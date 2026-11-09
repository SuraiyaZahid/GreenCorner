<?php
/**
 * GreenCorner — Refund Model
 *
 * The refund record and the policy that governs it.
 *
 * THE CENTRAL RULE
 *
 * A refund is **never** marked successful without a gateway confirmation.
 *
 * This is enforced in three places at once, so that no single mistake can
 * mark a customer's money as returned when it has not been:
 *
 *   1. In the data model: a refund starts as `refund_pending` and only
 *      `confirm()` can move it to `refunded`, and `confirm()` demands a
 *      gateway reference — the identifier the wallet operator gave for the
 *      returned money.
 *   2. In the gateway seam: `sendToGateway()` makes NO network call yet
 *      and returns `gateway_unavailable`, so there is no path that could
 *      produce a confirmation out of thin air.
 *   3. In the Admin page: the refund is shown with its real status, and a
 *      pending refund is visibly pending — the customer is told the money
 *      is on its way, not that it has arrived.
 *
 * WHY A REFUND CAN BE PENDING AT ALL
 *
 * The store verifies a bKash or Nagad payment by hand. That means the store
 * holds the money and sends it back by hand too, which may mean walking to
 * the wallet outlet, or handing over cash. Either way the refund is
 * `refund_pending` until somebody confirms the money was actually
 * returned. When the official gateway API is integrated, the same row
 * becomes `refunded` automatically once the operator confirms — the schema
 * and the states do not change.
 *
 * THE PAID BUT UNFULFILLABLE CASE
 *
 * When a payment has been verified but the product can no longer be
 * supplied, the order is not simply cancelled: the money WAS received, so
 * cancelling it would hide that fact. Instead the order becomes
 * `paid_unfulfillable` and a refund is opened. The customer is told that
 * the payment was received and that the money is being returned.
 *
 * Implements:
 *   FR-4.14  a verified payment for a product that is no longer available
 *            results in a full refund and an order marked
 *            `paid_unfulfillable`
 *   FR-4.15  a refund is only `refunded` once the gateway confirms it; a
 *            failed refund is flagged for Admin review
 *   FR-6.12 the Admin sees the refund status and the refund history
 *   BR-14    the refund amount is the full amount paid
 *   BR-16    inventory is deducted exactly once, and a refund does not
 *            deduct it again
 */

require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../helpers/logging.php';
require_once __DIR__ . '/Order.php';
require_once __DIR__ . '/OrderStatus.php';

class Refund
{
    /**
     * The refund has been opened but the money has not been returned yet.
     */
    public const STATUS_PENDING = 'refund_pending';

    /**
     * The gateway (or the store, by hand) confirmed that the money was
     * returned. Only a confirmation can set this.
     */
    public const STATUS_REFUNDED = 'refunded';

    /**
     * The refund was attempted and failed. This is flagged for Admin review.
     */
    public const STATUS_FAILED = 'refund_failed';

    /**
     * The three refund states.
     *
     * @var array<int, string>
     */
    public const ALL = array(self::STATUS_PENDING, self::STATUS_REFUNDED, self::STATUS_FAILED);

    /**
     * The order status of an order whose payment was received but which
     * cannot be supplied.
     */
    public const ORDER_PAID_UNFULFILLABLE = 'paid_unfulfillable';

    /**
     * The reason recorded on a refund opened for an unfulfillable order.
     */
    public const REASON_UNFULFILLABLE = 'The product was no longer available, so the order was refunded in full.';

    /**
     * A human-readable label for a refund status.
     *
     * @param string $status
     * @return string
     */
    public static function label(string $status): string
    {
        switch ($status) {
            case self::STATUS_PENDING:
                return 'Refund pending';
            case self::STATUS_REFUNDED:
                return 'Refunded';
            case self::STATUS_FAILED:
                return 'Refund failed';
            default:
                return 'Unknown';
        }
    }

    // -----------------------------------------------------------------------
    // Reading rows
    // -----------------------------------------------------------------------

    /**
     * The refunds of a payment, newest first.
     *
     * @param int $paymentId
     * @return array<int, array<string, mixed>>
     */
    public static function forPayment(int $paymentId): array
    {
        return db_select(
            'SELECT `id`, `payment_id`, `amount`, `status`, `reason`,
                    `gateway_reference`, `failure_detail`, `confirmed_at`,
                    `created_at`, `updated_at`
               FROM `refunds`
              WHERE `payment_id` = ?
              ORDER BY `id` DESC',
            'i',
            array($paymentId)
        );
    }

