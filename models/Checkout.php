<?php
/**
 * GreenCorner — Checkout Service
 *
 * The transactional core of the checkout. It performs the re-validation,
 * the order creation, the stock reduction, the payment record and the cart
 * clearing inside ONE database transaction, so that they succeed together
 * or not at all.
 *
 * Implements:
 *   FR-4.11 the cart is re-read and re-validated inside the checkout
 *           transaction, with the product rows locked using
 *           SELECT ... FOR UPDATE so that concurrent checkouts cannot
 *           oversell the last unit
 *   FR-4.12 the current prices are re-read; a changed price produces an
 *           updated total for the customer to confirm
 *   FR-4.15 the whole checkout is one transaction; a failure rolls it back
 *           completely, leaving no order, no order items, no payment row and
 *           unchanged stock
 *   ER-20    a failed checkout leaves nothing behind
 *   ER-23    an out-of-stock or soft-deleted item aborts the checkout and
 *           names the offending item
 *   BR-6     the order line records the price at purchase as an immutable
 *           snapshot
 */

require_once dirname(__DIR__) . '/helpers/db.php';
require_once __DIR__ . '/Cart.php';
require_once __DIR__ . '/Order.php';
require_once __DIR__ . '/Payment.php';
require_once __DIR__ . '/WalletPayment.php';

