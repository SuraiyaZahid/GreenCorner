<?php
/**
 * GreenCorner — Phase 3 Test Suite
 *
 * Covers the Phase 3 scope: the Admin dashboard (P-14), Admin product
 * management (P-15), Admin category management (P-16), the product image
 * pipeline (FR-2.12, FR-6.4), the soft-delete mechanism (FR-6.5) and the
 * seed accounts (FR-6.12).
 *
 * Usage:
 *   php tests/phase3_tests.php [base-url]
 *
 * The default base URL is http://127.0.0.1:8000.
 *
 * The suite drives the application over HTTP, so the application must be
 * running before the tests are executed. See tests/serve.sh.
 */

require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../helpers/image.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/ProductAdmin.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/User.php';

$baseUrl = $argv[1] ?? 'http://127.0.0.1:8000';
$runner = new TestRunner();

echo "GreenCorner — Phase 3 Test Suite\n";
echo "Base URL: " . $baseUrl . "\n";

// ===========================================================================
// Helpers
// ===========================================================================

/**
 * The development accounts created by the seed (FR-6.12).
 */
const P3_ADMIN_EMAIL = 'admin@greencorner.test';
const P3_ADMIN_PASSWORD = 'Admin123!';
const P3_USER_EMAIL = 'user@greencorner.test';
const P3_USER_PASSWORD = 'User123!';

/**
 * Log a client in as the seeded Admin and return the client.
 *
 * The CSRF token is taken from the login form, so the whole login flow is
 * exercised exactly as a browser would perform it.
 */
function p3_admin_client(string $baseUrl): HttpClient
{
    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => P3_ADMIN_EMAIL,
        'password'   => P3_ADMIN_PASSWORD,
    ));

    return $client;
}

/**
 * Log a client in as the seeded User and return the client.
 */
function p3_user_client(string $baseUrl): HttpClient
{
    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => P3_USER_EMAIL,
        'password'   => P3_USER_PASSWORD,
    ));

    return $client;
}

/**
 * The CSRF token on the Admin product list page.
 */
function p3_admin_csrf(HttpClient $client): string
{
    $client->get('/admin/products');

    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $client->body, $m)) {
        return $m[1];
    }

    return '';
}

/**
 * Write a genuine JPEG file to a temporary path and return the path.
 *
 * @param int $width
 * @param int $height
 * @param array{0:int,1:int,2:int} $colour
 * @return string
 */
function p3_make_jpeg(int $width = 600, int $height = 400, array $colour = array(60, 130, 70)): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, $colour[0], $colour[1], $colour[2]));

    $path = tempnam(sys_get_temp_dir(), 'gcimg');
    imagejpeg($image, $path, 90);
    imagedestroy($image);

    return $path;
}

/**
 * Write a genuine PNG file to a temporary path and return the path.
 */
function p3_make_png(int $width = 300, int $height = 300): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 120, 90, 60));

    $path = tempnam(sys_get_temp_dir(), 'gcimg');
    imagepng($image, $path);
    imagedestroy($image);

    return $path;
}

/**
 * The id of the first category.
 */
function p3_first_category_id(): int
{
    $row = db_select_one('SELECT `id` FROM `categories` ORDER BY `id` ASC LIMIT 1');

    return (int) ($row['id'] ?? 0);
}

/**
 * The id of the category the suite creates for itself.
 *
 * The suite never writes to a seeded category: renaming or removing one
 * would change the catalogue the Phase 2 tests assert on. The suite's own
 * category is created on demand and removed by p3_cleanup().
 *
 * @return int
 */
function p3_test_category_id(): int
{
    static $categoryId = null;

    if ($categoryId !== null) {
        return $categoryId;
    }

    $name = 'P3 Test Category';

    $row = db_select_one('SELECT `id` FROM `categories` WHERE `name` = ? LIMIT 1', 's', array($name));

    if ($row === null) {
        db_write(
            'INSERT INTO `categories` (`name`, `description`) VALUES (?, ?)',
            'ss',
            array($name, 'Created by the Phase 3 test suite.')
        );

        $row = db_select_one('SELECT `id` FROM `categories` WHERE `name` = ? LIMIT 1', 's', array($name));
    }

    $categoryId = (int) ($row['id'] ?? 0);

    return $categoryId;
}

/**
 * Create a product that belongs to the suite, and return its id.
 *
 * The product is created through the application's own endpoint, so the
 * fixture is a genuine product with a genuine uploaded image, exactly as a
 * real one would be. It is named with the "P3 " prefix so p3_cleanup() can
 * remove it.
 *
 * Every state-changing test uses a product of its own, so a seeded product
 * is never renamed, soft-deleted or has its image replaced.
 *
 * @param HttpClient $client    An Admin client.
 * @param string     $name      The product name.
 * @param bool       $withImage Whether the product should have an image file.
 * @return int The new product's id, or 0 when it could not be created.
 */
function p3_create_product(HttpClient $client, string $name, bool $withImage = true): int
{
    $categoryId = p3_test_category_id();

    if ($categoryId <= 0) {
        return 0;
    }

    $fields = array(
        'name'        => $name,
        'description' => 'Created by the Phase 3 test suite.',
        'price'       => '5.00',
        'stock'       => '3',
        'category_id' => (string) $categoryId,
    );

    $files = array();

    if ($withImage) {
        $source = p3_make_jpeg(600, 400, array(70, 140, 80));

        $fields['csrf_token'] = p3_admin_csrf($client);

        $client->postMultipart('/admin/products', $fields, array(
            'image' => array(
                'path' => $source,
                'name' => 'p3-fixture.jpg',
                'type' => 'image/jpeg',
            ),
        ));

        @unlink($source);
    } else {
        $fields['csrf_token'] = p3_admin_csrf($client);

        $client->post('/admin/products', $fields);
    }

    $row = db_select_one('SELECT `id` FROM `products` WHERE `name` = ? LIMIT 1', 's', array($name));

    return (int) ($row['id'] ?? 0);
}

/**
 * The number of product files in the upload directory.
 */
function p3_upload_file_count(): int
{
    return count(glob(UPLOAD_DIR . DIRECTORY_SEPARATOR . 'product-*') ?: array());
}

/**
 * Remove a product and its image files, so a test starts from a clean slate.
 */
function p3_delete_product(int $id): void
{
    $row = db_select_one(
        'SELECT `image_path`, `thumbnail_path` FROM `products` WHERE `id` = ? LIMIT 1',
        'i',
        array($id)
    );

    if ($row !== null) {
        db_write('DELETE FROM `products` WHERE `id` = ?', 'i', array($id));
        delete_stored_images((string) $row['image_path'], (string) $row['thumbnail_path']);
    }
}

/**
 * Remove everything the suite created, so the catalogue is left exactly as
 * the seed left it.
 *
 * The Phase 2 tests assert on the seeded sample data — exactly five
 * categories, between 25 and 30 products, and a product called
 * "Boston Fern". A Phase 3 test that leaves a product or a category behind
 * would change those counts and break the Phase 2 suite, so the Phase 3
 * suite cleans up after itself.
 *
 * Every record the suite creates is named with the "P3 " prefix, so the
 * cleanup can never touch a seeded record.
 */