    /**
     * The most recent refund of a payment, if it has one.
     *
     * @param int $paymentId
     * @return array<string, mixed>|null
     */
    public static function latestForPayment(int $paymentId): ?array
    {
        $refunds = self::forPayment($paymentId);

        return $refunds[0] ?? null;
    }

    /**
     * The refunds that failed and need an Admin to look at them.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function failed(): array
    {
        return db_select(
            'SELECT r.`id`, r.`payment_id`, r.`amount`, r.`status`, r.`reason`,
                    r.`failure_detail`, r.`created_at`,
                    o.`id` AS `order_id`, o.`order_number`
               FROM `refunds` r
               JOIN `payments` p ON p.`id` = r.`payment_id`
               JOIN `orders` o ON o.`id` = p.`order_id`
              WHERE r.`status` = ?
              ORDER BY r.`created_at` ASC',
            's',
            array(self::STATUS_FAILED)
        );
    }

    /**
     * The number of refund rows, for the tests and the health checks.
     *
     * @return int
     */
    public static function countAll(): int
    {
        $row = db_select_one('SELECT COUNT(*) AS `total` FROM `refunds`');

        return (int) ($row['total'] ?? 0);
    }

    // -----------------------------------------------------------------------
    // Writing rows
    // -----------------------------------------------------------------------

    /**
     * Open a refund for a payment.
     *
     * The refund is created as `refund_pending`. It is never created as
     * `refunded`, because the money has not been returned yet.
     *
     * @param int    $paymentId The payment being refunded.
     * @param string $amount    The amount to refund.
     * @param string $reason    Why the money is being returned.
     * @return int The new refund id.
     */
    public static function open(int $paymentId, string $amount, string $reason): int
    {
        return db_insert_id(
            'INSERT INTO `refunds` (`payment_id`, `amount`, `status`, `reason`)
             VALUES (?, ?, ?, ?)',
            'idss',
            array($paymentId, $amount, self::STATUS_PENDING, $reason)
        );
    }

    /**
     * Confirm that the money was returned.
     *
     * This is the ONLY method that can set the status to `refunded`, and
     * it demands a gateway reference: the identifier the wallet operator
     * gave for the returned money. Without one there is no evidence that
     * the customer got their money back, so the refund stays pending.
     *
     * @param int    $refundId
     * @param string $gatewayReference The operator's reference for the refund.
     * @return array{status: string, message: string}
     */
    public static function confirm(int $refundId, string $gatewayReference): array
    {
        $result = array(
            'status'  => 'refused',
            'message' => '',
        );

        $gatewayReference = trim($gatewayReference);

        if ($gatewayReference === '') {
            $result['message'] = 'A refund can only be confirmed with the reference the payment provider gave for it.';

            return $result;
        }

        $refund = db_select_one(
            'SELECT `id`, `status` FROM `refunds` WHERE `id` = ?',
            'i',
            array($refundId)
        );

        if ($refund === null) {
            $result['message'] = 'We could not find that refund.';

            return $result;
        }

        $status = (string) $refund['status'];

        if ($status === self::STATUS_REFUNDED) {
            $result['message'] = 'This refund has already been confirmed.';

            return $result;
        }

        db_write(
            'UPDATE `refunds`
                SET `status` = ?, `gateway_reference` = ?, `confirmed_at` = ?, `failure_detail` = NULL
              WHERE `id` = ?',
            'sssi',
            array(self::STATUS_REFUNDED, $gatewayReference, Order::now(), $refundId)
        );

        $result['status'] = 'success';

        return $result;
    }

    /**
     * Record that a refund attempt failed.
     *
     * A failed refund is flagged for Admin review: it is listed by
     * `failed()` and shown on the Admin order page, so the store can chase
     * the money manually.
     *
     * @param int    $refundId
     * @param string $detail What went wrong.
     * @return array{status: string, message: string}
     */
    public static function fail(int $refundId, string $detail): array
    {
        $result = array(
            'status'  => 'refused',
            'message' => '',
        );

        $detail = trim($detail);

        if ($detail === '') {
            $result['message'] = 'Please say what went wrong with the refund.';

            return $result;
        }

        $refund = db_select_one(
            'SELECT `id`, `status` FROM `refunds` WHERE `id` = ?',
            'i',
            array($refundId)
        );

        if ($refund === null) {
            $result['message'] = 'We could not find that refund.';

            return $result;
        }

        if ((string) $refund['status'] === self::STATUS_REFUNDED) {
            $result['message'] = 'This refund has already been confirmed and cannot be marked as failed.';

            return $result;
        }

        db_write(
            'UPDATE `refunds` SET `status` = ?, `failure_detail` = ? WHERE `id` = ?',
            'ssi',
            array(self::STATUS_FAILED, $detail, $refundId)
        );

        gc_log(LOG_CHANNEL_ADMIN_ACTIONS, 'refund_failed', array(
            'refund_id'  => $refundId,
            'detail'     => $detail,
        ));

        $result['status'] = 'success';

        return $result;
    }

