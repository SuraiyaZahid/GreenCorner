<?php
/**
 * GreenCorner — Payment Model
 *
 * Data access for the `payments` table, the simulated card processor, and
 * the validation of the card fields.
 *
 * Implements:
 *   FR-4.2  Card and Cash on Delivery are the two methods; Card is the
 *           default
 *   FR-4.3  the card fields are validated server-side; a validation failure
 *           creates no payment record at all
 *   FR-4.4  a successful card payment inserts method='card', status='success',
 *           paid_at set and order_id referencing the new order
 *   FR-4.5  a declined card attempt inserts method='card', status='failed',
 *           paid_at NULL and order_id NULL — a decline references no order
 *   FR-4.6  the processor produces both outcomes; nothing assumes that a
 *           successful validation means a successful payment
 *   FR-4.7  Cash on Delivery inserts method='cod', status='pending',
 *           paid_at NULL and order_id referencing the new order
 *   FR-4.9  no card instrument data is ever stored, logged or echoed
 *   FR-4.10 exactly one payment method applies to an order
 *   FR-4.16 the payment status is owned by this table alone
 *   BR-8    the five permitted (method, status, order_id) shapes, and no
 *           others
 *   BR-9    a failed card payment is immutable: it can never be turned into
 *           a success by any code path
 *
 * The card outcome is decided by the STRICT whitelist approved in decision
 * B:
 *
 *   4242 4242 4242 4242  -> success
 *   4000 0000 0000 0002  -> decline
 *   any other Luhn-valid  -> decline
 *
 * The success path is reachable only by the first number. There is no
 * gateway, no network call and no fallback that treats a validated card as
 * a successful payment.
 */

require_once dirname(__DIR__) . '/helpers/db.php';

class Payment
{
    /**
     * The card number that the simulated processor approves (decision B).
     */
    public const SUCCESS_CARD = '4242424242424242';

    /**
     * The card number that the simulated processor declines (decision B).
     */
    public const DECLINE_CARD = '4000000000000002';

    /**
     * The shortest accepted card number, in digits.
     */
    public const MIN_CARD_DIGITS = 13;

    /**
     * The longest accepted card number, in digits.
     */
    public const MAX_CARD_DIGITS = 19;

    /**
     * The number of digits in an accepted CVV.
     */
    public const CVV_LENGTH = 3;

    /**
     * The two permitted payment methods.
     */
    public const METHOD_CARD = 'card';
    public const METHOD_COD = 'cod';

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    /**
     * Strip the spaces and hyphens from a card number.
     *
     * @param string $number
     * @return string
     */
    public static function normaliseCardNumber(string $number): string
    {
        return preg_replace('/[\s\-]/', '', trim($number)) ?? '';
    }

    /**
     * Whether a string passes the Luhn checksum.
     *
     * @param string $digits A string of digits.
     * @return bool
     */
    public static function passesLuhn(string $digits): bool
    {
        if ($digits === '' || !ctype_digit($digits)) {
            return false;
        }

        $sum = 0;
        $double = false;

        // Walk the digits from the right, doubling every second digit.
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }

    /**
     * Validate the card fields.
     *
     * The rules, as approved:
     *   - the cardholder name is required
     *   - the card number is required, digits only after removing spaces and
     *     hyphens, between 13 and 19 digits long, and must pass the Luhn
     *     checksum
     *   - the expiry is required, in MM/YY or MM/YYYY form, and must be in
     *     the future
     *   - the CVV is required and exactly three digits
     *
     * The card number itself is never returned by this method, so it cannot
     * leak into a log line or an error message (FR-4.9, SEC-15).
     *
     * @param string $cardholderName
     * @param string $cardNumber
     * @param string $expiry
     * @param string $cvv
     * @return array<string, string> The field errors; empty when valid.
     */
    public static function validateCard(
        string $cardholderName,
        string $cardNumber,
        string $expiry,
        string $cvv
    ): array {
        $errors = array();

        if (trim($cardholderName) === '') {
            $errors['cardholder_name'] = 'Please enter the name on the card.';
        }

        $digits = self::normaliseCardNumber($cardNumber);

        if ($digits === '') {
            $errors['card_number'] = 'Please enter your card number.';
        } elseif (!ctype_digit($digits)) {
            $errors['card_number'] = 'Please enter your card number as digits only.';
        } elseif (strlen($digits) < self::MIN_CARD_DIGITS || strlen($digits) > self::MAX_CARD_DIGITS) {
            $errors['card_number'] = 'Please enter a card number of between '
                . self::MIN_CARD_DIGITS . ' and ' . self::MAX_CARD_DIGITS . ' digits.';
        } elseif (!self::passesLuhn($digits)) {
            $errors['card_number'] = 'That card number is not valid. Please check it and try again.';
        }

        if (trim($expiry) === '') {
            $errors['expiry'] = 'Please enter the expiry date.';
        } elseif (!self::isValidExpiry($expiry)) {
            $errors['expiry'] = 'Please enter the expiry date as MM/YY, and make sure it has not passed.';
        }

        $cvvDigits = trim($cvv);

        if ($cvvDigits === '') {
            $errors['cvv'] = 'Please enter the security code.';
        } elseif (!ctype_digit($cvvDigits) || strlen($cvvDigits) !== self::CVV_LENGTH) {
            $errors['cvv'] = 'Please enter the ' . self::CVV_LENGTH . '-digit security code.';
        }

        return $errors;
    }

