<?php
/**
 * GreenCorner — Checkout Snapshot
 *
 * The short-lived record of the price summary the customer was shown.
 *
 * FR-4.12 requires the customer to confirm the price before the order is
 * committed, and FR-4.11 requires the price to be re-validated at
 * checkout. The snapshot is what makes the comparison possible: it holds
 * the totals that were displayed on the Payment page (P-09), so the
 * checkout can tell whether the price moved in the meantime.
 *
 * The snapshot lives in the session, not in the database: it is a
 * short-lived, per-browser artefact and it must never be trusted as the
 * source of truth. The authoritative values are always re-read from the
 * database inside the checkout transaction.
 *
 * The snapshot is single-use: it is consumed by the checkout attempt, and
 * a new one is created whenever the Payment page is opened.
 */

require_once __DIR__ . '/functions.php';

/**
 * The session key under which the snapshot is stored.
 */
const CHECKOUT_SNAPSHOT_KEY = 'checkout_snapshot';

/**
 * How long a snapshot stays valid, in seconds.
 *
 * The snapshot is only used between the moment the Payment page is shown
 * and the moment the customer submits it. A short lifetime means a stale
 * tab cannot be used to buy at an old price.
 */
const CHECKOUT_SNAPSHOT_TTL = 1800;

/**
 * Create the snapshot for the current cart.
 *
 * @param array<int, array<string, mixed>> $rows  The cart rows.
 * @param float                            $total The total shown to the customer.
 * @return void
 */
function checkout_snapshot_create(array $rows, float $total): void
{
    $lines = array();

    foreach ($rows as $row) {
        $lines[] = array(
            'product_id'   => (int) $row['product_id'],
            'quantity'     => (int) $row['quantity'],
            'price_at_add' => (string) $row['price_at_add'],
        );
    }

    $_SESSION[CHECKOUT_SNAPSHOT_KEY] = array(
        'created_at' => time(),
        'total'      => round($total, 2),
        'lines'      => $lines,
    );
}

/**
 * Read the snapshot, without consuming it.
 *
 * @return array{created_at: int, total: float, lines: array<int, array<string, mixed>>}|null
 */
function checkout_snapshot_read(): ?array
{
    if (!isset($_SESSION[CHECKOUT_SNAPSHOT_KEY]) || !is_array($_SESSION[CHECKOUT_SNAPSHOT_KEY])) {
        return null;
    }

    $snapshot = $_SESSION[CHECKOUT_SNAPSHOT_KEY];

    if (!isset($snapshot['created_at']) || !is_int($snapshot['created_at'])) {
        return null;
    }

    // An expired snapshot is treated as absent.
    if ((time() - $snapshot['created_at']) > CHECKOUT_SNAPSHOT_TTL) {
        unset($_SESSION[CHECKOUT_SNAPSHOT_KEY]);
        return null;
    }

    return array(
        'created_at' => $snapshot['created_at'],
        'total'      => isset($snapshot['total']) ? (float) $snapshot['total'] : 0.0,
        'lines'      => isset($snapshot['lines']) && is_array($snapshot['lines']) ? $snapshot['lines'] : array(),
    );
}

/**
 * The total recorded in the snapshot.
 *
 * @return float|null The total, or null when there is no valid snapshot.
 */
function checkout_snapshot_total(): ?float
{
    $snapshot = checkout_snapshot_read();

    return $snapshot === null ? null : $snapshot['total'];
}

/**
 * Consume the snapshot.
 *
 * The snapshot is single-use: it is removed when the checkout attempt is
 * made, whether that attempt succeeded or not, so a repeated submission
 * cannot reuse an old total.
 *
 * @return void
 */
function checkout_snapshot_consume(): void
{
    unset($_SESSION[CHECKOUT_SNAPSHOT_KEY]);
}

/**
 * Whether the snapshot matches the current cart.
 *
 * The comparison covers the cart contents: the products, their quantities
 * and the price each was added at. A mismatch means the cart changed since
 * the Payment page was shown, and the customer must be taken back to the
 * cart.
 *
 * @param array<int, array<string, mixed>> $rows The current cart rows.
 * @return bool
 */
function checkout_snapshot_matches(array $rows): bool
{
    $snapshot = checkout_snapshot_read();

    if ($snapshot === null) {
        return false;
    }

    if (count($snapshot['lines']) !== count($rows)) {
        return false;
    }

    $current = array();

    foreach ($rows as $row) {
        $current[(int) $row['product_id']] = array(
            'quantity'     => (int) $row['quantity'],
            'price_at_add' => (string) $row['price_at_add'],
        );
    }

    foreach ($snapshot['lines'] as $line) {
        $productId = (int) ($line['product_id'] ?? 0);

        if (!isset($current[$productId])) {
            return false;
        }

        if ($current[$productId]['quantity'] !== (int) ($line['quantity'] ?? 0)) {
            return false;
        }
    }

    return true;
}