class Checkout
{
    /**
     * The outcome of a checkout attempt.
     *
     * `status` is one of:
     *   'success'   the order was created and committed
     *   'price_changed' the prices moved; the customer must confirm again
     *   'unavailable'   an item is out of stock or no longer available
     *   'declined'      the card was declined
     *   'invalid'       the submitted payment details are not usable
     *   'empty'         the cart is empty
     */
    /**
     * @return array{status: string, order: array<string,mixed>|null, items: array<int,array<string,mixed>>, total: float, previous_total: float, message: string, card_errors?: array<string,string>}
     */
    public static function place(
        int $userId,
        array $address,
        string $method,
        array $card = array()
    ): array {
        $result = array(
            'status'         => 'unavailable',
            'order'          => null,
            'items'          => array(),
            'total'          => 0.0,
            'previous_total' => 0.0,
            'message'        => '',
        );

        $method = self::normaliseMethod($method);

        // The card fields are validated BEFORE the transaction starts, so a
        // validation failure cannot create a payment row (FR-4.3). The card
        // data is validated and then discarded: it is never stored.
        if ($method === Payment::METHOD_CARD) {
            $cardErrors = Payment::validateCard(
                (string) ($card['cardholder_name'] ?? ''),
                (string) ($card['card_number'] ?? ''),
                (string) ($card['expiry'] ?? ''),
                (string) ($card['cvv'] ?? '')
            );

            if ($cardErrors !== array()) {
                $result['status'] = 'invalid';
                $result['message'] = 'Please correct the card details and try again.';
                $result['card_errors'] = $cardErrors;

                return $result;
            }
        }

        // FR-4.11: the wallet fields are validated before the transaction as
        // well, for the same reason: a validation failure must create no
        // payment record at all.
        if (WalletPayment::isWalletMethod($method)) {
            $walletErrors = WalletPayment::validate(
                (string) ($card['sender_number'] ?? ''),
                (string) ($card['transaction_id'] ?? '')
            );

            if ($walletErrors !== array()) {
                $result['status'] = 'invalid';
                $result['message'] = 'Please correct the payment details and try again.';
                $result['card_errors'] = $walletErrors;

                return $result;
            }

            // FR-4.12: a transaction id can never be submitted twice. The
            // database enforces this with a UNIQUE key as well, so that two
            // submissions racing each other cannot both succeed; this check
            // is what produces the friendly message.
            if (WalletPayment::transactionIdExists((string) ($card['transaction_id'] ?? ''))) {
                $result['status'] = 'invalid';
                $result['message'] = 'That transaction id has already been submitted. Please check it, or contact the store if this is a mistake.';
                $result['card_errors'] = array(
                    'transaction_id' => 'This transaction id has already been submitted.',
                );

                return $result;
            }
        }

        $cartRows = Cart::items($userId);

        if ($cartRows === array()) {
            $result['status'] = 'empty';
            $result['message'] = 'Your cart is empty.';

            return $result;
        }

        // The total the customer was shown on the payment page.
        $shownTotal = isset($card['shown_total'])
            ? (float) $card['shown_total']
            : self::totalOf($cartRows);

        db_begin_transaction();

        try {
            // FR-4.11: re-read every cart item with its product row locked.
            // The lock is held until the transaction ends, which is what
            // prevents two concurrent checkouts from both taking the last
            // unit.
            $items = array();
            $total = 0.0;
            $priceChanged = false;
            $previousTotal = 0.0;

            foreach ($cartRows as $row) {
                $productId = (int) $row['product_id'];

                $locked = db_select_one(
                    'SELECT `id`, `name`, `price`, `stock`, `is_deleted`
                       FROM `products`
                      WHERE `id` = ?
                      FOR UPDATE',
                    'i',
                    array($productId)
                );

                if ($locked === null) {
                    throw new CheckoutUnavailable(
                        'A product in your cart no longer exists.',
                        (string) $row['product_name']
                    );
                }

                // FR-3.8 / ER-23: a soft-deleted product blocks the checkout
                // and is named to the customer.
                if ((int) $locked['is_deleted'] === 1) {
                    throw new CheckoutUnavailable(
                        'That product is no longer available.',
                        (string) $locked['name']
                    );
                }

                $quantity = (int) $row['quantity'];
                $stock = (int) $locked['stock'];

                if ($stock < 1) {
                    throw new CheckoutUnavailable(
                        'That product is out of stock.',
                        (string) $locked['name']
                    );
                }

                // The quantity is clamped to the available stock, as the
                // cart page does (FR-3.8).
                if ($quantity > $stock) {
                    $quantity = $stock;
                }

                $currentPrice = (float) $locked['price'];
                $lineTotal = round($currentPrice * $quantity, 2);

                // FR-4.12: a price that has moved since the customer was
                // shown the total.
                if (abs($currentPrice - (float) $row['price_at_add']) >= 0.005) {
                    $priceChanged = true;
                }

                $previousTotal += round((float) $row['price_at_add'] * (int) $row['quantity'], 2);
                $total += $lineTotal;

                $items[] = array(
                    'product_id'   => $productId,
                    'product_name' => (string) $locked['name'],
                    'unit_price'   => $currentPrice,
                    'quantity'     => $quantity,
                    'line_total'   => $lineTotal,
                );
            }

            $total = round($total, 2);

            // FR-4.12: the customer must confirm the updated total before
            // the order is committed. The order is NOT created yet.
            if ($priceChanged && abs($total - $shownTotal) >= 0.005) {
                db_rollback();

                $result['status'] = 'price_changed';
                $result['items'] = $items;
                $result['total'] = $total;
                $result['previous_total'] = round($shownTotal, 2);
                $result['message'] = 'The price of one or more items has changed. Please review the updated total and confirm to continue.';

                return $result;
            }

            // FR-4.14 / decision C and D: the order number is generated
            // inside this transaction, with the daily counter locked.
            $orderNumber = Order::nextNumber();

            $orderId = Order::create($orderNumber, $userId, $total, $address);

            foreach ($items as $item) {
                Order::addItem(
                    $orderId,
                    (int) $item['product_id'],
                    (string) $item['product_name'],
                    (float) $item['unit_price'],
                    (int) $item['quantity']
                );

                // FR-4.4 / FR-4.15: the stock is reduced in the same
                // transaction. The row is already locked, so the reduction
                // cannot take the stock below zero.
                db_write(
                    'UPDATE `products` SET `stock` = `stock` - ? WHERE `id` = ?',
                    'ii',
                    array((int) $item['quantity'], (int) $item['product_id'])
                );
            }

            if ($method === Payment::METHOD_COD) {
                // FR-4.7: the COD payment is pending; the cash has not been
                // collected yet.
                Payment::recordCodPending($orderId);
            } elseif (WalletPayment::isWalletMethod($method)) {
                // FR-4.11 (wallet): the payment is recorded as
                // `pending_verification`. The customer has submitted the
                // sender number and the transaction id, but the money has
                // NOT been confirmed: only an Admin who has checked the
                // wallet statement can approve it, and only then does the
                // payment become a success.
                //
                // The reference code the customer was shown is stored with
                // the payment, so the store can match it against the wallet
                // statement.
                WalletPayment::recordPending(
                    $orderId,
                    $method,
                    (string) ($card['sender_number'] ?? ''),
                    (string) ($card['transaction_id'] ?? ''),
                    number_format($total, 2, '.', ''),
                    (string) ($card['reference_code'] ?? '')
                );
            } else {
                // FR-4.5 / FR-4.6: the simulated processor decides the
                // outcome. A decline is recorded as a failed payment with
                // NO order reference, so the whole transaction is rolled
                // back and the customer can try again.
                $outcome = Payment::simulateCard((string) ($card['card_number'] ?? ''));

                if ($outcome !== 'success') {
                    throw new CheckoutDeclined(Payment::DECLINE_MESSAGE);
                }

                // FR-4.4: the successful payment references the new order.
                Payment::recordCardSuccess($orderId);
            }

            // FR-4.15: the cart is cleared only on the successful path.
            Cart::clear($userId);

            db_commit();
        } catch (CheckoutUnavailable $e) {
            // ER-20 / ER-23: the transaction is rolled back completely, so
            // no order, no order items, no payment row and unchanged stock
            // survive the failure.
            db_rollback();

            $result['status'] = 'unavailable';
            $result['message'] = $e->getMessage();
            $result['product_name'] = $e->productName;

            return $result;
        } catch (CheckoutDeclined $e) {
            // FR-4.5: a decline is recorded, but it records NO order. The
            // transaction is rolled back first, so the failed payment row is
            // written afterwards, outside the transaction, with a NULL
            // order_id.
            db_rollback();

            Payment::recordCardFailure();

            $result['status'] = 'declined';
            $result['message'] = $e->getMessage();

            return $result;
        } catch (Throwable $e) {
            db_rollback();
            throw $e;
        }

        $result['status'] = 'success';
        $result['items'] = $items;
        $result['total'] = $total;
        $result['order'] = Order::find($orderId);

        return $result;
    }