function p3_cleanup(): void
{
    // The products first: a category cannot be removed while products still
    // reference it.
    $products = db_select(
        "SELECT `id`, `image_path`, `thumbnail_path`
           FROM `products`
          WHERE `name` LIKE 'P3 %'
             OR `name` LIKE '<script>%'"
    );

    foreach ($products as $product) {
        db_write('DELETE FROM `products` WHERE `id` = ?', 'i', array((int) $product['id']));
        delete_stored_images(
            (string) $product['image_path'],
            (string) $product['thumbnail_path']
        );
    }

    db_write("DELETE FROM `categories` WHERE `name` LIKE 'P3 %'", '', array());
}

// ===========================================================================
$runner->group('1. Seed accounts (FR-6.12, AC-40)');
// ===========================================================================

$runner->test('The seed creates the Admin account', function () {
    $row = db_select_one(
        "SELECT u.`id`, u.`full_name`, u.`email`, u.`is_active`, r.`name` AS `role_name`
           FROM `users` u
           JOIN `roles` r ON r.`id` = u.`role_id`
          WHERE u.`email` = ? LIMIT 1",
        's',
        array(P3_ADMIN_EMAIL)
    );

    assert_true($row !== null, 'The seeded Admin account does not exist');
    assert_equals('Admin', (string) $row['role_name'], 'The seeded Admin has the wrong role');
    assert_equals(1, (int) $row['is_active'], 'The seeded Admin is not active');
});

$runner->test('The seed creates the test User account', function () {
    $row = db_select_one(
        "SELECT u.`id`, u.`full_name`, u.`email`, u.`is_active`, r.`name` AS `role_name`
           FROM `users` u
           JOIN `roles` r ON r.`id` = u.`role_id`
          WHERE u.`email` = ? LIMIT 1",
        's',
        array(P3_USER_EMAIL)
    );

    assert_true($row !== null, 'The seeded test User account does not exist');
    assert_equals('User', (string) $row['role_name'], 'The seeded test User has the wrong role');
    assert_equals(1, (int) $row['is_active'], 'The seeded test User is not active');
});

$runner->test('The seeded accounts store only a password hash', function () {
    $row = db_select_one(
        'SELECT `password_hash` FROM `users` WHERE `email` = ? LIMIT 1',
        's',
        array(P3_ADMIN_EMAIL)
    );

    assert_true($row !== null, 'The seeded Admin account does not exist');

    $hash = (string) $row['password_hash'];

    assert_true($hash !== P3_ADMIN_PASSWORD, 'The password is stored in plain text');
    assert_true(password_verify(P3_ADMIN_PASSWORD, $hash), 'The stored hash does not verify the password');
    assert_true(strlen($hash) >= 60, 'The stored hash is too short to be a bcrypt hash');
});

$runner->test('The seeded Admin can log in and lands on the Admin Dashboard', function () use ($baseUrl) {
    $client = p3_admin_client($baseUrl);

    $response = $client->get('/admin/dashboard', true);

    assert_equals(200, $response['status'], 'The Admin could not reach the Admin Dashboard');
    assert_contains('Admin dashboard', $response['body'], 'The Admin Dashboard was not rendered');
});

$runner->test('The seeded test User cannot reach the Admin Dashboard', function () use ($baseUrl) {
    $client = p3_user_client($baseUrl);

    $response = $client->get('/admin/dashboard');

    assert_equals(403, $response['status'], 'A User was allowed to reach the Admin Dashboard');
});

$runner->test('The development credentials are documented in the README', function () {
    $readme = (string) file_get_contents(__DIR__ . '/../README.md');

    assert_contains(P3_ADMIN_EMAIL, $readme, 'The Admin email is not documented');
    assert_contains(P3_USER_EMAIL, $readme, 'The test User email is not documented');
    assert_contains(P3_ADMIN_PASSWORD, $readme, 'The Admin password is not documented');
    assert_contains(P3_USER_PASSWORD, $readme, 'The test User password is not documented');
});

// ===========================================================================
$runner->group('2. Admin dashboard (FR-6.1, P-14)');
// ===========================================================================

$runner->test('The dashboard shows the product, order and user counts', function () use ($baseUrl) {
    $client = p3_admin_client($baseUrl);
    $response = $client->get('/admin/dashboard');

    assert_equals(200, $response['status'], 'The Admin Dashboard was not returned');

    assert_contains('id="stat-products"', $response['body'], 'The product count is missing');
    assert_contains('id="stat-orders"', $response['body'], 'The order count is missing');
    assert_contains('id="stat-users"', $response['body'], 'The user count is missing');
});

$runner->test('The product count excludes soft-deleted products', function () use ($baseUrl) {
    $active = ProductAdmin::countActive();
    $deleted = ProductAdmin::countDeleted();

    $client = p3_admin_client($baseUrl);
    $response = $client->get('/admin/dashboard');

    assert_contains(
        'id="stat-products">' . $active . '<',
        $response['body'],
        'The dashboard product count does not exclude soft-deleted products'
    );
    assert_contains(
        (string) $deleted . ' soft-deleted',
        $response['body'],
        'The dashboard does not report the soft-deleted count'
    );
});

$runner->test('The dashboard user count matches the database', function () use ($baseUrl) {
    $expected = (int) (db_select_one('SELECT COUNT(*) AS `total` FROM `users`')['total'] ?? 0);

    $client = p3_admin_client($baseUrl);
    $response = $client->get('/admin/dashboard');

    assert_contains(
        'id="stat-users">' . $expected . '<',
        $response['body'],
        'The dashboard user count does not match the database'
    );
});

$runner->test('An anonymous visitor is redirected to login', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/admin/dashboard');

    assert_equals(401, $response['status'], 'An anonymous visitor was not refused with 401');
    assert_contains('/auth/login', $response['headers']['location'] ?? '', 'The visitor was not sent to login');
});

// ===========================================================================
$runner->group('3. Product creation (FR-6.2, FR-2.12, P-15)');
// ===========================================================================

$runner->test('A product is created with a genuine image upload', function () use ($baseUrl) {
    $categoryId = p3_first_category_id();
    $imagePath = p3_make_jpeg(600, 400);
    $name = 'P3 Test Product ' . bin2hex(random_bytes(4));

    try {
        $client = p3_admin_client($baseUrl);
        $token = p3_admin_csrf($client);

        $response = $client->postMultipart('/admin/products', array(
            'csrf_token'  => $token,
            'name'        => $name,
            'description' => 'Created by the Phase 3 test suite.',
            'price'       => '19.99',
            'stock'       => '7',
            'category_id' => (string) $categoryId,
        ), array(
            'image' => array(
                'path' => $imagePath,
                'name' => 'p3-product.jpg',
                'type' => 'image/jpeg',
            ),
        ));

        assert_equals(302, $response['status'], 'The product was not created: ' . $response['body']);

        $row = db_select_one(
            'SELECT `id`, `name`, `description`, `price`, `stock`, `image_path`, `thumbnail_path`, `category_id`, `is_deleted`
               FROM `products` WHERE `name` = ? LIMIT 1',
            's',
            array($name)
        );

        assert_true($row !== null, 'The product was not stored');
        assert_equals('19.99', (string) $row['price'], 'The price was stored incorrectly');
        assert_equals(7, (int) $row['stock'], 'The stock was stored incorrectly');
        assert_equals($categoryId, (int) $row['category_id'], 'The category was stored incorrectly');
        assert_equals(0, (int) $row['is_deleted'], 'A new product must not be soft-deleted');

        // Both the original and the generated thumbnail are stored (FR-6.4).
        $original = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string) $row['image_path']);
        $thumbnail = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string) $row['thumbnail_path']);

        assert_true(is_file($original), 'The original image was not stored');
        assert_true(is_file($thumbnail), 'The thumbnail was not stored');

        $size = @getimagesize($thumbnail);
        assert_true($size !== false, 'The thumbnail could not be read');
        assert_equals(THUMBNAIL_SIZE, (int) $size[0], 'The thumbnail width is wrong');
        assert_equals(THUMBNAIL_SIZE, (int) $size[1], 'The thumbnail height is wrong');

        p3_delete_product((int) $row['id']);
    } finally {
        @unlink($imagePath);
    }
});