    // -----------------------------------------------------------------------
    // The paid-but-unfulfillable policy
    // -----------------------------------------------------------------------

    /**
     * Mark an order as paid but unfulfillable and open a full refund.
     *
     * This is called when a wallet payment has been verified but the
     * product can no longer be supplied. The order is not cancelled: the
     * money WAS received, and the order's status is what tells the store
     * and the customer that.
     *
     * The refund is opened for the **full amount paid** (BR-14), and it
     * starts as `refund_pending`.
     *
     * The order status is set directly here rather than through
     * `OrderStatus`, because `paid_unfulfillable` is not a fulfilment
     * transition: it is the outcome of a payment event, and it is
     * terminal in the same way that `cancelled` is.
     *
     * @param int $orderId
     * @return array{status: string, message: string, refund_id: int, order: array<string, mixed>|null}
     */
    public static function markPaidUnfulfillable(int $orderId): array
    {
        $result = array(
            'status'   => 'refused',
            'message'  => '',
            'refund_id' => 0,
            'order'    => null,
        );

        db_begin_transaction();

        try {
            $order = Order::findForUpdate($orderId);

            if ($order === null) {
                db_rollback();
                $result['message'] = 'We could not find that order.';

                return $result;
            }

            $currentStatus = (string) $order['status'];

            if ($currentStatus === self::ORDER_PAID_UNFULFILLABLE) {
                db_rollback();
                $result['message'] = 'This order has already been marked as paid but unfulfillable.';

                return $result;
            }

            $payment = db_select_one(
                'SELECT `id`, `method`, `status`, `order_id`
                   FROM `payments`
                  WHERE `order_id` = ?
                  ORDER BY `id` DESC
                  LIMIT 1
                  FOR UPDATE',
                'i',
                array($orderId)
            );

            if ($payment === null) {
                db_rollback();
                $result['message'] = 'This order has no payment to refund.';

                return $result;
            }

            // Only a payment that has actually been received can be
            // refunded. A pending or failed payment has no money in it.
            if ((string) $payment['status'] !== 'success') {
                db_rollback();
                $result['message'] = 'Only a verified payment can be refunded. This one is '
                    . (string) $payment['status'] . '.';

                return $result;
            }

            // The refund covers the full amount paid (BR-14).
            $amount = number_format((float) $order['total_amount'], 2, '.', '');

            $refundId = self::open(
                (int) $payment['id'],
                $amount,
                self::REASON_UNFULFILLABLE
            );

            db_write(
                'UPDATE `orders` SET `status` = ? WHERE `id` = ?',
                'si',
                array(self::ORDER_PAID_UNFULFILLABLE, $orderId)
            );

            db_commit();

            $result['status'] = 'success';
            $result['refund_id'] = $refundId;
            $result['order'] = Order::find($orderId);

            return $result;
        } catch (Throwable $exception) {
            db_rollback();

            throw $exception;
        }
    }

    // -----------------------------------------------------------------------
    // Future gateway integration (see the class docblock)
    // -----------------------------------------------------------------------

    /**
     * The seam where a gateway refund call would go.
     *
     * There is no gateway integration yet, so this method makes NO network
     * call. It returns `gateway_unavailable` and the refund stays
     * `refund_pending` — which is the honest outcome: the money has not
     * been sent back yet.
     *
     * When the official bKash (https://developer.bka.sh/) or Nagad merchant
     * API is integrated, this method makes the refund call and, when the
     * operator confirms, calls `confirm()` with the reference it returns.
     *
     * @param int $refundId
     * @return array{status: string, message: string}
     */
    public static function sendToGateway(int $refundId): array
    {
        return array(
            'status'  => 'gateway_unavailable',
            'message' => 'The mobile wallet gateway is not connected. The refund is waiting to be sent, and must be confirmed by hand.',
        );
    }
}