    /**
     * Reduce a requested payment method to one the store accepts.
     *
     * The method arrives from the form, so it is untrusted. A wallet method
     * is only honoured when that wallet is configured with a merchant
     * number: a customer must never be able to select a method the store
     * cannot receive money through.
     *
     * Anything unrecognised falls back to Card, as it did before the wallet
     * methods were added.
     *
     * @param string $method
     * @return string
     */
    public static function normaliseMethod(string $method): string
    {
        if ($method === Payment::METHOD_COD) {
            return Payment::METHOD_COD;
        }

        if (WalletPayment::isWalletMethod($method) && WalletPayment::isAvailable($method)) {
            return $method;
        }

        return Payment::METHOD_CARD;
    }

    /**
     * The total of a set of cart rows, computed from the CURRENT prices.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return float
     */
    public static function totalOf(array $rows): float
    {
        $total = 0.0;

        foreach ($rows as $row) {
            $total += round((float) $row['current_price'] * (int) $row['quantity'], 2);
        }

        return round($total, 2);
    }

    /**
     * The total a set of cart rows would have had at the prices they were
     * added at. Used to show the customer the difference.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return float
     */
    public static function previousTotalOf(array $rows): float
    {
        $total = 0.0;

        foreach ($rows as $row) {
            $total += round((float) $row['price_at_add'] * (int) $row['quantity'], 2);
        }

        return round($total, 2);
    }
}

/**
 * Thrown when a cart item is unavailable at checkout.
 *
 * The offending product name is carried separately so the customer can be
 * told exactly which item is the problem (FR-4.11 acceptance criterion 2).
 */
class CheckoutUnavailable extends RuntimeException
{
    /** @var string */
    public $productName;

    public function __construct(string $message, string $productName = '')
    {
        parent::__construct($message);
        $this->productName = $productName;
    }
}

/**
 * Thrown when the simulated card processor declines a payment.
 */
class CheckoutDeclined extends RuntimeException
{
}
