<?php
/**
 * GreenCorner — Sample Data Seed
 *
 * Implements decision D-007 (see config/DECISIONS.md):
 *
 *   - 5 categories: Plants, Seeds, Pots, Tools, Fertilisers.
 *   - 25–30 sample products.
 *   - Valid local product images — each product gets a generated original
 *     and a generated thumbnail, stored as real image files.
 *   - No duplicate records when the script is run again. The seed is
 *     idempotent: it may be run repeatedly with the same result.
 *
 * It also completes the account seed required by FR-6.12 / AC-40 and PRD
 * §8.5: the initial Admin account and a separate test User account, both
 * with the matching role. The credentials are documented in README.md.
 *
 * The script is a development and demonstration aid. It refuses to run in
 * production (SRS §4.4), which is the same rule that applies to the role
 * seed in database/schema.sql.
 *
 * Usage:
 *   php database/seed.php
 *
 * Idempotency:
 *   Categories are matched by name. Products are matched by name within
 *   their category. A product that already exists is updated in place, so
 *   running the script twice leaves exactly one row per product and never
 *   leaves orphaned image files behind.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../helpers/image.php';

if (APP_ENV === 'production') {
    fwrite(STDERR, "Refusing to seed a production database (SRS §4.4).\n");
    exit(1);
}

/**
 * The five sample categories (D-007).
 *
 * @return array<string, array{name: string, description: string}>
 */
function seed_categories(): array
{
    return array(
        'Plants' => array(
            'description' => 'Ready-to-pot houseplants and outdoor favourites.',
        ),
        'Seeds' => array(
            'description' => 'Fresh seeds for herbs, vegetables and flowers.',
        ),
        'Pots' => array(
            'description' => 'Terracotta, ceramic and plastic planters in every size.',
        ),
        'Tools' => array(
            'description' => 'Trowels, pruners and the everyday gardening essentials.',
        ),
        'Fertilisers' => array(
            'description' => 'Feeds and soil boosters for healthier growth.',
        ),
    );
}

/**
 * The sample products, grouped by category (D-007).
 *
 * 28 products in total: 8 Plants, 5 Seeds, 6 Pots, 4 Tools, 5 Fertilisers.
 *
 * The names deliberately include overlapping words, so the FR-2.6 multi-word
 * search behaviour can be exercised against real data ("pot large" matches
 * "Large Terracotta Pot" but not "Small Plastic Pot").
 *
 * @return array<int, array<string, mixed>>
 */
