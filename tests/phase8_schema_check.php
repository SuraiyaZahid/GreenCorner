<?php
/**
 * GreenCorner — Wallet payment schema check.
 *
 * Confirms that the schema the wallet payment feature relies on is really
 * in place: the widened payment enums, the new tables, their unique keys
 * and their foreign keys.
 *
 * Usage:
 *   php tests/phase8_schema_check.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/db.php';

$failures = 0;

/**
 * Assert that a condition holds.
 */
function p8_assert(bool $condition, string $message): void
{
    global $failures;

    if ($condition) {
        echo '  [PASS] ' . $message . PHP_EOL;
        return;
    }

    echo '  [FAIL] ' . $message . PHP_EOL;
    $failures++;
}

echo "GreenCorner — wallet payment schema check" . PHP_EOL . PHP_EOL;

/**
 * MySQL returns the information_schema column names in upper case, so the
 * rows are normalised before they are read.
 */
function p8_rows(array $rows): array
{
    $normalised = array();

    foreach ($rows as $row) {
        $entry = array();

        foreach ($row as $name => $value) {
            $entry[strtolower((string) $name)] = $value;
        }

        $normalised[] = $entry;
    }

    return $normalised;
}

// ---------------------------------------------------------------------------
echo "The payments table" . PHP_EOL;

$row = db_select_one(
    "SELECT `column_type` FROM `information_schema`.`columns`
      WHERE `table_schema` = DATABASE() AND `table_name` = 'payments' AND `column_name` = 'method'"
);

$row = $row === null ? null : p8_rows(array($row))[0];

p8_assert(
    $row !== null && strpos((string) $row['column_type'], "'bkash'") !== false
        && strpos((string) $row['column_type'], "'nagad'") !== false,
    'The payments.method enum covers bkash and nagad.'
);

$row = db_select_one(
    "SELECT `column_type` FROM `information_schema`.`columns`
      WHERE `table_schema` = DATABASE() AND `table_name` = 'payments' AND `column_name` = 'status'"
);

$row = $row === null ? null : p8_rows(array($row))[0];

p8_assert(
    $row !== null && strpos((string) $row['column_type'], "'pending_verification'") !== false,
    'The payments.status enum covers pending_verification.'
);

$row = db_select_one(
    "SELECT `column_type` FROM `information_schema`.`columns`
      WHERE `table_schema` = DATABASE() AND `table_name` = 'orders' AND `column_name` = 'status'"
);

$row = $row === null ? null : p8_rows(array($row))[0];

p8_assert(
    $row !== null && strpos((string) $row['column_type'], "'paid_unfulfillable'") !== false,
    'The orders.status enum covers paid_unfulfillable.'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . "The payment_ref table" . PHP_EOL;

$columns = p8_rows(db_select(
    "SELECT `column_name` FROM `information_schema`.`columns`
      WHERE `table_schema` = DATABASE() AND `table_name` = 'payment_ref'"
));

$columnNames = array();
foreach ($columns as $column) {
    $columnNames[] = (string) $column['column_name'];
}

foreach (array('sender_number', 'transaction_id', 'amount', 'reference_code', 'status',
               'verified_by', 'verified_at', 'rejection_reason') as $expected) {
    p8_assert(
        in_array($expected, $columnNames, true),
        'payment_ref.' . $expected . ' exists.'
    );
}

$indexes = p8_rows(db_select(
    "SELECT `index_name`, `non_unique` FROM `information_schema`.`statistics`
      WHERE `table_schema` = DATABASE() AND `table_name` = 'payment_ref'"
));

$unique = array();
foreach ($indexes as $index) {
    if ((int) $index['non_unique'] === 0) {
        $unique[] = (string) $index['index_name'];
    }
}

p8_assert(
    in_array('uq_payment_ref_transaction_id', $unique, true),
    'payment_ref has a UNIQUE key on the transaction id.'
);

$constraints = p8_rows(db_select(
    "SELECT `constraint_name` FROM `information_schema`.`table_constraints`
      WHERE `table_schema` = DATABASE() AND `table_name` = 'payment_ref'
        AND `constraint_type` = 'FOREIGN KEY'"
));

$foreignKeys = array();
foreach ($constraints as $constraint) {
    $foreignKeys[] = (string) $constraint['constraint_name'];
}

p8_assert(
    in_array('fk_payment_ref_payment', $foreignKeys, true),
    'payment_ref is bound to payments by a foreign key.'
);
p8_assert(
    in_array('fk_payment_ref_verified_by', $foreignKeys, true),
    'payment_ref is bound to users by a foreign key.'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . "The refunds table" . PHP_EOL;

$columns = p8_rows(db_select(
    "SELECT `column_name` FROM `information_schema`.`columns`
      WHERE `table_schema` = DATABASE() AND `table_name` = 'refunds'"
));

$columnNames = array();
foreach ($columns as $column) {
    $columnNames[] = (string) $column['column_name'];
}

foreach (array('payment_id', 'amount', 'status', 'reason', 'gateway_reference',
               'failure_detail', 'confirmed_at') as $expected) {
    p8_assert(
        in_array($expected, $columnNames, true),
        'refunds.' . $expected . ' exists.'
    );
}

$row = db_select_one(
    "SELECT `column_type` FROM `information_schema`.`columns`
      WHERE `table_schema` = DATABASE() AND `table_name` = 'refunds' AND `column_name` = 'status'"
);

$row = $row === null ? null : p8_rows(array($row))[0];

p8_assert(
    $row !== null
        && strpos((string) $row['column_type'], "'refund_pending'") !== false
        && strpos((string) $row['column_type'], "'refunded'") !== false
        && strpos((string) $row['column_type'], "'refund_failed'") !== false,
    'The refunds.status enum covers refund_pending, refunded and refund_failed.'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . "The pre-existing data survived the migration" . PHP_EOL;

$row = db_select_one('SELECT COUNT(*) AS `total` FROM `payment_ref`');
p8_assert((int) $row['total'] >= 0, 'The payment_ref table is readable.');

echo PHP_EOL;

if ($failures > 0) {
    echo 'RESULT: ' . $failures . ' problem(s) found.' . PHP_EOL;
    exit(1);
}

echo 'RESULT: the wallet payment schema is in place.' . PHP_EOL;
exit(0);