$runner->test('A new product appears in the storefront immediately', function () use ($baseUrl) {
    $categoryId = p3_first_category_id();
    $imagePath = p3_make_jpeg();
    $name = 'P3 Storefront Product ' . bin2hex(random_bytes(4));

    try {
        $client = p3_admin_client($baseUrl);
        $token = p3_admin_csrf($client);

        $client->postMultipart('/admin/products', array(
            'csrf_token'  => $token,
            'name'        => $name,
            'description' => 'Appears in the shop.',
            'price'       => '5.00',
            'stock'       => '3',
            'category_id' => (string) $categoryId,
        ), array(
            'image' => array(
                'path' => $imagePath,
                'name' => 'p3-storefront.jpg',
                'type' => 'image/jpeg',
            ),
        ));

        $row = db_select_one('SELECT `id` FROM `products` WHERE `name` = ? LIMIT 1', 's', array($name));
        assert_true($row !== null, 'The product was not created');

        $shopper = new HttpClient($baseUrl);
        $listing = $shopper->get('/products');
        assert_contains($name, $listing['body'], 'The new product is not in the storefront');

        $detail = $shopper->get('/products/' . (int) $row['id']);
        assert_equals(200, $detail['status'], 'The new product detail page is not reachable');

        p3_delete_product((int) $row['id']);
    } finally {
        @unlink($imagePath);
    }
});

$runner->test('The image is mandatory when creating a product', function () use ($baseUrl) {
    $categoryId = p3_first_category_id();

    $client = p3_admin_client($baseUrl);
    $token = p3_admin_csrf($client);

    $response = $client->post('/admin/products', array(
        'csrf_token'  => $token,
        'name'        => 'P3 No Image Product',
        'description' => 'This should be refused.',
        'price'       => '3.00',
        'stock'       => '1',
        'category_id' => (string) $categoryId,
    ));

    assert_equals(422, $response['status'], 'A product without an image was accepted');
    assert_contains('image', strtolower($response['body']), 'The missing image was not reported');

    $row = db_select_one('SELECT `id` FROM `products` WHERE `name` = ? LIMIT 1', 's', array('P3 No Image Product'));
    assert_true($row === null, 'A product was stored without an image');
});

$runner->test('An invalid price is refused', function () use ($baseUrl) {
    $categoryId = p3_first_category_id();
    $imagePath = p3_make_jpeg();

    try {
        $client = p3_admin_client($baseUrl);
        $token = p3_admin_csrf($client);

        $response = $client->postMultipart('/admin/products', array(
            'csrf_token'  => $token,
            'name'        => 'P3 Bad Price ' . bin2hex(random_bytes(4)),
            'description' => 'Bad price.',
            'price'       => 'twelve pounds',
            'stock'       => '1',
            'category_id' => (string) $categoryId,
        ), array(
            'image' => array(
                'path' => $imagePath,
                'name' => 'p3-badprice.jpg',
                'type' => 'image/jpeg',
            ),
        ));

        assert_equals(422, $response['status'], 'An invalid price was accepted');
        assert_contains('price', strtolower($response['body']), 'The invalid price was not reported');
    } finally {
        @unlink($imagePath);
    }
});

$runner->test('A missing name and a missing category are refused', function () use ($baseUrl) {
    $imagePath = p3_make_jpeg();

    try {
        $client = p3_admin_client($baseUrl);
        $token = p3_admin_csrf($client);

        $response = $client->postMultipart('/admin/products', array(
            'csrf_token'  => $token,
            'name'        => '',
            'description' => 'No name, no category.',
            'price'       => '1.00',
            'stock'       => '1',
            'category_id' => '',
        ), array(
            'image' => array(
                'path' => $imagePath,
                'name' => 'p3-nofields.jpg',
                'type' => 'image/jpeg',
            ),
        ));

        assert_equals(422, $response['status'], 'A product with no name or category was accepted');
        assert_contains('name', strtolower($response['body']), 'The missing name was not reported');
        assert_contains('category', strtolower($response['body']), 'The missing category was not reported');
    } finally {
        @unlink($imagePath);
    }
});

// ===========================================================================
$runner->group('4. Image validation (FR-2.12, SEC-3, AC-25)');
// ===========================================================================

$runner->test('A text file renamed to .jpg is rejected', function () use ($baseUrl) {
    $categoryId = p3_first_category_id();
    $temporary = tempnam(sys_get_temp_dir(), 'gc');
    file_put_contents($temporary, 'This is not an image. It is plain text.');

    $name = 'P3 Fake Image ' . bin2hex(random_bytes(4));

    try {
        $client = p3_admin_client($baseUrl);
        $token = p3_admin_csrf($client);

        $response = $client->postMultipart('/admin/products', array(
            'csrf_token'  => $token,
            'name'        => $name,
            'description' => 'Fake image.',
            'price'       => '2.00',
            'stock'       => '1',
            'category_id' => (string) $categoryId,
        ), array(
            'image' => array(
                'path' => $temporary,
                'name' => 'fake.jpg',
                'type' => 'image/jpeg',
            ),
        ));

        assert_equals(422, $response['status'], 'A text file was accepted as an image');
        assert_contains('not a valid', strtolower($response['body']), 'The rejection was not explained');

        $row = db_select_one('SELECT `id` FROM `products` WHERE `name` = ? LIMIT 1', 's', array($name));
        assert_true($row === null, 'A product was stored with a fake image');
    } finally {
        @unlink($temporary);
    }
});

$runner->test('A disallowed file extension is rejected', function () use ($baseUrl) {
    $categoryId = p3_first_category_id();
    $temporary = tempnam(sys_get_temp_dir(), 'gc');
    file_put_contents($temporary, 'plain text');

    try {
        $client = p3_admin_client($baseUrl);
        $token = p3_admin_csrf($client);

        $response = $client->postMultipart('/admin/products', array(
            'csrf_token'  => $token,
            'name'        => 'P3 SVG Upload ' . bin2hex(random_bytes(4)),
            'description' => 'SVG upload.',
            'price'       => '2.00',
            'stock'       => '1',
            'category_id' => (string) $categoryId,
        ), array(
            'image' => array(
                'path' => $temporary,
                'name' => 'fake.svg',
                'type' => 'image/svg+xml',
            ),
        ));

        assert_equals(422, $response['status'], 'A disallowed file extension was accepted');
    } finally {
        @unlink($temporary);
    }
});

