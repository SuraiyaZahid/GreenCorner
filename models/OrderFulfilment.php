<?php
/**
 * GreenCorner — Order Fulfilment Service
 *
 * The transactional core of the Admin order management (P-17, P-18).
 *
 * Implements:
 *   FR-6.8  the Admin changes the fulfilment status; every transition is
 *           validated; `cancelled` is terminal; backward transitions are
 *           permitted; cancelling restores stock only where the product
 *           still exists and is not soft-deleted, and the other cases are
 *           logged for manual handling; cancelling does not restore the
 *           items to the customer's cart; a cancelled COD order's payment
 *           moves from `pending` to `failed`
 *   FR-6.9  the Admin confirms that the cash for a COD order has been
 *           collected
 *   BR-7    the fulfilment status vocabulary and its transitions
 *   BR-9    a failed card payment is immutable
 *   BR-13   stock is restored only for still-available products; the other
 *           cases are logged for manual handling; the cart is not restored
 *   BR-15   a cancelled order's COD payment moves `pending -> failed`,
 *           retaining its `order_id`
 *   ER-16   a rejected write is handled as a validation failure, never
 *           retried silently
 *
 * Everything happens inside **one transaction**, so the status change, the
 * stock restoration and the payment update succeed together or not at all.
 *
 * The transition rules live in `OrderStatus`, and the payment rules live in
 * `Payment`. This service joins them and owns the transaction.
 */

require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../helpers/logging.php';
require_once __DIR__ . '/Order.php';
require_once __DIR__ . '/OrderStatus.php';
require_once __DIR__ . '/Payment.php';

class OrderFulfilment
{
    /**
     * The outcome of a fulfilment change.
     *
     * `status` is one of:
     *   'success'   the change was committed
     *   'not_found' the order does not exist
     *   'refused'   the transition is not permitted, or the payment change
     *               was refused; nothing was written
     *
     * @return array{status: string, message: string, order: array<string, mixed>|null, restored: array<int, array<string, mixed>>, unrestored: array<int, array<string, mixed>>}
     */
    public static function transition(int $orderId, string $to): array
    {
        $result = array(
            'status'      => 'refused',
            'message'     => '',
            'order'       => null,
            'restored'    => array(),
            'unrestored'  => array(),
        );

        // The requested status must be one of the five permitted values.
        // This is checked before the transaction starts, so an unknown value
        // never reaches the database.
        if (!OrderStatus::isValid($to)) {
            $result['message'] = 'That is not a fulfilment status this store uses.';

            return $result;
        }

        db_begin_transaction();

        try {
            // FR-6.8: the order row is locked, so two Admin requests cannot
            // change the same order at the same time.
            $order = Order::findForUpdate($orderId);

            if ($order === null) {
                db_rollback();

                $result['status'] = 'not_found';
                $result['message'] = 'We could not find that order.';

                return $result;
            }

            $from = (string) $order['status'];

            // FR-6.8 / BR-7: every transition is validated.
            if (!OrderStatus::canTransition($from, $to)) {
                db_rollback();

                $result['status'] = 'refused';
                $result['message'] = OrderStatus::refusalMessage($from, $to);

                return $result;
            }

            Order::updateStatus($orderId, $to);

            if ($to === OrderStatus::CANCELLED) {
                $stock = self::restoreStock($orderId, $order);

                $result['restored'] = $stock['restored'];
                $result['unrestored'] = $stock['unrestored'];

                // BR-15: a cancelled order's COD payment moves from
                // `pending` to `failed`, retaining its `order_id`.
                self::failCodPayment($orderId);
            }

            db_commit();
        } catch (Throwable $e) {
            db_rollback();
            throw $e;
        }

        $result['status'] = 'success';
        $result['order'] = Order::find($orderId);

        return $result;
    }