function seed_products(): array
{
    return array(
        // --- Plants (8) ----------------------------------------------------
        array('name' => 'Boston Fern', 'category' => 'Plants', 'price' => '450.00', 'stock' => 14, 'colour' => array(53, 122, 74)),
        array('name' => 'Monstera Deliciosa', 'category' => 'Plants', 'price' => '1250.00', 'stock' => 9, 'colour' => array(46, 104, 62)),
        array('name' => 'Snake Plant', 'category' => 'Plants', 'price' => '780.00', 'stock' => 22, 'colour' => array(70, 118, 60)),
        array('name' => 'Peace Lily', 'category' => 'Plants', 'price' => '920.00', 'stock' => 11, 'colour' => array(58, 108, 66)),
        array('name' => 'Spider Plant', 'category' => 'Plants', 'price' => '380.00', 'stock' => 30, 'colour' => array(96, 148, 78)),
        array('name' => 'Rubber Plant', 'category' => 'Plants', 'price' => '1100.00', 'stock' => 6, 'colour' => array(44, 92, 58)),
        array('name' => 'Aloe Vera Plant', 'category' => 'Plants', 'price' => '540.00', 'stock' => 17, 'colour' => array(104, 142, 74)),
        array('name' => 'Pothos Golden', 'category' => 'Plants', 'price' => '660.00', 'stock' => 13, 'colour' => array(124, 152, 70)),

        // --- Seeds (5) -----------------------------------------------------
        array('name' => 'Basil Seeds Packet', 'category' => 'Seeds', 'price' => '120.00', 'stock' => 80, 'colour' => array(88, 140, 72)),
        array('name' => 'Tomato Seeds Packet', 'category' => 'Seeds', 'price' => '150.00', 'stock' => 65, 'colour' => array(168, 74, 58)),
        array('name' => 'Sunflower Seeds Packet', 'category' => 'Seeds', 'price' => '95.00', 'stock' => 72, 'colour' => array(186, 146, 52)),
        array('name' => 'Mint Seeds Packet', 'category' => 'Seeds', 'price' => '110.00', 'stock' => 58, 'colour' => array(76, 136, 96)),
        array('name' => 'Marigold Seeds Packet', 'category' => 'Seeds', 'price' => '105.00', 'stock' => 44, 'colour' => array(198, 132, 48)),

        // --- Pots (6) ------------------------------------------------------
        array('name' => 'Large Terracotta Pot', 'category' => 'Pots', 'price' => '680.00', 'stock' => 16, 'colour' => array(166, 104, 74)),
        array('name' => 'Small Plastic Pot', 'category' => 'Pots', 'price' => '90.00', 'stock' => 120, 'colour' => array(120, 132, 138)),
        array('name' => 'Large Ceramic Pot', 'category' => 'Pots', 'price' => '1450.00', 'stock' => 8, 'colour' => array(178, 186, 176)),
        array('name' => 'Hanging Basket Pot', 'category' => 'Pots', 'price' => '520.00', 'stock' => 19, 'colour' => array(150, 122, 96)),
        array('name' => 'Square Window Box', 'category' => 'Pots', 'price' => '980.00', 'stock' => 10, 'colour' => array(132, 112, 88)),
        array('name' => 'Mini Fairy Pot', 'category' => 'Pots', 'price' => '210.00', 'stock' => 64, 'colour' => array(186, 148, 130)),

        // --- Tools (4) -----------------------------------------------------
        array('name' => 'Garden Trowel', 'category' => 'Tools', 'price' => '340.00', 'stock' => 26, 'colour' => array(96, 100, 104)),
        array('name' => 'Pruning Shears', 'category' => 'Tools', 'price' => '620.00', 'stock' => 15, 'colour' => array(74, 82, 88)),
        array('name' => 'Watering Can', 'category' => 'Tools', 'price' => '880.00', 'stock' => 12, 'colour' => array(72, 118, 92)),
        array('name' => 'Hand Fork', 'category' => 'Tools', 'price' => '260.00', 'stock' => 33, 'colour' => array(110, 106, 98)),

        // --- Fertilisers (5) ------------------------------------------------
        array('name' => 'Organic Compost', 'category' => 'Fertilisers', 'price' => '450.00', 'stock' => 38, 'colour' => array(96, 72, 52)),
        array('name' => 'Balanced Plant Food', 'category' => 'Fertilisers', 'price' => '380.00', 'stock' => 42, 'colour' => array(84, 116, 74)),
        array('name' => 'Slow Release Granules', 'category' => 'Fertilisers', 'price' => '520.00', 'stock' => 28, 'colour' => array(126, 118, 82)),
        array('name' => 'Leaf Shine Spray', 'category' => 'Fertilisers', 'price' => '290.00', 'stock' => 35, 'colour' => array(90, 140, 110)),
        array('name' => 'Rooting Powder', 'category' => 'Fertilisers', 'price' => '180.00', 'stock' => 51, 'colour' => array(112, 96, 76)),
    );
}

/**
 * The description used for a product.
 *
 * @param string $name
 * @param string $category
 * @return string
 */
function seed_description(string $name, string $category): string
{
    return $name . ' — a dependable choice from our ' . strtolower($category)
        . ' range, selected for healthy growth and easy care in the home or garden.'
        . ' Grown and packed locally, and supplied with practical care notes.';
}

/**
 * Find a category by name, or create it.
 *
 * @param string $name
 * @param string $description
 * @return int The category id.
 */
function seed_upsert_category(string $name, string $description): int
{
    $existing = db_select_one(
        'SELECT `id` FROM `categories` WHERE `name` = ? LIMIT 1',
        's',
        array($name)
    );

    if ($existing !== null) {
        return (int) $existing['id'];
    }

    return db_insert_id(
        'INSERT INTO `categories` (`name`, `description`) VALUES (?, ?)',
        'ss',
        array($name, $description)
    );
}

/**
 * Find a product by name, or report that it is absent.
 *
 * @param string $name
 * @return array<string, mixed>|null
 */
function seed_find_product(string $name): ?array
{
    return db_select_one(
        'SELECT `id`, `image_path`, `thumbnail_path` FROM `products` WHERE `name` = ? LIMIT 1',
        's',
        array($name)
    );
}

/**
 * Whether both image files of a product are present on disk.
 *
 * @param string $imagePath
 * @param string $thumbnailPath
 * @return bool
 */
function seed_images_present(string $imagePath, string $thumbnailPath): bool
{
    if ($imagePath === '' || $thumbnailPath === '') {
        return false;
    }

    $original = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($imagePath);
    $thumbnail = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($thumbnailPath);

    return is_file($original) && is_file($thumbnail);
}

/**
 * Generate the placeholder image for a product.
 *
 * A simple leaf-coloured panel is drawn with the product's initials on it,
 * so every sample product has a real, valid image on disk. The original is
 * 1200×1200 and the thumbnail is the usual 400×400.
 *
 * @param string $name
 * @param array{int, int, int} $colour
 * @return array{original: string, thumbnail: string}
 */