$runner->test('A genuine PNG upload is accepted', function () use ($baseUrl) {
    $categoryId = p3_first_category_id();
    $imagePath = p3_make_png();
    $name = 'P3 PNG Product ' . bin2hex(random_bytes(4));

    try {
        $client = p3_admin_client($baseUrl);
        $token = p3_admin_csrf($client);

        $response = $client->postMultipart('/admin/products', array(
            'csrf_token'  => $token,
            'name'        => $name,
            'description' => 'A PNG product.',
            'price'       => '4.50',
            'stock'       => '2',
            'category_id' => (string) $categoryId,
        ), array(
            'image' => array(
                'path' => $imagePath,
                'name' => 'p3-product.png',
                'type' => 'image/png',
            ),
        ));

        assert_equals(302, $response['status'], 'A genuine PNG was not accepted: ' . $response['body']);

        $row = db_select_one(
            'SELECT `id`, `image_path` FROM `products` WHERE `name` = ? LIMIT 1',
            's',
            array($name)
        );

        assert_true($row !== null, 'The PNG product was not stored');
        assert_contains(
            '.png',
            (string) $row['image_path'],
            'The PNG was not stored under a .png extension'
        );

        p3_delete_product((int) $row['id']);
    } finally {
        @unlink($imagePath);
    }
});

$runner->test('An image larger than 2 MB is rejected', function () use ($baseUrl) {
    $categoryId = p3_first_category_id();

    // A real JPEG that is larger than the 2 MB limit, but still small enough
    // to fit inside PHP's post_max_size, so the request itself reaches the
    // application and is refused by the validator.
    $width = 1500;
    $height = 1500;
    $image = imagecreatetruecolor($width, $height);

    // Random noise does not compress, so the file is comfortably over 2 MB.
    for ($x = 0; $x < $width; $x += 4) {
        for ($y = 0; $y < $height; $y += 4) {
            imagesetpixel($image, $x, $y, imagecolorallocate(
                $image,
                random_int(0, 255),
                random_int(0, 255),
                random_int(0, 255)
            ));
        }
    }

    $imagePath = tempnam(sys_get_temp_dir(), 'gcbig');
    imagejpeg($image, $imagePath, 100);
    imagedestroy($image);

    try {
        $size = filesize($imagePath);
        assert_true($size > MAX_UPLOAD_BYTES, 'The test image is not over the limit (' . $size . ' bytes)');

        $client = p3_admin_client($baseUrl);
        $token = p3_admin_csrf($client);

        $response = $client->postMultipart('/admin/products', array(
            'csrf_token'  => $token,
            'name'        => 'P3 Oversized ' . bin2hex(random_bytes(4)),
            'description' => 'Oversized image.',
            'price'       => '2.00',
            'stock'       => '1',
            'category_id' => (string) $categoryId,
        ), array(
            'image' => array(
                'path' => $imagePath,
                'name' => 'big.jpg',
                'type' => 'image/jpeg',
            ),
        ));

        assert_equals(422, $response['status'], 'An oversized image was accepted');
        assert_contains('2 mb', strtolower($response['body']), 'The size limit was not explained');
    } finally {
        @unlink($imagePath);
    }
});

$runner->test('A request whose body exceeds PHP post_max_size is refused', function () use ($baseUrl) {
    $categoryId = p3_first_category_id();

    // A file larger than PHP's post_max_size makes PHP discard the whole
    // request body. The application must still refuse the request clearly
    // rather than re-render an empty form as though nothing had arrived.
    $width = 2600;
    $height = 2600;
    $image = imagecreatetruecolor($width, $height);

    for ($x = 0; $x < $width; $x += 4) {
        for ($y = 0; $y < $height; $y += 4) {
            imagesetpixel($image, $x, $y, imagecolorallocate(
                $image,
                random_int(0, 255),
                random_int(0, 255),
                random_int(0, 255)
            ));
        }
    }

    $imagePath = tempnam(sys_get_temp_dir(), 'gchuge');
    imagejpeg($image, $imagePath, 100);
    imagedestroy($image);

    try {
        $size = filesize($imagePath);
        assert_true(
            $size > (int) ini_get('post_max_size'),
            'The test image is not over post_max_size (' . $size . ' bytes)'
        );

        $client = p3_admin_client($baseUrl);
        $token = p3_admin_csrf($client);

        $response = $client->postMultipart('/admin/products', array(
            'csrf_token'  => $token,
            'name'        => 'P3 Huge ' . bin2hex(random_bytes(4)),
            'description' => 'Huge image.',
            'price'       => '2.00',
            'stock'       => '1',
            'category_id' => (string) $categoryId,
        ), array(
            'image' => array(
                'path' => $imagePath,
                'name' => 'huge.jpg',
                'type' => 'image/jpeg',
            ),
        ));

        assert_equals(422, $response['status'], 'An over-post_max_size upload was not refused with 422');
        assert_contains('too large', strtolower($response['body']), 'The oversized request was not explained');

        $row = db_select_one(
            'SELECT `id` FROM `products` WHERE `name` LIKE ? LIMIT 1',
            's',
            array('P3 Huge %')
        );
        assert_true($row === null, 'A product was stored from a discarded request body');
    } finally {
        @unlink($imagePath);
    }
});

$runner->test('A rejected upload leaves no files behind', function () use ($baseUrl) {
    $categoryId = p3_first_category_id();
    $before = p3_upload_file_count();

    $temporary = tempnam(sys_get_temp_dir(), 'gc');
    file_put_contents($temporary, 'not an image');

    try {
        $client = p3_admin_client($baseUrl);
        $token = p3_admin_csrf($client);

        $client->postMultipart('/admin/products', array(
            'csrf_token'  => $token,
            'name'        => 'P3 No Files ' . bin2hex(random_bytes(4)),
            'description' => 'Rejected upload.',
            'price'       => '2.00',
            'stock'       => '1',
            'category_id' => (string) $categoryId,
        ), array(
            'image' => array(
                'path' => $temporary,
                'name' => 'fake.jpg',
                'type' => 'image/jpeg',
            ),
        ));

        $after = p3_upload_file_count();

        assert_equals($before, $after, 'A rejected upload left files behind');
    } finally {
        @unlink($temporary);
    }
});

$runner->test('The stored images are served over HTTP with an image content type', function () use ($baseUrl) {
    $row = db_select_one(
        'SELECT `image_path`, `thumbnail_path` FROM `products` WHERE `is_deleted` = 0 AND `image_path` <> \'\' LIMIT 1'
    );

    assert_true($row !== null, 'There is no product image to check');

    $client = new HttpClient($baseUrl);
    $original = $client->get('/uploads/' . rawurlencode((string) $row['image_path']));
    $thumbnail = $client->get('/uploads/' . rawurlencode((string) $row['thumbnail_path']));

    assert_equals(200, $original['status'], 'The stored original is not served');
    assert_equals(200, $thumbnail['status'], 'The stored thumbnail is not served');
    assert_contains('image/', $original['headers']['content-type'] ?? '', 'The original content type is wrong');
});

// ===========================================================================
$runner->group('5. Product update (FR-6.3, FR-6.4)');
// ===========================================================================

$runner->test('A product field can be updated', function () use ($baseUrl) {
    $name = 'P3 Field Update ' . bin2hex(random_bytes(4));

    $client = p3_admin_client($baseUrl);
    $productId = p3_create_product($client, $name);

    assert_true($productId > 0, 'The product was not created');

    $token = p3_admin_csrf($client);

    $response = $client->post('/admin/products/' . $productId . '/update', array(
        'csrf_token'  => $token,
        'name'        => $name,
        'description' => 'Updated by the Phase 3 test suite.',
        'price'       => '31.25',
        'stock'       => '42',
        'category_id' => (string) p3_test_category_id(),
    ));

    assert_equals(302, $response['status'], 'The update was refused: ' . $response['body']);

    $updated = db_select_one(
        'SELECT `description`, `price`, `stock` FROM `products` WHERE `id` = ? LIMIT 1',
        'i',
        array($productId)
    );

    assert_true($updated !== null, 'The product disappeared');
    assert_equals('31.25', (string) $updated['price'], 'The price was not updated');
    assert_equals(42, (int) $updated['stock'], 'The stock was not updated');
    assert_contains('Updated by the Phase 3 test suite.', (string) $updated['description'], 'The description was not updated');
});

