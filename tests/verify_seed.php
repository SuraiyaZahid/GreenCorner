<?php
/**
 * GreenCorner — Seed verification helper.
 *
 * Confirms that every seeded product has a valid original image and a valid
 * square thumbnail on disk, and that the seed left no duplicate rows behind.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../helpers/image.php';

$rows = db_select(
    'SELECT `id`, `name`, `image_path`, `thumbnail_path` FROM `products` ORDER BY `id`'
);

$problems = 0;

foreach ($rows as $row) {
    $original = UPLOAD_DIR . DIRECTORY_SEPARATOR . $row['image_path'];
    $thumbnail = UPLOAD_DIR . DIRECTORY_SEPARATOR . $row['thumbnail_path'];

    if (!is_file($original) || !is_file($thumbnail)) {
        echo 'MISSING: ' . $row['name'] . PHP_EOL;
        $problems++;
        continue;
    }

    $originalInfo = @getimagesize($original);
    $thumbnailInfo = @getimagesize($thumbnail);

    if ($originalInfo === false || $thumbnailInfo === false) {
        echo 'NOT AN IMAGE: ' . $row['name'] . PHP_EOL;
        $problems++;
        continue;
    }

    if ($thumbnailInfo[0] !== THUMBNAIL_SIZE || $thumbnailInfo[1] !== THUMBNAIL_SIZE) {
        echo 'BAD THUMBNAIL SIZE: ' . $row['name']
            . ' ' . $thumbnailInfo[0] . 'x' . $thumbnailInfo[1] . PHP_EOL;
        $problems++;
    }
}

$duplicateNames = db_select(
    'SELECT `name`, COUNT(*) AS `total` FROM `products` GROUP BY `name` HAVING COUNT(*) > 1'
);

$categoryCount = db_select_one('SELECT COUNT(*) AS `total` FROM `categories`');
$productCount = db_select_one('SELECT COUNT(*) AS `total` FROM `products`');

echo 'Products checked:    ' . count($rows) . PHP_EOL;
echo 'Categories:          ' . (int) $categoryCount['total'] . PHP_EOL;
echo 'Product rows:        ' . (int) $productCount['total'] . PHP_EOL;
echo 'Duplicate names:     ' . count($duplicateNames) . PHP_EOL;
echo 'Image problems:      ' . $problems . PHP_EOL;

if ($rows !== array()) {
    $first = $rows[0];
    $info = getimagesize(UPLOAD_DIR . DIRECTORY_SEPARATOR . $first['image_path']);
    $thumb = getimagesize(UPLOAD_DIR . DIRECTORY_SEPARATOR . $first['thumbnail_path']);
    echo 'Sample original:     ' . $info[0] . 'x' . $info[1] . ' ' . $info['mime'] . PHP_EOL;
    echo 'Sample thumbnail:    ' . $thumb[0] . 'x' . $thumb[1] . ' ' . $thumb['mime'] . PHP_EOL;
}

echo ($problems === 0 && count($duplicateNames) === 0) ? "SEED OK\n" : "SEED HAS PROBLEMS\n";