function seed_generate_image(string $name, array $colour): array
{
    $directory = ensure_upload_dir();

    $baseName = 'seed-' . substr(sha1($name), 0, 16);
    $originalName  = $baseName . '.jpg';
    $thumbnailName = $baseName . '-thumb.jpg';

    $originalPath  = $directory . DIRECTORY_SEPARATOR . $originalName;
    $thumbnailPath = $directory . DIRECTORY_SEPARATOR . $thumbnailName;

    // The original.
    $original = imagecreatetruecolor(1200, 1200);
    imagefill($original, 0, 0, imagecolorallocate($original, 248, 250, 246));

    // A soft radial backdrop in the product's colour.
    $steps = 60;
    for ($i = $steps; $i > 0; $i--) {
        $ratio = $i / $steps;
        $r = (int) (247 - (247 - $colour[0]) * $ratio * 0.35);
        $g = (int) (250 - (250 - $colour[1]) * $ratio * 0.35);
        $b = (int) (246 - (246 - $colour[2]) * $ratio * 0.35);
        $shade = imagecolorallocate($original, $r, $g, $b);
        imagefilledellipse($original, 600, 600, (int) (1100 * $ratio), (int) (1100 * $ratio), $shade);
    }

    // A leaf shape.
    $leaf = imagecolorallocate($original, $colour[0], $colour[1], $colour[2]);
    imagefilledellipse($original, 600, 560, 420, 620, $leaf);
    imagefilledellipse($original, 600, 700, 300, 460, $leaf);
    imagefilledellipse($original, 600, 830, 180, 300, $leaf);

    // The midrib.
    $rib = imagecolorallocate($original, (int) ($colour[0] * 0.65), (int) ($colour[1] * 0.65), (int) ($colour[2] * 0.65));
    imagesetthickness($original, 14);
    imageline($original, 600, 380, 600, 940, $rib);

    // The initials.
    $initials = initials_for($name);
    $white = imagecolorallocate($original, 255, 255, 255);
    $fontSize = 5;
    $textWidth = imagefontwidth($fontSize) * strlen($initials);
    imagestring($original, $fontSize, (int) (600 - $textWidth / 2), 1010, $initials, $white);

    imagejpeg($original, $originalPath, 88);
    imagedestroy($original);

    // The thumbnail is generated from the stored original by the same code
    // path the Admin upload uses, so a seeded product and an uploaded one
    // behave identically.
    if (!write_thumbnail_image($originalPath, $thumbnailPath, 'image/jpeg')) {
        @unlink($originalPath);
        throw new RuntimeException('Could not generate the thumbnail for: ' . $name);
    }

    return array('original' => $originalName, 'thumbnail' => $thumbnailName);
}

/**
 * The initials shown on a generated placeholder image.
 *
 * @param string $name
 * @return string
 */
function initials_for(string $name): string
{
    $words = preg_split('/\s+/', trim($name)) ?: array();
    $initials = '';

    foreach ($words as $word) {
        if ($word === '') {
            continue;
        }
        $initials .= strtoupper(substr($word, 0, 1));
        if (strlen($initials) >= 3) {
            break;
        }
    }

    return $initials === '' ? 'GC' : $initials;
}

// ---------------------------------------------------------------------------
// Accounts
// ---------------------------------------------------------------------------

/**
 * The development accounts created by the seed (FR-6.12, AC-40).
 *
 * PRD §8.5: "A seed script creates the initial Admin account, a separate
 * test User account, both roles…". The credentials are documented in the
 * README, and the default Admin password must be changed before real use.
 *
 * @return array<int, array{name: string, email: string, password: string, role: string, full_name: string}>
 */
function seed_accounts(): array
{
    return array(
        array(
            'name'      => 'Admin',
            'email'     => 'admin@greencorner.test',
            'password'  => 'Admin123!',
            'role'      => 'Admin',
            'full_name' => 'GreenCorner Admin',
        ),
        array(
            'name'      => 'Test User',
            'email'     => 'user@greencorner.test',
            'password'  => 'User123!',
            'role'      => 'User',
            'full_name' => 'GreenCorner Test User',
        ),
    );
}

/**
 * Create or update a development account.
 *
 * The account is matched by its email address, so a repeated run updates
 * the existing row instead of creating a duplicate (idempotency, AC-40).
 *
 * The password is stored only as a hash (FR-1.3). The role is resolved
 * from the `roles` table, so the account always carries the right role.
 *
 * @param array<string, string> $account
 * @return string 'created', 'updated' or 'skipped'.
 */