$runner->test('The image is optional on update and the existing image is kept', function () use ($baseUrl) {
    $name = 'P3 Keep Image ' . bin2hex(random_bytes(4));

    $client = p3_admin_client($baseUrl);
    $productId = p3_create_product($client, $name);

    assert_true($productId > 0, 'The product was not created');

    $row = db_select_one(
        'SELECT `image_path`, `thumbnail_path` FROM `products` WHERE `id` = ? LIMIT 1',
        'i',
        array($productId)
    );

    assert_true($row !== null, 'The product disappeared');

    $token = p3_admin_csrf($client);

    $response = $client->post('/admin/products/' . $productId . '/update', array(
        'csrf_token'  => $token,
        'name'        => $name,
        'description' => 'Updated without a new image.',
        'price'       => '8.00',
        'stock'       => '2',
        'category_id' => (string) p3_test_category_id(),
    ));

    assert_equals(302, $response['status'], 'The update without an image was refused');

    $updated = db_select_one(
        'SELECT `image_path`, `thumbnail_path` FROM `products` WHERE `id` = ? LIMIT 1',
        'i',
        array($productId)
    );

    assert_true($updated !== null, 'The product disappeared');
    assert_equals(
        (string) $row['image_path'],
        (string) $updated['image_path'],
        'The original image path changed without a new upload'
    );
    assert_equals(
        (string) $row['thumbnail_path'],
        (string) $updated['thumbnail_path'],
        'The thumbnail path changed without a new upload'
    );

    // The files themselves are still on disk.
    assert_true(
        is_file(UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string) $updated['image_path'])),
        'The original image file was deleted on an update without a new image'
    );
});

$runner->test('A new image replaces the old one, and the old files are deleted afterwards', function () use ($baseUrl) {
    $name = 'P3 Replace Image ' . bin2hex(random_bytes(4));

    $client = p3_admin_client($baseUrl);
    $productId = p3_create_product($client, $name);

    assert_true($productId > 0, 'The product was not created');

    $row = db_select_one(
        'SELECT `image_path`, `thumbnail_path` FROM `products` WHERE `id` = ? LIMIT 1',
        'i',
        array($productId)
    );

    $oldOriginal = (string) $row['image_path'];
    $oldOriginalPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($oldOriginal);

    $imagePath = p3_make_jpeg(800, 800, array(200, 90, 60));

    try {
        $token = p3_admin_csrf($client);

        $response = $client->postMultipart('/admin/products/' . $productId . '/update', array(
            'csrf_token'  => $token,
            'name'        => $name,
            'description' => 'Replaced the image.',
            'price'       => '9.00',
            'stock'       => '2',
            'category_id' => (string) p3_test_category_id(),
        ), array(
            'image' => array(
                'path' => $imagePath,
                'name' => 'p3-replacement.jpg',
                'type' => 'image/jpeg',
            ),
        ));

        assert_equals(302, $response['status'], 'The update with a new image was refused: ' . $response['body']);

        $updated = db_select_one(
            'SELECT `image_path`, `thumbnail_path` FROM `products` WHERE `id` = ? LIMIT 1',
            'i',
            array($productId)
        );

        assert_true($updated !== null, 'The product disappeared');
        assert_true(
            (string) $updated['image_path'] !== $oldOriginal,
            'The image path did not change after a new upload'
        );

        // The new files exist.
        assert_true(
            is_file(UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string) $updated['image_path'])),
            'The new original was not stored'
        );
        assert_true(
            is_file(UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string) $updated['thumbnail_path'])),
            'The new thumbnail was not stored'
        );

        // The previous files are deleted only AFTER the new ones are stored.
        assert_false(is_file($oldOriginalPath), 'The previous original was not deleted');
    } finally {
        @unlink($imagePath);
    }
});

$runner->test('The edit form shows the current product values', function () use ($baseUrl) {
    $row = db_select_one('SELECT `id`, `name` FROM `products` WHERE `is_deleted` = 0 ORDER BY `id` ASC LIMIT 1');
    assert_true($row !== null, 'There is no product to edit');

    $client = p3_admin_client($baseUrl);
    $response = $client->get('/admin/products/' . (int) $row['id'] . '/edit');

    assert_equals(200, $response['status'], 'The edit form was not returned');
    assert_contains('name="name"', $response['body'], 'The edit form has no name field');
    assert_contains('name="price"', $response['body'], 'The edit form has no price field');
    assert_contains('name="stock"', $response['body'], 'The edit form has no stock field');
    assert_contains('name="image"', $response['body'], 'The edit form has no image field');
});

$runner->test('The edit form for a missing product returns 404', function () use ($baseUrl) {
    $client = p3_admin_client($baseUrl);
    $response = $client->get('/admin/products/99999999/edit');

    assert_equals(404, $response['status'], 'A missing product did not return 404');
});

// ===========================================================================
$runner->group('6. Soft delete (FR-6.5, FR-2.8, AC-26)');
// ===========================================================================

$runner->test('Removing a product is a soft delete: the row is retained', function () use ($baseUrl) {
    $name = 'P3 Soft Delete ' . bin2hex(random_bytes(4));

    $client = p3_admin_client($baseUrl);
    $productId = p3_create_product($client, $name);

    assert_true($productId > 0, 'The product was not created');

    $token = p3_admin_csrf($client);
    $response = $client->post('/admin/products/' . $productId . '/delete', array(
        'csrf_token' => $token,
    ));

    assert_equals(302, $response['status'], 'The delete was refused');

    $after = db_select_one(
        'SELECT `id`, `is_deleted` FROM `products` WHERE `id` = ? LIMIT 1',
        'i',
        array($productId)
    );

    assert_true($after !== null, 'The product row was hard-deleted — it must only be soft-deleted');
    assert_equals(1, (int) $after['is_deleted'], 'The product was not marked as deleted');
});

$runner->test('A soft-deleted product is excluded from the storefront listing', function () use ($baseUrl) {
    $name = 'P3 Listing Excluded ' . bin2hex(random_bytes(4));

    $client = p3_admin_client($baseUrl);
    $productId = p3_create_product($client, $name);

    assert_true($productId > 0, 'The product was not created');

    $token = p3_admin_csrf($client);
    $client->post('/admin/products/' . $productId . '/delete', array('csrf_token' => $token));

    $shopper = new HttpClient($baseUrl);
    $listing = $shopper->get('/products');

    assert_not_contains(
        'class="product-name">' . $name . '<',
        $listing['body'],
        'A soft-deleted product is still shown in the storefront listing'
    );
});

$runner->test('A soft-deleted product is excluded from search', function () use ($baseUrl) {
    $name = 'P3 Search Excluded ' . bin2hex(random_bytes(4));

    $client = p3_admin_client($baseUrl);
    $productId = p3_create_product($client, $name);

    assert_true($productId > 0, 'The product was not created');

    $token = p3_admin_csrf($client);
    $client->post('/admin/products/' . $productId . '/delete', array('csrf_token' => $token));

    $shopper = new HttpClient($baseUrl);
    $search = $shopper->get('/products?search=' . rawurlencode($name));

    assert_not_contains(
        'class="product-name">' . $name . '<',
        $search['body'],
        'A soft-deleted product is still returned by the search'
    );
});