    /**
     * Whether an expiry date is well formed and still in the future.
     *
     * @param string $expiry 'MM/YY' or 'MM/YYYY'.
     * @return bool
     */
    public static function isValidExpiry(string $expiry): bool
    {
        $expiry = trim($expiry);

        if (preg_match('#^(0[1-9]|1[0-2])/(\d{2}|\d{4})$#', $expiry, $matches) !== 1) {
            return false;
        }

        $month = (int) $matches[1];
        $year = (int) $matches[2];

        if (strlen($matches[2]) === 2) {
            // A two-digit year is read as 20xx.
            $year += 2000;
        }

        // The card is valid through the last instant of its expiry month, so
        // the comparison is against the first instant of the next month.
        $firstOfThisMonth = (int) date('Y') * 12 + (int) date('n');
        $expiryMonth = $year * 12 + $month;

        return $expiryMonth >= $firstOfThisMonth;
    }

    // -----------------------------------------------------------------------
    // The simulated processor
    // -----------------------------------------------------------------------

    /**
     * Run the simulated card processor.
     *
     * This is a pure, in-process simulation. There is no gateway, no HTTP
     * call and no external service (FR-4.6, SRS §9.3). The card number is
     * read and immediately discarded; it is never stored or logged
     * (FR-4.9).
     *
     * The decision is a whitelist, not a blacklist: only the approved
     * success number succeeds, and every other well-formed card declines
     * (decision B).
     *
     * @param string $cardNumber The submitted card number.
     * @return string 'success' or 'declined'.
     */
    public static function simulateCard(string $cardNumber): string
    {
        $digits = self::normaliseCardNumber($cardNumber);

        if ($digits === self::SUCCESS_CARD) {
            return 'success';
        }

        // Every other card declines: the specified decline number and any
        // other Luhn-valid number alike.
        return 'declined';
    }

    /**
     * The message shown when a card payment is declined.
     */
    public const DECLINE_MESSAGE = 'Your card was declined. Please try a different card, or choose Cash on Delivery.';

    // -----------------------------------------------------------------------
    // Writing payment rows
    // -----------------------------------------------------------------------

    /**
     * Record a successful card payment.
     *
     * The row references the new order, as the database constraint
     * `chk_payment_order_binding` requires (FR-4.4).
     *
     * @param int $orderId
     * @return int The new payment id.
     */
    public static function recordCardSuccess(int $orderId): int
    {
        return db_insert_id(
            "INSERT INTO `payments` (`order_id`, `method`, `status`, `paid_at`)
             VALUES (?, 'card', 'success', ?)",
            'is',
            array($orderId, Order::now())
        );
    }

    /**
     * Record a declined card attempt.
     *
     * A declined attempt references no order: `order_id` is NULL, as the
     * database constraint requires (FR-4.5, BR-8).
     *
     * @return int The new payment id.
     */
    public static function recordCardFailure(): int
    {
        return db_insert_id(
            "INSERT INTO `payments` (`order_id`, `method`, `status`, `paid_at`)
             VALUES (NULL, 'card', 'failed', NULL)"
        );
    }

    /**
     * Record a Cash on Delivery order.
     *
     * The payment is `pending`: the cash has not been collected yet, and
     * the Admin confirms it later (FR-4.7).
     *
     * @param int $orderId
     * @return int The new payment id.
     */
    public static function recordCodPending(int $orderId): int
    {
        return db_insert_id(
            "INSERT INTO `payments` (`order_id`, `method`, `status`, `paid_at`)
             VALUES (?, 'cod', 'pending', NULL)",
            'i',
            array($orderId)
        );
    }

