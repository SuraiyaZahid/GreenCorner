<?php
/**
 * GreenCorner — Test database reset helper (orders and payments).
 *
 * The phase 4 isolation suite asserts that the orders and payments tables
 * are empty once it has cleaned up after itself, which only holds on a
 * database that has never held an order. This helper removes the orders
 * that the ad-hoc sessions left behind.
 *
 * Usage:
 *   php tests/phase8_order_cleanup.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/db.php';

$orders = db_select('SELECT `id`, `order_number` FROM `orders` ORDER BY `id`');

foreach ($orders as $order) {
    $orderId = (int) $order['id'];

    db_write('DELETE FROM `refunds` WHERE `payment_id` IN (SELECT `id` FROM `payments` WHERE `order_id` = ?)', 'i', array($orderId));
    db_write('DELETE FROM `payment_ref` WHERE `payment_id` IN (SELECT `id` FROM `payments` WHERE `order_id` = ?)', 'i', array($orderId));
    db_write('DELETE FROM `order_items` WHERE `order_id` = ?', 'i', array($orderId));
    db_write('DELETE FROM `payments` WHERE `order_id` = ?', 'i', array($orderId));
    db_write('DELETE FROM `orders` WHERE `id` = ?', 'i', array($orderId));

    echo 'Removed the order ' . $order['order_number'] . ' and its payment rows.' . PHP_EOL;
}

$counts = db_select_one(
    'SELECT (SELECT COUNT(*) FROM `orders`) AS `orders`,
            (SELECT COUNT(*) FROM `payments`) AS `payments`,
            (SELECT COUNT(*) FROM `payment_ref`) AS `payment_ref`,
            (SELECT COUNT(*) FROM `refunds`) AS `refunds`'
);

echo 'The database now holds ' . $counts['orders'] . ' order(s), '
    . $counts['payments'] . ' payment(s), '
    . $counts['payment_ref'] . ' wallet submission(s) and '
    . $counts['refunds'] . ' refund(s).' . PHP_EOL;

exit(0);