$runner->test('A soft-deleted product detail page returns 404', function () use ($baseUrl) {
    $name = 'P3 Detail 404 ' . bin2hex(random_bytes(4));

    $client = p3_admin_client($baseUrl);
    $productId = p3_create_product($client, $name);

    assert_true($productId > 0, 'The product was not created');

    $token = p3_admin_csrf($client);
    $client->post('/admin/products/' . $productId . '/delete', array('csrf_token' => $token));

    $shopper = new HttpClient($baseUrl);
    $detail = $shopper->get('/products/' . $productId);

    assert_equals(404, $detail['status'], 'A soft-deleted product did not return 404');
});

$runner->test('The Admin list shows a soft-deleted product and marks it', function () use ($baseUrl) {
    $name = 'P3 Admin Marked ' . bin2hex(random_bytes(4));

    $client = p3_admin_client($baseUrl);
    $productId = p3_create_product($client, $name);

    assert_true($productId > 0, 'The product was not created');

    $token = p3_admin_csrf($client);
    $client->post('/admin/products/' . $productId . '/delete', array('csrf_token' => $token));

    $response = $client->get('/admin/products');

    assert_equals(200, $response['status'], 'The Admin product list was not returned');
    assert_contains($name, $response['body'], 'The soft-deleted product is missing from the Admin list');
    assert_contains('badge-deleted', $response['body'], 'The soft-deleted product is not marked as deleted');
});

$runner->test('A soft-deleted product can be restored', function () use ($baseUrl) {
    $name = 'P3 Restore ' . bin2hex(random_bytes(4));

    $client = p3_admin_client($baseUrl);
    $productId = p3_create_product($client, $name);

    assert_true($productId > 0, 'The product was not created');

    $token = p3_admin_csrf($client);
    $client->post('/admin/products/' . $productId . '/delete', array('csrf_token' => $token));

    $deleted = db_select_one('SELECT `is_deleted` FROM `products` WHERE `id` = ? LIMIT 1', 'i', array($productId));
    assert_equals(1, (int) $deleted['is_deleted'], 'The product was not soft-deleted');

    $token = p3_admin_csrf($client);
    $response = $client->post('/admin/products/' . $productId . '/restore', array(
        'csrf_token' => $token,
    ));

    assert_equals(302, $response['status'], 'The restore was refused');

    $after = db_select_one('SELECT `is_deleted` FROM `products` WHERE `id` = ? LIMIT 1', 'i', array($productId));
    assert_equals(0, (int) $after['is_deleted'], 'The product was not restored');
});

// ===========================================================================
$runner->group('7. Admin product list pagination');
// ===========================================================================

$runner->test('The Admin list shows 20 products per page', function () use ($baseUrl) {
    // Make sure there are enough products for more than one page. The
    // filler products belong to the suite's own category and are removed
    // again by p3_cleanup().
    $existing = (int) (db_select_one('SELECT COUNT(*) AS `total` FROM `products`')['total'] ?? 0);
    $needed = 25 - $existing;

    $client = p3_admin_client($baseUrl);

    for ($i = 0; $i < max(0, $needed); $i++) {
        $name = 'P3 Pagination ' . $i . ' ' . bin2hex(random_bytes(3));
        $imagePath = p3_make_jpeg(200, 200, array(90, 140, 100));

        try {
            $token = p3_admin_csrf($client);

            $client->postMultipart('/admin/products', array(
                'csrf_token'  => $token,
                'name'        => $name,
                'description' => 'Pagination filler.',
                'price'       => '1.00',
                'stock'       => '1',
                'category_id' => (string) p3_test_category_id(),
            ), array(
                'image' => array(
                    'path' => $imagePath,
                    'name' => 'p3-page.jpg',
                    'type' => 'image/jpeg',
                ),
            ));
        } finally {
            @unlink($imagePath);
        }
    }

    $total = (int) (db_select_one('SELECT COUNT(*) AS `total` FROM `products`')['total'] ?? 0);
    assert_true($total > 20, 'There are not enough products for more than one page (' . $total . ')');

    $response = $client->get('/admin/products');

    assert_equals(200, $response['status'], 'The Admin product list was not returned');

    preg_match_all('/class="admin-product-name">/', $response['body'], $matches);
    assert_equals(20, count($matches[0]), 'The first page does not show 20 products');
});

$runner->test('The Admin list paginates and the last page holds the remainder', function () use ($baseUrl) {
    $total = (int) (db_select_one('SELECT COUNT(*) AS `total` FROM `products`')['total'] ?? 0);
    $lastPage = (int) ceil($total / 20);

    $client = p3_admin_client($baseUrl);
    $response = $client->get('/admin/products?page=' . $lastPage);

    assert_equals(200, $response['status'], 'The last page was not returned');

    preg_match_all('/class="admin-product-name">/', $response['body'], $matches);
    $expected = $total - (20 * ($lastPage - 1));

    assert_equals($expected, count($matches[0]), 'The last page does not hold the expected number of products');
});

$runner->test('An out-of-range Admin page falls back to the last page', function () use ($baseUrl) {
    $total = (int) (db_select_one('SELECT COUNT(*) AS `total` FROM `products`')['total'] ?? 0);
    $lastPage = (int) ceil($total / 20);

    $client = p3_admin_client($baseUrl);
    $response = $client->get('/admin/products?page=99999');

    assert_equals(200, $response['status'], 'The out-of-range page was not returned');
    assert_contains('page ' . $lastPage . ' of ' . $lastPage, $response['body'], 'The out-of-range page did not fall back to the last page');
});

$runner->test('An invalid Admin page number falls back to the first page', function () use ($baseUrl) {
    $client = p3_admin_client($baseUrl);
    $response = $client->get('/admin/products?page=abc');

    assert_equals(200, $response['status'], 'The invalid page was not returned');
    assert_contains('page 1 of', $response['body'], 'The invalid page did not fall back to the first page');
});

$runner->test('The Admin list shows both active and soft-deleted products', function () use ($baseUrl) {
    $active = (int) (db_select_one('SELECT COUNT(*) AS `total` FROM `products` WHERE `is_deleted` = 0')['total'] ?? 0);
    $deleted = (int) (db_select_one('SELECT COUNT(*) AS `total` FROM `products` WHERE `is_deleted` = 1')['total'] ?? 0);

    $client = p3_admin_client($baseUrl);
    $response = $client->get('/admin/products');

    // The count is rendered across several lines, so the whitespace is
    // collapsed before the assertion.
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($response['body'])));

    assert_contains(
        (string) ($active + $deleted) . ' products in total',
        $text,
        'The Admin list does not count both active and soft-deleted products'
    );
});

// ===========================================================================
$runner->group('8. Category management (FR-6.6, AC-27)');
// ===========================================================================

$runner->test('A category can be created', function () use ($baseUrl) {
    $name = 'P3 Category ' . bin2hex(random_bytes(4));

    $client = p3_admin_client($baseUrl);
    $token = p3_admin_csrf($client);

    $response = $client->post('/admin/categories', array(
        'csrf_token'  => $token,
        'name'        => $name,
        'description' => 'Created by the Phase 3 test suite.',
    ));

    assert_equals(302, $response['status'], 'The category was not created: ' . $response['body']);

    $row = db_select_one('SELECT `id`, `description` FROM `categories` WHERE `name` = ? LIMIT 1', 's', array($name));
    assert_true($row !== null, 'The category was not stored');
    assert_equals('Created by the Phase 3 test suite.', (string) $row['description'], 'The description was not stored');
});

