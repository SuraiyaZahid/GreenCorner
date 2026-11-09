<?php
/**
 * GreenCorner — Fulfilment Status Vocabulary
 *
 * The single place where the permitted fulfilment statuses and the
 * permitted transitions between them are defined.
 *
 * Implements:
 *   BR-7   the fulfilment status is exactly one of `placed`, `processing`,
 *          `shipped`, `delivered`, `cancelled`; the system sets `placed`;
 *          only an Admin changes it; `cancelled` is terminal; backward
 *          transitions are permitted; all transitions are validated
 *   FR-6.8 the Admin Order Detail page allows the Admin to update the
 *          fulfilment status, and every transition is validated
 *
 * The rules, in full:
 *
 *   - The normal forward fulfilment sequence is
 *     `placed -> processing -> shipped -> delivered`.
 *   - A backward correction between two non-terminal statuses is
 *     permitted, for example `shipped -> processing`. This is what FR-6.8
 *     means by "backward transitions are permitted (for example
 *     `shipped -> processing`)".
 *   - An **arbitrary forward jump is not** permitted: `placed -> shipped`
 *     and `placed -> delivered` are refused, because the intermediate
 *     fulfilment steps have not happened. (Approved decision A-3.)
 *   - `cancelled` is **terminal**: it can be entered from any non-terminal
 *     status, and nothing can leave it.
 *   - `delivered -> cancelled` is refused, because `delivered` is the
 *     completion of the order and the customer has already received it
 *     (FR-6.8, acceptance criterion 3). `delivered` can still be corrected
 *     backwards, to `shipped`, `processing` or `placed`.
 *   - A transition to the status the order already has is refused: it is
 *   not a change.
 *
 * The database stores the status in an ENUM, so the *values* are already
 * constrained there. The *transitions* are not: there is no CHECK
 * constraint on `orders.status`, so this file — and the service layer that
 * calls it — is the only enforcement point (SRS §10.4).
 *
 * This class is deliberately free of database access, so the rules can be
 * read and tested in one place.
 */
class OrderStatus
{
    /** The order has been placed and not yet processed. */
    public const PLACED = 'placed';

    /** The order is being prepared. */
    public const PROCESSING = 'processing';

    /** The order has been handed to the courier. */
    public const SHIPPED = 'shipped';

    /** The order has been received by the customer. */
    public const DELIVERED = 'delivered';

    /** The order has been cancelled. This status is terminal. */
    public const CANCELLED = 'cancelled';

    /**
     * Every permitted fulfilment status, in the order of the normal
     * fulfilment sequence.
     *
     * @var array<int, string>
     */
    public const ALL = array(
        self::PLACED,
        self::PROCESSING,
        self::SHIPPED,
        self::DELIVERED,
        self::CANCELLED,
    );

    /**
     * The normal forward fulfilment sequence.
     *
     * @var array<int, string>
     */
    public const FORWARD_SEQUENCE = array(
        self::PLACED,
        self::PROCESSING,
        self::SHIPPED,
        self::DELIVERED,
    );

    /**
     * The statuses that are not terminal.
     *
     * A backward correction is permitted between any two of these, and
     * `cancelled` may be entered from any of them.
     *
     * @var array<int, string>
     */
    public const NON_TERMINAL = array(
        self::PLACED,
        self::PROCESSING,
        self::SHIPPED,
        self::DELIVERED,
    );

    /**
     * Whether a string is one of the five permitted statuses.
     *
     * @param mixed $status
     * @return bool
     */
    public static function isValid($status): bool
    {
        if (!is_string($status)) {
            return false;
        }

        return in_array($status, self::ALL, true);
    }

    /**
     * Whether a status is terminal, meaning nothing can follow it.
     *
     * @param string $status
     * @return bool
     */
    public static function isTerminal(string $status): bool
    {
        return $status === self::CANCELLED;
    }

    /**
     * The statuses that may follow the given status.
     *
     * This is what the Admin Order Detail page offers, so a transition
     * that is refused is never offered in the first place (FR-6.8,
     * acceptance criterion 3).
     *
     * The returned list is empty for `cancelled`, because `cancelled` is
     * terminal.
     *
     * @param string $from The current status.
     * @return array<int, string>
     */
    public static function allowedTargets(string $from): array
    {
        if (!self::isValid($from)) {
            return array();
        }

        // The terminal status can never be left.
        if (self::isTerminal($from)) {
            return array();
        }

        $targets = array();

        // The next step in the normal forward sequence.
        $position = array_search($from, self::FORWARD_SEQUENCE, true);

        if ($position !== false && isset(self::FORWARD_SEQUENCE[$position + 1])) {
            $targets[] = self::FORWARD_SEQUENCE[$position + 1];
        }

        // A backward correction to any earlier non-terminal step. The
        // current status itself is not a target: setting the same status is
        // not a transition.
        for ($i = 0; $i < (int) $position; $i++) {
            $targets[] = self::FORWARD_SEQUENCE[$i];
        }

        // Cancellation is permitted from any non-terminal status, except
        // `delivered`: FR-6.8 acceptance criterion 3 requires that
        // `delivered -> cancelled` is refused, because the customer has
        // already received the order.
        if ($from !== self::DELIVERED) {
            $targets[] = self::CANCELLED;
        }

        return $targets;
    }

    /**
     * Whether a transition from one status to another is permitted.
     *
     * @param string $from The current status.
     * @param string $to   The requested status.
     * @return bool
     */
    public static function canTransition(string $from, string $to): bool
    {
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }

        // A transition to the same status is not a transition.
        if ($from === $to) {
            return false;
        }

        return in_array($to, self::allowedTargets($from), true);
    }

    /**
     * The message shown when a transition is refused.
     *
     * @param string $from The current status.
     * @param string $to   The requested status.
     * @return string
     */
    public static function refusalMessage(string $from, string $to): string
    {
        if (!self::isValid($to)) {
            return 'That is not a fulfilment status this store uses.';
        }

        if (self::isTerminal($from)) {
            return 'This order is cancelled, and a cancelled order cannot change status.';
        }

        if ($from === self::DELIVERED && $to === self::CANCELLED) {
            return 'A delivered order cannot be cancelled.';
        }

        if ($from === $to) {
            return 'This order already has that status.';
        }

        return 'The order cannot move from ' . $from . ' to ' . $to . '.';
    }

    /**
     * A human-readable label for a status.
     *
     * @param string $status
     * @return string
     */
    public static function label(string $status): string
    {
        if (!self::isValid($status)) {
            return 'Unknown';
        }

        return ucfirst($status);
    }
}