    /**
     * The payment row of an order, if it has one.
     *
     * @param int $orderId
     * @return array<string, mixed>|null
     */
    public static function forOrder(int $orderId): ?array
    {
        return db_select_one(
            'SELECT `id`, `order_id`, `method`, `status`, `paid_at`
               FROM `payments`
              WHERE `order_id` = ?
              ORDER BY `id` DESC
              LIMIT 1',
            'i',
            array($orderId)
        );
    }

    // -----------------------------------------------------------------------
    // Updating payment rows (FR-6.8, FR-6.9)
    // -----------------------------------------------------------------------

    /**
     * Move a payment to a new status.
     *
     * This method is the **only** place a payment status is changed, and it
     * enforces the rules the database deliberately leaves to the
     * application (SRS §10.4):
     *
     *   - **BR-9: a failed card payment is immutable.** A payment whose
     *     method is `card` and whose status is `failed` can never become a
     *     success. The database constraint `chk_payment_order_binding`
     *     actually *permits* the shape `card/failed -> card/success`, so
     *     this method is the only thing standing between the two states.
     *   - **FR-6.9: only a COD payment that is still pending can be
     *     confirmed as collected.** A card payment is never settled by this
     *     route at all, whatever its status.
     *   - The permitted (method, status) combinations are the five shapes
     *     the constraint allows (BR-8), so the write can never violate it.
     *
     * @param int    $paymentId
     * @param string $status      The new status: 'pending', 'success' or
     *                             'failed'.
     * @return array{status: string, message: string} 'success' when the
     *         payment was updated, otherwise a 'refused' status with the
     *         reason.
     */
    public static function updateStatus(int $paymentId, string $status): array
    {
        $result = array(
            'status'  => 'refused',
            'message' => '',
        );

        if (!in_array($status, array('pending', 'success', 'failed'), true)) {
            $result['message'] = 'That is not a payment status this store uses.';

            return $result;
        }

        $payment = db_select_one(
            'SELECT `id`, `order_id`, `method`, `status`, `paid_at`
               FROM `payments`
              WHERE `id` = ?
              FOR UPDATE',
            'i',
            array($paymentId)
        );

        if ($payment === null) {
            $result['message'] = 'We could not find that payment.';

            return $result;
        }

        $method = (string) $payment['method'];
        $current = (string) $payment['status'];

        // BR-9: a failed card payment is immutable.
        if ($method === self::METHOD_CARD && $current === 'failed') {
            $result['message'] = 'A declined card payment cannot be changed. The customer must pay another way.';

            return $result;
        }

        // FR-6.9: only a pending COD payment can be confirmed as collected.
        if ($status === 'success' && $method !== self::METHOD_COD) {
            $result['message'] = 'Only a Cash on Delivery payment can be confirmed as collected.';

            return $result;
        }

        // A COD payment that is already settled or failed is not moved
        // again. FR-6.9: only a **pending** COD payment can be confirmed as
        // collected, so a payment that is already `success` is refused
        // rather than quietly re-settled.
        if ($method === self::METHOD_COD && $current !== 'pending') {
            $result['message'] = 'This Cash on Delivery payment is already ' . $current . '.';

            return $result;
        }

        // The write itself. `paid_at` is set when the payment becomes a
        // success, and cleared when it stops being one.
        $paidAt = $status === 'success' ? Order::now() : null;

        db_write(
            'UPDATE `payments` SET `status` = ?, `paid_at` = ? WHERE `id` = ?',
            'ssi',
            array($status, $paidAt, $paymentId)
        );

        $result['status'] = 'success';

        return $result;
    }

    /**
     * Confirm that the cash for a Cash on Delivery order has been
     * collected (FR-6.9).
     *
     * The payment moves from `pending` to `success`, and `paid_at` is set
     * to the current time.
     *
     * A **card payment can never be confirmed this way**, because the method
     * is not `cod` (BR-9). The call is refused before anything is written.
     * A COD payment that is already `success` or already `failed` is
     * refused too, so the cash is never collected twice.
     *
     * @param int $paymentId
     * @return array{status: string, message: string} 'success' when the
     *         payment was confirmed, otherwise a 'refused' status with the
     *         reason.
     */
    public static function confirmCodCollection(int $paymentId): array
    {
        return self::updateStatus($paymentId, 'success');
    }

    /**
     * The number of payment rows, for the tests and the health checks.
     *
     * @return int
     */
    public static function countAll(): int
    {
        $row = db_select_one('SELECT COUNT(*) AS `total` FROM `payments`');

        return (int) ($row['total'] ?? 0);
    }
}