$runner->test('A duplicate category name is refused', function () use ($baseUrl) {
    $existing = db_select_one('SELECT `name` FROM `categories` ORDER BY `id` ASC LIMIT 1');
    assert_true($existing !== null, 'There is no category to duplicate');

    $client = p3_admin_client($baseUrl);
    $token = p3_admin_csrf($client);

    $response = $client->post('/admin/categories', array(
        'csrf_token'  => $token,
        'name'        => (string) $existing['name'],
        'description' => 'Duplicate.',
    ));

    assert_equals(422, $response['status'], 'A duplicate category name was accepted');
    assert_contains('already exists', strtolower($response['body']), 'The duplicate was not explained');
});

$runner->test('A category with an empty name is refused', function () use ($baseUrl) {
    $client = p3_admin_client($baseUrl);
    $token = p3_admin_csrf($client);

    $response = $client->post('/admin/categories', array(
        'csrf_token'  => $token,
        'name'        => '',
        'description' => 'No name.',
    ));

    assert_equals(422, $response['status'], 'A category with no name was accepted');
});

$runner->test('A category can be renamed', function () use ($baseUrl) {
    $name = 'P3 Rename Target ' . bin2hex(random_bytes(4));

    $client = p3_admin_client($baseUrl);
    $token = p3_admin_csrf($client);

    $client->post('/admin/categories', array(
        'csrf_token'  => $token,
        'name'        => $name,
        'description' => 'Before the rename.',
    ));

    $row = db_select_one('SELECT `id` FROM `categories` WHERE `name` = ? LIMIT 1', 's', array($name));
    assert_true($row !== null, 'The category was not created');
    $categoryId = (int) $row['id'];

    $newName = 'P3 Renamed ' . bin2hex(random_bytes(4));
    $token = p3_admin_csrf($client);

    $response = $client->post('/admin/categories/' . $categoryId . '/update', array(
        'csrf_token'  => $token,
        'name'        => $newName,
        'description' => 'After the rename.',
    ));

    assert_equals(302, $response['status'], 'The rename was refused: ' . $response['body']);

    $renamed = db_select_one('SELECT `name`, `description` FROM `categories` WHERE `id` = ? LIMIT 1', 'i', array($categoryId));
    assert_equals($newName, (string) $renamed['name'], 'The category was not renamed');
    assert_equals('After the rename.', (string) $renamed['description'], 'The description was not updated');
});

$runner->test('A category with products cannot be removed, and the products are named', function () use ($baseUrl) {
    // The suite's own category, holding the suite's own product. A seeded
    // category is never removed, even by a test that expects a refusal.
    $name = 'P3 Blocking Product ' . bin2hex(random_bytes(4));

    $client = p3_admin_client($baseUrl);
    $productId = p3_create_product($client, $name);

    assert_true($productId > 0, 'The product was not created');

    $categoryId = p3_test_category_id();
    assert_true($categoryId > 0, 'The suite category was not created');

    $token = p3_admin_csrf($client);

    $response = $client->post('/admin/categories/' . $categoryId . '/delete', array(
        'csrf_token' => $token,
    ));

    assert_equals(422, $response['status'], 'A category with products was removed');
    assert_contains(
        $name,
        $response['body'],
        'The blocking product was not named'
    );

    $still = db_select_one('SELECT `id` FROM `categories` WHERE `id` = ? LIMIT 1', 'i', array($categoryId));
    assert_true($still !== null, 'The category was removed even though it still has products');
});

$runner->test('A category can be removed once its products are reassigned', function () use ($baseUrl) {
    $name = 'P3 Removable ' . bin2hex(random_bytes(4));

    $client = p3_admin_client($baseUrl);
    $token = p3_admin_csrf($client);

    $client->post('/admin/categories', array(
        'csrf_token'  => $token,
        'name'        => $name,
        'description' => 'To be removed.',
    ));

    $row = db_select_one('SELECT `id` FROM `categories` WHERE `name` = ? LIMIT 1', 's', array($name));
    assert_true($row !== null, 'The category was not created');
    $categoryId = (int) $row['id'];

    // It has no products, so the removal succeeds.
    $token = p3_admin_csrf($client);
    $response = $client->post('/admin/categories/' . $categoryId . '/delete', array(
        'csrf_token' => $token,
    ));

    assert_equals(302, $response['status'], 'The empty category was not removed: ' . $response['body']);

    $after = db_select_one('SELECT `id` FROM `categories` WHERE `id` = ? LIMIT 1', 'i', array($categoryId));
    assert_true($after === null, 'The category row is still present');
});

$runner->test('The category list shows the product count for each category', function () use ($baseUrl) {
    $client = p3_admin_client($baseUrl);
    $response = $client->get('/admin/categories');

    assert_equals(200, $response['status'], 'The category list was not returned');
    assert_contains('admin-category-name', $response['body'], 'The category list is empty');
    assert_contains('product', $response['body'], 'The category list shows no product counts');
});

$runner->test('The products table has no is_active column', function () {
    // The approved deletion mechanism is `is_deleted` alone. `is_active`
    // belongs to the `users` table and must not appear on `products`.
    $columns = array();

    $result = db()->query("SHOW COLUMNS FROM `products`");
    while ($row = $result->fetch_assoc()) {
        $columns[] = (string) $row['Field'];
    }
    $result->free();

    assert_false(in_array('is_active', $columns, true), 'The products table has an unapproved is_active column');
    assert_true(in_array('is_deleted', $columns, true), 'The products table has no is_deleted column');
});

// ===========================================================================
$runner->group('9. Authorisation and CSRF (FR-7.4, FR-7.7)');
// ===========================================================================

$runner->test('A User is refused every Admin page with 403', function () use ($baseUrl) {
    $client = p3_user_client($baseUrl);

    $pages = array(
        '/admin/dashboard',
        '/admin/products',
        '/admin/products/new',
        '/admin/categories',
    );

    foreach ($pages as $page) {
        $response = $client->get($page);
        assert_equals(403, $response['status'], 'A User was allowed to reach ' . $page);
    }
});

$runner->test('A User cannot create, edit or delete a product', function () use ($baseUrl) {
    $client = p3_user_client($baseUrl);
    $productId = (int) (db_select_one('SELECT `id` FROM `products` ORDER BY `id` ASC LIMIT 1')['id'] ?? 0);

    $attempts = array(
        array('POST', '/admin/products', array()),
        array('POST', '/admin/products/' . $productId . '/update', array()),
        array('POST', '/admin/products/' . $productId . '/delete', array()),
        array('POST', '/admin/products/' . $productId . '/restore', array()),
        array('POST', '/admin/categories', array()),
    );

    foreach ($attempts as $attempt) {
        $response = $client->request($attempt[0], $attempt[1], $attempt[2]);
        assert_equals(403, $response['status'], 'A User was allowed to POST to ' . $attempt[1]);
    }
});

