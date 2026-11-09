<?php
/**
 * GreenCorner — Mobile Wallet Payment Model
 *
 * Data access and rules for the manual bKash and Nagad merchant payments.
 *
 * WHAT THIS IS, AND WHAT IT IS NOT
 *
 * GreenCorner accepts a bKash or a Nagad payment in a MANUAL way: the
 * customer sends the money to the store's merchant number using their own
 * wallet app, then submits the sender number and the transaction id
 * (TrxID) on the checkout page. An Admin then checks the store's wallet
 * statement and either approves or rejects the submission.
 *
 * This is NOT a gateway integration. A submitted TrxID is a claim, not
 * proof: nothing here contacts bKash or Nagad, and there is no code path
 * that can reach `success` without a human Admin decision.
 *
 * The distinction is enforced in the data model, not merely in the prose:
 *
 *   - A submission creates a `payments` row with status
 *     `pending_verification` and a `payment_ref` row with status
 *     `pending_verification`.
 *   - Only `verify()` — which records the approving Admin — can move a
 *     wallet payment to `success`.
 *   - `reject()` moves it to `failed` and records the reason.
 *   - The status of a `payment_ref` row is the authority on whether the
 *     payment has been verified; the `payments` row follows it.
 *
 * FUTURE DIRECT GATEWAY INTEGRATION
 *
 * The shape of this class leaves room for the official merchant APIs
 * (https://developer.bka.sh/ for bKash, and the Nagad merchant guide for
 * Nagad) to be added without changing the checkout, the Admin pages or the
 * schema:
 *
 *   - `initiate()` is the seam where a gateway "create payment" call would
 *     go. It currently performs no network call at all and returns a
 *     `gateway_unavailable` result, so nothing can mistake it for a
 *     confirmed transaction.
 *   - `queryTransaction()` is the seam where a gateway "query transaction"
 *     call would go, for checking a TrxID automatically. It currently
 *     performs no network call and returns `gateway_unavailable`.
 *   - `refund()` in models/Refund.php is the seam where a gateway refund
 *     call would go. It currently performs no network call.
 *
 * Nothing in this file pretends to be an API response. There is no mock
 * HTTP client, no simulated gateway and no fabricated verification.
 *
 * Implements:
 *   FR-4.11  bKash and Nagad manual merchant payments are recorded with the
 *            sender number and transaction id, and the payment is NOT
 *            confirmed until an Admin verifies it
 *   FR-4.12  a transaction id can never be submitted twice
 *   FR-4.13  the wallet methods are available only when a merchant number is
 *            configured
 *   FR-4.17 the customer sees a pending message, never a paid message,
 *            before verification
 *   FR-6.10 the Admin Order Detail page shows the wallet payment details
 *           and offers approve/reject
 *   FR-6.11 the Admin action is protected by authentication, authorisation,
 *           CSRF and server-side validation
 *   BR-8     the permitted (method, status, order_id) shapes
 *   BR-12    a payment that is not verified is never treated as paid
 */

require_once dirname(__DIR__) . '/helpers/db.php';

class WalletPayment
{
    // -----------------------------------------------------------------------
    // Vocabulary
    // -----------------------------------------------------------------------

    /** The bKash method. */
    public const METHOD_BKASH = 'bkash';

    /** The Nagad method. */
    public const METHOD_NAGAD = 'nagad';

    /**
     * The two wallet methods.
     *
     * @var array<int, string>
     */
    public const METHODS = array(self::METHOD_BKASH, self::METHOD_NAGAD);

    /**
     * The status of a `payment_ref` row that the store has not looked at yet.
     */
    public const STATUS_PENDING = 'pending_verification';

    /**
     * The status of a `payment_ref` row an Admin has checked against the
     * wallet statement and accepted.
     */
    public const STATUS_VERIFIED = 'verified';

    /**
     * The status of a `payment_ref` row an Admin has checked and refused.
     */
    public const STATUS_REJECTED = 'rejected';

    /**
     * The payment status that means "submitted, not yet checked".
     */
    public const PAYMENT_STATUS_PENDING = 'pending_verification';