    /**
     * Confirm that the cash for a Cash on Delivery order has been collected
     * (FR-6.9).
     *
     * @param int $orderId
     * @return array{status: string, message: string, order: array<string, mixed>|null}
     */
    public static function confirmCod(int $orderId): array
    {
        $result = array(
            'status'  => 'refused',
            'message' => '',
            'order'   => null,
        );

        db_begin_transaction();

        try {
            $order = Order::findForUpdate($orderId);

            if ($order === null) {
                db_rollback();

                $result['status'] = 'not_found';
                $result['message'] = 'We could not find that order.';

                return $result;
            }

            $payment = Payment::forOrder($orderId);

            if ($payment === null) {
                db_rollback();

                $result['message'] = 'This order has no payment to confirm.';

                return $result;
            }

            // FR-6.9: only a Cash on Delivery payment can be confirmed as
            // collected. A card payment is refused here, before anything is
            // written.
            if ((string) $payment['method'] !== Payment::METHOD_COD) {
                db_rollback();

                $result['message'] = 'Only a Cash on Delivery payment can be confirmed as collected.';

                return $result;
            }

            // FR-6.9 / BR-9: only a COD payment that is still pending can
            // be confirmed. A failed card payment is refused here, because
            // `Payment::updateStatus()` refuses it.
            $updated = Payment::confirmCodCollection((int) $payment['id']);

            if ($updated['status'] !== 'success') {
                db_rollback();

                $result['message'] = $updated['message'];

                return $result;
            }

            db_commit();
        } catch (Throwable $e) {
            db_rollback();
            throw $e;
        }

        $result['status'] = 'success';
        $result['order'] = Order::find($orderId);

        return $result;
    }

    /**
     * Restore the stock of the products on a cancelled order (BR-13).
     *
     * Stock is restored **only** where the product still exists and is not
     * soft-deleted. Where it cannot be restored, the case is logged for
     * manual handling.
     *
     * The customer's cart is **not** touched: cancelling an order does not
     * put the items back in the cart (BR-13, FR-6.8 acceptance criterion
     * 7).
     *
     * Each product is read and restored in a single pass, so the decision
     * to restore and the decision to log are always made from the same
     * locked row.
     *
     * @param int                  $orderId
     * @param array<string, mixed> $order The locked order row.
     * @return array{restored: array<int, array<string, mixed>>, unrestored: array<int, array<string, mixed>>}
     */
    private static function restoreStock(int $orderId, array $order): array
    {
        $restored = array();
        $unrestored = array();

        $items = Order::items($orderId);

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $quantity = (int) $item['quantity'];

            // The product row is locked, so two cancellations cannot both
            // restore the same unit.
            $product = db_select_one(
                'SELECT `id`, `name`, `stock`, `is_deleted`
                   FROM `products`
                  WHERE `id` = ?
                  FOR UPDATE',
                'i',
                array($productId)
            );

            // BR-13: a product that no longer exists, or that has been
            // soft-deleted, cannot be restored automatically.
            if ($product === null || (int) $product['is_deleted'] === 1) {
                $reason = $product === null ? 'product_missing' : 'product_soft_deleted';

                $unrestored[] = array(
                    'product_id'   => $productId,
                    'product_name' => (string) $item['product_name_at_purchase'],
                    'quantity'     => $quantity,
                    'reason'       => $reason,
                );

                // FR-6.8 acceptance criterion 6: the case is written to the
                // manual-handling log. The entry holds the timestamp, the
                // order id and number, the product id, the product name and
                // the reason. It holds no customer personal data, no card
                // data and no password material.
                gc_log(LOG_CHANNEL_ORDER_CANCELLATION, 'stock_not_restored', array(
                    'order_id'     => $orderId,
                    'order_number' => (string) $order['order_number'],
                    'product_id'   => $productId,
                    'product_name' => (string) $item['product_name_at_purchase'],
                    'quantity'     => $quantity,
                    'reason'       => $reason,
                ));

                continue;
            }

            db_write(
                'UPDATE `products` SET `stock` = `stock` + ? WHERE `id` = ?',
                'ii',
                array($quantity, $productId)
            );

            $restored[] = array(
                'product_id'   => $productId,
                'product_name' => (string) $product['name'],
                'quantity'     => $quantity,
            );
        }

        return array('restored' => $restored, 'unrestored' => $unrestored);
    }

    /**
     * Move a cancelled order's Cash on Delivery payment from `pending` to
     * `failed` (BR-15).
     *
     * The `order_id` is retained: the constraint `chk_payment_order_binding`
     * requires `cod/failed` to reference an order, so a cancelled COD order
     * keeps the link to its order for the audit trail.
     *
     * A card payment is left alone: it has already been paid, and there is
     * no gateway to refund it.
     *
     * @param int $orderId
     * @return void
     */
    private static function failCodPayment(int $orderId): void
    {
        $payment = Payment::forOrder($orderId);

        if ($payment === null) {
            return;
        }

        if ((string) $payment['method'] !== Payment::METHOD_COD) {
            // FR-6.8 / BR-15 only cover the COD case.
            return;
        }

        if ((string) $payment['status'] !== 'pending') {
            return;
        }

        Payment::updateStatus((int) $payment['id'], 'failed');
    }
}