$runner->test('Every Admin POST requires a CSRF token', function () use ($baseUrl) {
    $client = p3_admin_client($baseUrl);
    $productId = (int) (db_select_one('SELECT `id` FROM `products` ORDER BY `id` ASC LIMIT 1')['id'] ?? 0);

    $attempts = array(
        array('/admin/products', array()),
        array('/admin/products/' . $productId . '/update', array()),
        array('/admin/products/' . $productId . '/delete', array()),
        array('/admin/products/' . $productId . '/restore', array()),
        array('/admin/categories', array()),
    );

    foreach ($attempts as $attempt) {
        $response = $client->post($attempt[0], $attempt[1]);
        assert_equals(422, $response['status'], 'A POST without a CSRF token to ' . $attempt[0] . ' was not refused with 422');
    }
});

$runner->test('A rejected CSRF token re-renders the form with 422 and no state change', function () use ($baseUrl) {
    $client = p3_admin_client($baseUrl);
    $token = p3_admin_csrf($client);

    $name = 'P3 CSRF Product ' . bin2hex(random_bytes(4));

    $response = $client->post('/admin/products', array(
        'csrf_token'  => 'a-completely-wrong-token',
        'name'        => $name,
        'description' => 'Should not be stored.',
        'price'       => '2.00',
        'stock'       => '1',
        'category_id' => (string) p3_first_category_id(),
    ));

    assert_equals(422, $response['status'], 'A bad CSRF token was not refused with 422');
    assert_contains('alert-error', $response['body'], 'The CSRF error was not shown');

    $row = db_select_one('SELECT `id` FROM `products` WHERE `name` = ? LIMIT 1', 's', array($name));
    assert_true($row === null, 'A product was stored despite the rejected CSRF token');
});

$runner->test('An Admin with a forced password change is blocked from the Admin panel', function () use ($baseUrl) {
    // FR-1.9 and decision D-006: the forced change blocks the Admin pages
    // for an Admin too, while the storefront stays reachable.
    $row = db_select_one(
        'SELECT `id` FROM `users` WHERE `role_id` = (SELECT `id` FROM `roles` WHERE `name` = \'Admin\') LIMIT 1'
    );

    assert_true($row !== null, 'There is no Admin account');
    $adminId = (int) $row['id'];

    $original = db_select_one('SELECT `must_change_password` FROM `users` WHERE `id` = ? LIMIT 1', 'i', array($adminId));
    db_write('UPDATE `users` SET `must_change_password` = 1 WHERE `id` = ?', 'i', array($adminId));

    try {
        $client = p3_admin_client($baseUrl);

        $admin = $client->get('/admin/dashboard');
        assert_equals(403, $admin['status'], 'A forced-change Admin reached the Admin Dashboard');
        assert_contains(
            '/auth/change-password',
            $admin['headers']['location'] ?? '',
            'The forced-change Admin was not sent to the change-password page'
        );

        // The storefront stays reachable (decision D-006).
        $storefront = $client->get('/products');
        assert_equals(200, $storefront['status'], 'The storefront is not reachable during a forced password change');
    } finally {
        db_write(
            'UPDATE `users` SET `must_change_password` = ? WHERE `id` = ?',
            'ii',
            array((int) $original['must_change_password'], $adminId)
        );
    }
});

$runner->test('The upload probe route no longer exists', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/__upload_probe');

    assert_equals(404, $response['status'], 'The upload probe route is still reachable');
});

$runner->test('The Admin pages do not expose a CSRF token in the URL', function () use ($baseUrl) {
    $client = p3_admin_client($baseUrl);

    foreach (array('/admin/dashboard', '/admin/products', '/admin/categories') as $page) {
        $response = $client->get($page);
        assert_not_contains('csrf_token=', $response['body'], 'A CSRF token appears in the page body for ' . $page);
    }
});

$runner->test('Admin-entered content is escaped in the Admin list and the storefront', function () use ($baseUrl) {
    // NFR-S-03 / SEC-2: a script tag stored as a product name must be
    // rendered as text, never as markup.
    $name = '<script>alert(1)</script>';

    $client = p3_admin_client($baseUrl);
    $productId = p3_create_product($client, $name);

    assert_true($productId > 0, 'The product was not created');

    $adminList = $client->get('/admin/products');
    assert_not_contains(
        '<script>alert(1)</script>',
        $adminList['body'],
        'The script tag is rendered as markup in the Admin list'
    );
    assert_contains(
        '&lt;script&gt;',
        $adminList['body'],
        'The script tag is not escaped in the Admin list'
    );

    $shopper = new HttpClient($baseUrl);
    $storefront = $shopper->get('/products');

    assert_not_contains(
        '<script>alert(1)</script>',
        $storefront['body'],
        'The script tag is rendered as markup in the storefront'
    );
    assert_contains(
        '&lt;script&gt;',
        $storefront['body'],
        'The script tag is not escaped in the storefront'
    );
});

// ===========================================================================
$runner->group('10. Phase 1 and Phase 2 functionality still works');
// ===========================================================================

$runner->test('The public storefront still works', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $home = $client->get('/');
    assert_equals(200, $home['status'], 'The landing page is not reachable');
    assert_contains('GreenCorner', $home['body'], 'The landing page is not rendered');

    $products = $client->get('/products');
    assert_equals(200, $products['status'], 'The product listing is not reachable');
    assert_contains('product-name', $products['body'], 'The product listing shows no products');
});

$runner->test('The product detail page still works', function () use ($baseUrl) {
    $row = db_select_one('SELECT `id` FROM `products` WHERE `is_deleted` = 0 LIMIT 1');
    assert_true($row !== null, 'There is no active product');

    $client = new HttpClient($baseUrl);
    $response = $client->get('/products/' . (int) $row['id']);

    assert_equals(200, $response['status'], 'The product detail page is not reachable');
    assert_contains('product-detail', $response['body'], 'The product detail page is not rendered');
});

$runner->test('Registration and login still work', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $email = 'p3-regression-' . bin2hex(random_bytes(4)) . '@greencorner.test';

    $token = $client->csrfToken('/auth/register');
    $response = $client->post('/auth/register', array(
        'csrf_token' => $token,
        'full_name'  => 'P3 Regression User',
        'email'      => $email,
        'password'   => 'Regression123!',
        'confirm_password' => 'Regression123!',
    ));

    assert_equals(302, $response['status'], 'The registration was refused: ' . $response['body']);

    $row = db_select_one('SELECT `id` FROM `users` WHERE `email` = ? LIMIT 1', 's', array($email));
    assert_true($row !== null, 'The account was not created');

    // Clean up so the suite does not leave accounts behind.
    db_write('DELETE FROM `users` WHERE `id` = ?', 'i', array((int) $row['id']));
});

$runner->test('The database-backed login rate limiting still works', function () use ($baseUrl) {
    // D-005: five failed attempts lock the source address out.
    $client = new HttpClient($baseUrl);

    for ($i = 0; $i < 5; $i++) {
        $token = $client->csrfToken('/auth/login');
        $client->post('/auth/login', array(
            'csrf_token' => $token,
            'email'      => 'nobody-' . bin2hex(random_bytes(4)) . '@greencorner.test',
            'password'   => 'WrongPassword123!',
        ));
    }

    $row = db_select_one('SELECT COUNT(*) AS `total` FROM `login_attempts`');
    assert_true((int) $row['total'] > 0, 'No failed attempt was recorded');

    // Clear the counter so the rest of the suite is not locked out.
    db_write('DELETE FROM `login_attempts`', '', array());
});

// The suite leaves the catalogue exactly as the seed left it, so the Phase 2
// suite — which asserts on the seeded sample data — still passes when it
// runs afterwards.
p3_cleanup();

exit($runner->summary());