    /**
     * The shortest accepted sender number, in digits.
     *
     * A Bangladeshi mobile number is 11 digits and starts with 01.
     */
    public const MIN_SENDER_DIGITS = 11;

    /**
     * The longest accepted sender number, in digits.
     */
    public const MAX_SENDER_DIGITS = 15;

    /**
     * The shortest accepted transaction id, in characters.
     *
     * bKash transaction ids are 10 alphanumeric characters; Nagad's are
     * similar. The lower bound is kept deliberately short so that a new
     * operator format does not lock the customer out of paying.
     */
    public const MIN_TRXID_LENGTH = 6;

    /**
     * The longest accepted transaction id, in characters.
     */
    public const MAX_TRXID_LENGTH = 64;

    /**
     * The longest accepted rejection reason, in characters.
     */
    public const MAX_REASON_LENGTH = 255;

    // -----------------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------------

    /**
     * The merchant number of a wallet method.
     *
     * @param string $method 'bkash' or 'nagad'.
     * @return string The configured merchant number, or '' when none is set.
     */
    public static function merchantNumber(string $method): string
    {
        if ($method === self::METHOD_BKASH) {
            return trim((string) (defined('BKASH_MERCHANT_NUMBER') ? BKASH_MERCHANT_NUMBER : ''));
        }

        if ($method === self::METHOD_NAGAD) {
            return trim((string) (defined('NAGAD_MERCHANT_NUMBER') ? NAGAD_MERCHANT_NUMBER : ''));
        }

        return '';
    }

    /**
     * The dial code a customer uses to reach the merchant payment menu.
     *
     * @param string $method 'bkash' or 'nagad'.
     * @return string
     */
    public static function dialCode(string $method): string
    {
        if ($method === self::METHOD_BKASH) {
            return defined('BKASH_MERCHANT_DIAL_CODE') ? (string) BKASH_MERCHANT_DIAL_CODE : '*247#';
        }

        if ($method === self::METHOD_NAGAD) {
            return defined('NAGAD_MERCHANT_DIAL_CODE') ? (string) NAGAD_MERCHANT_DIAL_CODE : '*167#';
        }

        return '';
    }

    /**
     * The display name of a wallet method.
     *
     * @param string $method 'bkash' or 'nagad'.
     * @return string
     */
    public static function methodLabel(string $method): string
    {
        if ($method === self::METHOD_BKASH) {
            return 'bKash';
        }

        if ($method === self::METHOD_NAGAD) {
            return 'Nagad';
        }

        return 'Unknown';
    }

    /**
     * Whether a string is one of the two wallet methods.
     *
     * @param mixed $method
     * @return bool
     */
    public static function isWalletMethod($method): bool
    {
        return is_string($method) && in_array($method, self::METHODS, true);
    }

    /**
     * Whether a wallet method is available in this installation.
     *
     * A method with no merchant number is not offered: the customer must
     * never be told to send money to a number the store does not watch.
     *
     * @param string $method
     * @return bool
     */
    public static function isAvailable(string $method): bool
    {
        return self::isWalletMethod($method) && self::merchantNumber($method) !== '';
    }

