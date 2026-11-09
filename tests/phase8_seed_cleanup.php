<?php
/**
 * GreenCorner — Test database reset helper.
 *
 * Removes the rows that the ad-hoc sessions left behind, so that the
 * seeded catalogue matches database/seed.php exactly and the phase 2 suite
 * measures the seed rather than the leftovers.
 *
 * Usage:
 *   php tests/phase8_seed_cleanup.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/db.php';

$rows = db_select(
    'SELECT `id`, `name` FROM `categories` ORDER BY `id`'
);

$seedCategories = array('Plants', 'Seeds', 'Pots', 'Tools', 'Fertilisers');
$removed = 0;

foreach ($rows as $category) {
    if (in_array((string) $category['name'], $seedCategories, true)) {
        continue;
    }

    $categoryId = (int) $category['id'];

    db_write('DELETE FROM `cart_items` WHERE `product_id` IN (SELECT `id` FROM `products` WHERE `category_id` = ?)', 'i', array($categoryId));
    db_write('DELETE FROM `order_items` WHERE `product_id` IN (SELECT `id` FROM `products` WHERE `category_id` = ?)', 'i', array($categoryId));
    db_write('DELETE FROM `products` WHERE `category_id` = ?', 'i', array($categoryId));
    db_write('DELETE FROM `categories` WHERE `id` = ?', 'i', array($categoryId));

    echo 'Removed the category "' . $category['name'] . '" and its products.' . PHP_EOL;
    $removed++;
}

// The products the seed script defines, by name. Anything else is a
// leftover from an ad-hoc session.
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
    $removed++;
}

// Run the seed again, so the catalogue matches the seed script exactly.
$output = array();
$status = 0;

exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/database/seed.php') . ' 2>&1',
    $output,
    $status
);

echo 'The seed exited with ' . $status . '.' . PHP_EOL;

if ($status !== 0) {
    echo implode(PHP_EOL, $output) . PHP_EOL;
    exit(1);
}

$counts = db_select_one(
    'SELECT (SELECT COUNT(*) FROM `categories`) AS `categories`,
            (SELECT COUNT(*) FROM `products`) AS `products`'
);

echo 'The catalogue now holds ' . $counts['categories'] . ' categories and '
    . $counts['products'] . ' products.' . PHP_EOL;

exit(0);
