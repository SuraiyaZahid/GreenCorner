<?php
/**
 * GreenCorner — Test database reset helper.
 *
 * Brings the test database back to the state the seeded catalogue
 * describes, so that the phase suites measure the application rather than
 * the rows that an ad-hoc session left behind.
 *
 * It removes:
 *   - the categories and products that database/seed.php does not define
 *   - the orders, payments, wallet submissions and refunds of every
 *     account the test suites created (the 'p8+' email prefix) and any
 *     leftover manual order
 *
 * The phase 4 isolation suite asserts that the orders and payments tables
 * are empty once it has cleaned up after itself, which only holds on a
 * database that has never held an order. That is what this helper is for.
 *
 * Usage:
 *   php tests/phase8_cleanup.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/db.php';

// ---------------------------------------------------------------------------
// The catalogue: everything the seed script does not define is a leftover.
// ---------------------------------------------------------------------------

$seedCategories = array('Plants', 'Seeds', 'Pots', 'Tools', 'Fertilisers');

$categories = db_select('SELECT `id`, `name` FROM `categories` ORDER BY `id`');

foreach ($categories as $category) {
    if (in_array((string) $category['name'], $seedCategories, true)) {
        continue;
    }

    $categoryId = (int) $category['id'];

    db_write('DELETE FROM `cart_items` WHERE `product_id` IN (SELECT `id` FROM `products` WHERE `category_id` = ?)', 'i', array($categoryId));
    db_write('DELETE FROM `order_items` WHERE `product_id` IN (SELECT `id` FROM `products` WHERE `category_id` = ?)', 'i', array($categoryId));
    db_write('DELETE FROM `products` WHERE `category_id` = ?', 'i', array($categoryId));
    db_write('DELETE FROM `categories` WHERE `id` = ?', 'i', array($categoryId));

    echo 'Removed the category "' . $category['name'] . '" and its products.' . PHP_EOL;
}

$seedProducts = array(
    'Boston Fern', 'Monstera Deliciosa', 'Snake Plant', 'Peace Lily',
    'Spider Plant', 'Rubber Plant', 'Aloe Vera Plant', 'Pothos Golden',
    'Basil Seeds Packet', 'Tomato Seeds Packet', 'Sunflower Seeds Packet',
    'Mint Seeds Packet', 'Marigold Seeds Packet',
    'Large Terracotta Pot', 'Small Plastic Pot', 'Large Ceramic Pot',
    'Hanging Basket Pot', 'Square Window Box', 'Mini Fairy Pot',
    'Garden Trowel', 'Pruning Shears', 'Watering Can', 'Hand Fork',
    'Organic Compost', 'Balanced Plant Food', 'Slow Release Granules',
    'Leaf Shine Spray', 'Rooting Powder',
);

$products = db_select('SELECT `id`, `name` FROM `products` ORDER BY `id`');

foreach ($products as $product) {
    if (in_array((string) $product['name'], $seedProducts, true)) {
        continue;
    }

    $productId = (int) $product['id'];

    db_write('DELETE FROM `cart_items` WHERE `product_id` = ?', 'i', array($productId));
    db_write('DELETE FROM `order_items` WHERE `product_id` = ?', 'i', array($productId));
    db_write('DELETE FROM `products` WHERE `id` = ?', 'i', array($productId));

    echo 'Removed the leftover product "' . $product['name'] . '".' . PHP_EOL;
}

// ---------------------------------------------------------------------------
// The orders: every order of every test account, and any leftover order.
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// The accounts: the ones the test suites created.
// ---------------------------------------------------------------------------

$users = db_select('SELECT `id`, `email` FROM `users` WHERE `email` LIKE ?', 's', array('p8+%'));

foreach ($users as $user) {
    $userId = (int) $user['id'];

    db_write('DELETE FROM `sessions` WHERE `user_id` = ?', 'i', array($userId));
    db_write('DELETE FROM `cart_items` WHERE `user_id` = ?', 'i', array($userId));
    db_write('DELETE FROM `users` WHERE `id` = ?', 'i', array($userId));

    echo 'Removed the account ' . $user['email'] . '.' . PHP_EOL;
}

// ---------------------------------------------------------------------------
// Re-run the seed, so the catalogue matches the seed script exactly.
// ---------------------------------------------------------------------------

$output = array();
$status = 0;

exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/database/seed.php') . ' 2>&1',
    $output,
    $status
);

if ($status !== 0) {
    echo 'The seed did not exit cleanly:' . PHP_EOL;
    echo implode(PHP_EOL, $output) . PHP_EOL;
    exit(1);
}

$counts = db_select_one(
    'SELECT (SELECT COUNT(*) FROM `categories`) AS `categories`,
            (SELECT COUNT(*) FROM `products`) AS `products`,
            (SELECT COUNT(*) FROM `orders`) AS `orders`,
            (SELECT COUNT(*) FROM `payments`) AS `payments`,
            (SELECT COUNT(*) FROM `payment_ref`) AS `payment_ref`,
            (SELECT COUNT(*) FROM `refunds`) AS `refunds`'
);

echo PHP_EOL . 'The database now holds ' . $counts['categories'] . ' categories, '
    . $counts['products'] . ' products, '
    . $counts['orders'] . ' order(s), '
    . $counts['payments'] . ' payment(s), '
    . $counts['payment_ref'] . ' wallet submission(s) and '
    . $counts['refunds'] . ' refund(s).' . PHP_EOL;

exit(0);