function seed_upsert_account(array $account): string
{
    $role = db_select_one(
        'SELECT `id` FROM `roles` WHERE `name` = ? LIMIT 1',
        's',
        array($account['role'])
    );

    if ($role === null) {
        throw new RuntimeException(
            'The role "' . $account['role'] . '" does not exist. Run database/schema.sql first.'
        );
    }

    $existing = db_select_one(
        'SELECT `id` FROM `users` WHERE `email` = ? LIMIT 1',
        's',
        array($account['email'])
    );

    $passwordHash = password_hash($account['password'], PASSWORD_DEFAULT);

    if ($existing === null) {
        // The `created_at` column is left out so the database applies its
        // CURRENT_TIMESTAMP default, which is what the User model does too.
        db_write(
            'INSERT INTO `users`
                (`full_name`, `email`, `password_hash`, `role_id`, `is_active`, `must_change_password`)
             VALUES (?, ?, ?, ?, 1, 0)',
            'sssi',
            array(
                $account['full_name'],
                $account['email'],
                $passwordHash,
                (int) $role['id'],
            )
        );

        return 'created';
    }

    // An existing account is brought back to the expected state, so the
    // seed is safe to run after a reset.
    db_write(
        'UPDATE `users`
            SET `full_name` = ?, `password_hash` = ?, `role_id` = ?,
                `is_active` = 1, `must_change_password` = 0
          WHERE `id` = ?',
        'sssi',
        array(
            $account['full_name'],
            $passwordHash,
            (int) $role['id'],
            (int) $existing['id'],
        )
    );

    return 'updated';
}

// ---------------------------------------------------------------------------
// Run the seed
// ---------------------------------------------------------------------------

echo "GreenCorner — sample data seed\n";
echo 'Environment: ' . APP_ENV . "\n\n";

echo "Accounts\n";

foreach (seed_accounts() as $account) {
    $result = seed_upsert_account($account);
    $marker = $result === 'created' ? '+' : '=';

    echo '  ' . $marker . ' ' . $account['name']
        . ' (' . $account['role'] . '): ' . $account['email'] . "\n";
}

echo "\n";

$categoryIds = array();
$categories = seed_categories();

foreach ($categories as $name => $info) {
    $categoryIds[$name] = seed_upsert_category($name, $info['description']);
    echo '  category: ' . $name . ' (id ' . $categoryIds[$name] . ")\n";
}

echo "\n";

$created = 0;
$updated = 0;

foreach (seed_products() as $product) {
    $name = (string) $product['name'];
    $categoryName = (string) $product['category'];
    $categoryId = $categoryIds[$categoryName];

    $existing = seed_find_product($name);

    // The images are generated only when the product has none yet, so a
    // repeated run never leaves orphaned files behind.
    if ($existing === null) {
        $images = seed_generate_image($name, $product['colour']);
        $imagePath = $images['original'];
        $thumbnailPath = $images['thumbnail'];
    } else {
        $imagePath = (string) $existing['image_path'];
        $thumbnailPath = (string) $existing['thumbnail_path'];

        // A product whose image files are missing (or whose stored path is
        // unusable) gets fresh images, so the seed always leaves the
        // catalogue in a usable state.
        if (!seed_images_present($imagePath, $thumbnailPath)) {
            $images = seed_generate_image($name, $product['colour']);
            $imagePath = $images['original'];
            $thumbnailPath = $images['thumbnail'];
        }
    }

    $description = seed_description($name, $categoryName);

    if ($existing === null) {
        db_write(
            "INSERT INTO `products`
                (`name`, `description`, `price`, `stock`, `image_path`,
                 `thumbnail_path`, `category_id`, `is_deleted`)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)",
            'sssissi',
            array($name, $description, $product['price'], $product['stock'],
                  $imagePath, $thumbnailPath, $categoryId)
        );
        $created++;
        echo '  + ' . $name . "\n";
    } else {
        db_write(
            "UPDATE `products`
                SET `description` = ?, `price` = ?, `stock` = ?,
                    `image_path` = ?, `thumbnail_path` = ?, `category_id` = ?,
                    `is_deleted` = 0
              WHERE `id` = ?",
            'ssissii',
            array($description, $product['price'], $product['stock'],
                  $imagePath, $thumbnailPath, $categoryId, (int) $existing['id'])
        );
        $updated++;
        echo '  = ' . $name . "\n";
    }
}

$total = db_select_one('SELECT COUNT(*) AS `total` FROM `products`');
$categoryTotal = db_select_one('SELECT COUNT(*) AS `total` FROM `categories`');
$userTotal = db_select_one('SELECT COUNT(*) AS `total` FROM `users`');

echo "\n";
echo 'Categories: ' . (int) $categoryTotal['total'] . "\n";
echo 'Products:   ' . (int) $total['total'] . "\n";
echo 'Users:      ' . (int) $userTotal['total'] . "\n";
echo 'Created:    ' . $created . "\n";
echo 'Updated:    ' . $updated . "\n";
echo "\nSeed complete.\n";
echo "Development credentials are documented in README.md.\n";