    /**
     * The wallet methods that are available in this installation.
     *
     * @return array<int, string>
     */
    public static function availableMethods(): array
    {
        $available = array();

        foreach (self::METHODS as $method) {
            if (self::isAvailable($method)) {
                $available[] = $method;
            }
        }

        return $available;
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    /**
     * Strip the spaces, hyphens and parentheses from a sender number.
     *
     * @param string $number
     * @return string
     */
    public static function normaliseSenderNumber(string $number): string
    {
        return preg_replace('/[\s\-()]/', '', trim($number)) ?? '';
    }

    /**
     * Strip the spaces and hyphens from a transaction id.
     *
     * @param string $transactionId
     * @return string
     */
    public static function normaliseTransactionId(string $transactionId): string
    {
        return preg_replace('/[\s\-]/', '', trim($transactionId)) ?? '';
    }

    /**
     * Validate a sender number.
     *
     * A Bangladeshi mobile number is 11 digits beginning with 01. Numbers
     * from other countries are accepted in the international form, which
     * starts with a country code and a plus sign, so that a customer using
     * a wallet account registered abroad is not locked out.
     *
     * The check is made on the digits only, so the customer may type the
     * number with spaces, hyphens or brackets.
     *
     * @param string $number
     * @return string The error message, or '' when the number is valid.
     */
    public static function validateSenderNumber(string $number): string
    {
        $digits = self::normaliseSenderNumber($number);

        if ($digits === '') {
            return 'Please enter the sender number you paid from.';
        }

        // The international form: +8801XXXXXXXXX.
        if (strpos($digits, '+') === 0) {
            $international = substr($digits, 1);

            if (!ctype_digit($international)) {
                return 'Please enter the sender number as digits only.';
            }

            if (strlen($international) < 12 || strlen($international) > 15) {
                return 'Please enter a sender number of between 11 and 15 digits.';
            }

            return '';
        }

        if (!ctype_digit($digits)) {
            return 'Please enter the sender number as digits only.';
        }

        if (strlen($digits) < self::MIN_SENDER_DIGITS || strlen($digits) > self::MAX_SENDER_DIGITS) {
            return 'Please enter a sender number of between 11 and 15 digits.';
        }

        // A local Bangladeshi number must be 11 digits starting with 01.
        if (strlen($digits) === 11 && strpos($digits, '01') !== 0) {
            return 'A local sender number must start with 01.';
        }

        return '';
    }

    /**
     * Validate a transaction id.
     *
     * The id is alphanumeric. The check is deliberately about shape only:
     * it cannot tell whether the money really arrived, because nothing here
     * talks to the wallet operator. That is the Admin's job, which is
     * exactly why the payment stays unverified until they act.
     *
     * @param string $transactionId
     * @return string The error message, or '' when the id is valid.
     */
    public static function validateTransactionId(string $transactionId): string
    {
        $id = self::normaliseTransactionId($transactionId);

        if ($id === '') {
            return 'Please enter the transaction id from your payment receipt.';
        }

        if (!preg_match('/^[A-Za-z0-9]+$/', $id)) {
            return 'The transaction id may only contain letters and digits.';
        }

        if (strlen($id) < self::MIN_TRXID_LENGTH || strlen($id) > self::MAX_TRXID_LENGTH) {
            return 'Please enter a transaction id of between '
                . self::MIN_TRXID_LENGTH . ' and ' . self::MAX_TRXID_LENGTH . ' characters.';
        }

        return '';
    }

    /**
     * Validate both wallet fields.
     *
     * @param string $senderNumber
     * @param string $transactionId
     * @return array<string, string> The field errors; empty when valid.
     */
    public static function validate(string $senderNumber, string $transactionId): array
    {
        $errors = array();

        $senderError = self::validateSenderNumber($senderNumber);

        if ($senderError !== '') {
            $errors['sender_number'] = $senderError;
        }

        $trxError = self::validateTransactionId($transactionId);

        if ($trxError !== '') {
            $errors['transaction_id'] = $trxError;
        }

        return $errors;
    }

    // -----------------------------------------------------------------------
    // The reference shown to the customer
    // -----------------------------------------------------------------------

    /**
     * Build the reference code the customer is shown and asked to quote.
     *
     * The reference is what the store matches the incoming payment against
     * on the wallet statement. It has to be:
     *
     *   - **Stable.** The customer sees it on the Payment page, then sees
     *     it again after the order is placed, and the Admin reads it off
     *     the wallet statement. If it changed between those moments the
     *     store could not match anything. It is therefore derived from
     *     the cart and the customer, not from the order number — the
     *     order number is only allocated once the transaction runs, which
     *     is after the customer has already read the reference.
     *   - **Hard to guess.** It is derived from the customer id and the
     *     cart contents, so another customer cannot work out somebody
     *     else's reference and quote it in their own payment.
     *   - **Readable.** It is short enough to write on a note and to read
     *     out over the phone.
     *
     * @param int   $userId The customer placing the order.
     * @param array $lines  The cart lines: product id and quantity.
     * @return string
     */
    public static function referenceCode(int $userId, array $lines): string
    {
        $material = 'GC|' . $userId;

        foreach ($lines as $line) {
            $material .= '|' . (int) ($line['product_id'] ?? 0) . 'x' . (int) ($line['quantity'] ?? 0);
        }

        // A short, stable hash. It is a matching aid, not a secret, and it
        // is not a security token: the transaction id is what identifies
        // the payment, and it is verified against the wallet statement.
        return strtoupper(substr(hash('sha256', $material), 0, 8));
    }

    // -----------------------------------------------------------------------
    // Duplicate detection
    // -----------------------------------------------------------------------

    /**
     * Whether a transaction id has already been submitted.
     *
     * The uniqueness is enforced by the database as well — the
     * `payment_ref` table carries a UNIQUE key on `transaction_id` — so
     * that two customers submitting the same id at the same moment cannot
     * both succeed. This method exists to give a friendly message in the
     * common case; the constraint is what actually guarantees it.
     *
     * @param string $transactionId
     * @param int    $excludeId    A row to ignore, used when an Admin looks
     *                             at a submission that is already recorded.
     * @return bool
     */
    public static function transactionIdExists(string $transactionId, int $excludeId = 0): bool
    {
        $id = self::normaliseTransactionId($transactionId);

        if ($id === '') {
            return false;
        }

        $row = db_select_one(
            'SELECT `id` FROM `payment_ref` WHERE `transaction_id` = ? AND `id` <> ? LIMIT 1',
            'si',
            array($id, $excludeId)
        );

        return $row !== null;
    }

    // -----------------------------------------------------------------------
    // Writing rows
    // -----------------------------------------------------------------------

    /**
     * Record a wallet payment that is awaiting verification.
     *
     * The `payments` row is `pending_verification` and the `payment_ref`
     * row carries the sender number, the transaction id, the amount and the
     * reference code the customer was shown.
     *
     * The amount is captured at the time of the submission, so that a later
     * price change cannot rewrite what the customer was asked to pay.
     *
     * @param int    $orderId        The order the payment belongs to.
     * @param string $method         'bkash' or 'nagad'.
     * @param string $senderNumber
     * @param string $transactionId
     * @param string $amount         The order total, as a decimal string.
     * @param string $referenceCode  The reference shown to the customer.
     * @return int The new payment id.
     */
    public static function recordPending(
        int $orderId,
        string $method,
        string $senderNumber,
        string $transactionId,
        string $amount,
        string $referenceCode
    ): int {
        $paymentId = db_insert_id(
            "INSERT INTO `payments` (`order_id`, `method`, `status`, `paid_at`)
             VALUES (?, ?, 'pending_verification', NULL)",
            'is',
            array($orderId, $method)
        );

        db_write(
            'INSERT INTO `payment_ref`
                (`payment_id`, `method`, `sender_number`, `transaction_id`, `amount`, `reference_code`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            'isssdss',
            array(
                $paymentId,
                $method,
                self::normaliseSenderNumber($senderNumber),
                self::normaliseTransactionId($transactionId),
                $amount,
                $referenceCode,
                self::STATUS_PENDING,
            )
        );

        return $paymentId;
    }

    // -----------------------------------------------------------------------
    // Reading rows
    // -----------------------------------------------------------------------

    /**
     * The wallet submission of a payment, if it has one.
     *
     * @param int $paymentId
     * @return array<string, mixed>|null
     */
    public static function forPayment(int $paymentId): ?array
    {
        return db_select_one(
            'SELECT `id`, `payment_id`, `method`, `sender_number`, `transaction_id`,
                    `amount`, `reference_code`, `status`, `verified_by`, `verified_at`,
                    `rejection_reason`, `created_at`
               FROM `payment_ref`
              WHERE `payment_id` = ?
              ORDER BY `id` DESC
              LIMIT 1',
            'i',
            array($paymentId)
        );
    }

    /**
     * The wallet submissions that are waiting for an Admin to check them.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function pendingSubmissions(int $limit = 50): array
    {
        return db_select(
            'SELECT pr.`id`, pr.`payment_id`, pr.`method`, pr.`sender_number`,
                    pr.`transaction_id`, pr.`amount`, pr.`reference_code`,
                    pr.`status`, pr.`created_at`,
                    o.`id` AS `order_id`, o.`order_number`, o.`total_amount`,
                    u.`name` AS `customer_name`
               FROM `payment_ref` pr
               JOIN `payments` p ON p.`id` = pr.`payment_id`
               JOIN `orders` o ON o.`id` = p.`order_id`
               JOIN `users` u ON u.`id` = o.`user_id`
              WHERE pr.`status` = ?
              ORDER BY pr.`created_at` ASC, pr.`id` ASC
              LIMIT ?',
            'ii',
            array(self::STATUS_PENDING, $limit)
        );
    }

    /**
     * The number of wallet submissions, for the tests and the health checks.
     *
     * @return int
     */
    public static function countAll(): int
    {
        $row = db_select_one('SELECT COUNT(*) AS `total` FROM `payment_ref`');

        return (int) ($row['total'] ?? 0);
    }

    // -----------------------------------------------------------------------
    // Admin verification
    // -----------------------------------------------------------------------

    /**
     * Approve a wallet payment: the store has checked the money arrived.
     *
     * This is the ONLY way a bKash or Nagad payment becomes a success.
     * There is no automated path: a submitted transaction id is a claim,
     * and only a human who has seen the wallet statement can confirm it.
     *
     * The whole action runs in one transaction, and the row is locked with
     * FOR UPDATE, so that two Admins pressing "Approve" at the same moment
     * cannot both succeed: the second one finds the row already verified
     * and is refused.
     *
     * @param int $paymentId The payment to approve.
     * @param int $adminId   The Admin who approved it.
     * @return array{status: string, message: string} 'success' when the
     *         payment was approved, otherwise a 'refused' status with the
     *         reason.
     */
    public static function verify(int $paymentId, int $adminId): array
    {
        $result = array(
            'status'  => 'refused',
            'message' => '',
        );

        db_begin_transaction();

        try {
            $payment = db_select_one(
                'SELECT `id`, `order_id`, `method`, `status`
                   FROM `payments`
                  WHERE `id` = ?
                  FOR UPDATE',
                'i',
                array($paymentId)
            );

            if ($payment === null) {
                db_rollback();
                $result['message'] = 'We could not find that payment.';

                return $result;
            }

            $method = (string) $payment['method'];

            if (!self::isWalletMethod($method)) {
                db_rollback();
                $result['message'] = 'This is not a bKash or Nagad payment.';

                return $result;
            }

            $reference = db_select_one(
                'SELECT `id`, `status` FROM `payment_ref` WHERE `payment_id` = ? FOR UPDATE',
                'i',
                array($paymentId)
            );

            if ($reference === null) {
                db_rollback();
                $result['message'] = 'This payment has no wallet submission attached to it.';

                return $result;
            }

            $referenceStatus = (string) $reference['status'];

            // The payment can be approved exactly once.
            if ($referenceStatus !== self::STATUS_PENDING) {
                db_rollback();
                $result['message'] = $referenceStatus === self::STATUS_VERIFIED
                    ? 'This payment has already been verified.'
                    : 'This payment has already been rejected.';

                return $result;
            }

            // The payments row and the reference row must agree before the
            // write, so that a payment that was never pending cannot be
            // approved.
            if ((string) $payment['status'] !== self::PAYMENT_STATUS_PENDING) {
                db_rollback();
                $result['message'] = 'This payment is not awaiting verification.';

                return $result;
            }

            $now = Order::now();

            db_write(
                'UPDATE `payment_ref`
                    SET `status` = ?, `verified_by` = ?, `verified_at` = ?, `rejection_reason` = NULL
                  WHERE `id` = ?',
                'sisi',
                array(self::STATUS_VERIFIED, $adminId, $now, (int) $reference['id'])
            );

            db_write(
                'UPDATE `payments` SET `status` = ?, `paid_at` = ? WHERE `id` = ?',
                'ssi',
                array('success', $now, $paymentId)
            );

            db_commit();

            $result['status'] = 'success';

            return $result;
        } catch (Throwable $exception) {
            db_rollback();

            throw $exception;
        }
    }

    /**
     * Reject a wallet payment.
     *
     * The payment moves to `failed` and the reason is recorded against the
     * submission, so the Admin can see later why it was refused. A
     * rejected payment is never silently retried by the customer: the
     * transaction id stays in the table, so the same id cannot be reused.
     *
     * @param int    $paymentId The payment to reject.
     * @param int    $adminId   The Admin who rejected it.
     * @param string $reason    Why the payment was refused.
     * @return array{status: string, message: string} 'success' when the
     *         payment was rejected, otherwise a 'refused' status with the
     *         reason.
     */
    public static function reject(int $paymentId, int $adminId, string $reason): array
    {
        $result = array(
            'status'  => 'refused',
            'message' => '',
        );

        $reason = trim($reason);

        if ($reason === '') {
            $result['message'] = 'Please give a reason for rejecting this payment.';

            return $result;
        }

        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            $result['message'] = 'The reason is too long. Please keep it under '
                . self::MAX_REASON_LENGTH . ' characters.';

            return $result;
        }

        db_begin_transaction();

        try {
            $payment = db_select_one(
                'SELECT `id`, `method`, `status` FROM `payments` WHERE `id` = ? FOR UPDATE',
                'i',
                array($paymentId)
            );

            if ($payment === null) {
                db_rollback();
                $result['message'] = 'We could not find that payment.';

                return $result;
            }

            if (!self::isWalletMethod((string) $payment['method'])) {
                db_rollback();
                $result['message'] = 'This is not a bKash or Nagad payment.';

                return $result;
            }

            $reference = db_select_one(
                'SELECT `id`, `status` FROM `payment_ref` WHERE `payment_id` = ? FOR UPDATE',
                'i',
                array($paymentId)
            );

            if ($reference === null) {
                db_rollback();
                $result['message'] = 'This payment has no wallet submission attached to it.';

                return $result;
            }

            $referenceStatus = (string) $reference['status'];

            if ($referenceStatus !== self::STATUS_PENDING) {
                db_rollback();
                $result['message'] = $referenceStatus === self::STATUS_VERIFIED
                    ? 'This payment has already been verified.'
                    : 'This payment has already been rejected.';

                return $result;
            }

            $now = Order::now();

            db_write(
                'UPDATE `payment_ref`
                    SET `status` = ?, `verified_by` = ?, `verified_at` = ?, `rejection_reason` = ?
                  WHERE `id` = ?',
                'sisss',
                array(self::STATUS_REJECTED, $adminId, $now, $reason, (int) $reference['id'])
            );

            db_write(
                'UPDATE `payments` SET `status` = ?, `paid_at` = NULL WHERE `id` = ?',
                'si',
                array('failed', $paymentId)
            );

            db_commit();

            $result['status'] = 'success';

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
     * The result of a gateway call that was not made.
     *
     * @return array{status: string, message: string}
     */
    private static function gatewayUnavailable(): array
    {
        return array(
            'status'  => 'gateway_unavailable',
            'message' => 'The mobile wallet gateway is not connected. The payment must be checked by an Admin.',
        );
    }

    /**
     * The seam where a gateway "create payment" call would go.
     *
     * There is no gateway integration yet, so this method makes NO network
     * call and returns `gateway_unavailable`. It exists so that adding the
     * official API later is a change inside this method rather than a
     * change to the checkout flow.
     *
     * @param string $method 'bkash' or 'nagad'.
     * @param string $amount The amount to charge.
     * @return array{status: string, message: string, reference: string}
     */
    public static function initiate(string $method, string $amount): array
    {
        return self::gatewayUnavailable();
    }

    /**
     * The seam where a gateway "query transaction" call would go.
     *
     * There is no gateway integration yet, so this method makes NO network
     * call and returns `gateway_unavailable`. It never returns a
     * "verified" result, because it never asks anyone.
     *
     * @param string $method 'bkash' or 'nagad'.
     * @param string $transactionId
     * @return array{status: string, message: string}
     */
    public static function queryTransaction(string $method, string $transactionId): array
    {
        return self::gatewayUnavailable();
    }
}
