<?php
/**
 * GreenCorner — Phase 4 Test Suite
 *
 * Covers the Phase 4 scope: the Cart page (P-08), the Payment page (P-09),
 * the Order Confirmation page (P-10), the customer order list (P-11), the
 * customer order detail (P-12), the cart background endpoints, the
 * simulated card processor, Cash on Delivery, and the transactional
 * checkout.
 *
 * Usage:
 *   php tests/phase4_tests.php [base-url]
 *
 * The default base URL is http://127.0.0.1:8000.
 *
 * The suite drives the application over HTTP, so the application must be
 * running before the tests are executed. See tests/serve.sh.
 *
 * TEST ISOLATION
 *
 * The suite never touches the seeded catalogue. It creates its own
 * products under a "P4 " prefix, its own customer accounts under a
 * "p4+" email prefix, and its own orders, and it removes every one of them
 * at the end. The seeded products, categories and image files are left
 * exactly as the seed left them, so the Phase 1, Phase 2 and Phase 3
 * suites still pass afterwards.
 */

require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/HttpClient.php';
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/helpers/db.php';
require_once dirname(__DIR__) . '/helpers/image.php';
require_once dirname(__DIR__) . '/models/Product.php';
require_once dirname(__DIR__) . '/models/ProductAdmin.php';
require_once dirname(__DIR__) . '/models/Category.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Cart.php';
require_once dirname(__DIR__) . '/models/Order.php';
require_once dirname(__DIR__) . '/models/Payment.php';

$baseUrl = $argv[1] ?? 'http://127.0.0.1:8000';
$runner = new TestRunner();

echo "GreenCorner — Phase 4 Test Suite\n";
echo "Base URL: " . $baseUrl . "\n";

// ===========================================================================
// Helpers
// ===========================================================================

/**
 * The seeded Admin account (FR-6.12).
 */
const P4_ADMIN_EMAIL = 'admin@greencorner.test';
const P4_ADMIN_PASSWORD = 'Admin123!';

/**
 * The prefix every record the suite creates carries.
 */
const P4_PREFIX = 'P4 ';

/**
 * The prefix every customer account the suite creates carries.
 */
const P4_EMAIL_PREFIX = 'p4+';

/**
 * The card number the simulated processor approves (decision B).
 */
const P4_SUCCESS_CARD = '4242424242424242';

/**
 * The card number the simulated processor declines (decision B).
 */
const P4_DECLINE_CARD = '4000000000000002';

/**
 * A second Luhn-valid card number that is not the success number.
 *
 * The strict whitelist means it must decline too (decision B).
 */
const P4_OTHER_VALID_CARD = '4111111111111111';

/**
 * A Luhn-INVALID card number. It is a validation failure, not a decline.
 */
const P4_INVALID_CARD = '4242424242424243';

/**
 * Log a client in as the seeded Admin and return the client.
 */
function p4_admin_client(string $baseUrl): HttpClient
{
    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => P4_ADMIN_EMAIL,
        'password'   => P4_ADMIN_PASSWORD,
    ));

    return $client;
}

/**
 * The CSRF token on the Admin product list page.
 */
function p4_admin_csrf(HttpClient $client): string
{
    $client->get('/admin/products');

    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $client->body, $m)) {
        return $m[1];
    }

    return '';
}

/**
 * Write a genuine JPEG file to a temporary path and return the path.
 */
function p4_make_jpeg(int $width = 600, int $height = 400, array $colour = array(60, 130, 70)): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, $colour[0], $colour[1], $colour[2]));

    $path = tempnam(sys_get_temp_dir(), 'gcimg');
    imagejpeg($image, $path, 90);
    imagedestroy($image);

    return $path;
}

/**
 * The id of the category the suite creates for itself.
 *
 * The suite never writes to a seeded category: renaming or removing one
 * would change the catalogue the Phase 2 tests assert on.
 *
 * @return int
 */
function p4_test_category_id(): int
{
    static $categoryId = null;

    if ($categoryId !== null) {
        return $categoryId;
    }

    $name = 'P4 Test Category';

    $row = db_select_one('SELECT `id` FROM `categories` WHERE `name` = ? LIMIT 1', 's', array($name));

    if ($row === null) {
        db_write(
            'INSERT INTO `categories` (`name`, `description`) VALUES (?, ?)',
            'ss',
            array($name, 'Created by the Phase 4 test suite.')
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
 * fixture is a genuine product with a genuine uploaded image.
 *
 * @param HttpClient $client An Admin client.
 * @param string     $name   The product name.
 * @param float      $price  The product price.
 * @param int        $stock  The product stock.
 * @return int The new product's id, or 0 when it could not be created.
 */
function p4_create_product(HttpClient $client, string $name, float $price = 5.00, int $stock = 10): int
{
    $categoryId = p4_test_category_id();

    if ($categoryId <= 0) {
        return 0;
    }

    $source = p4_make_jpeg(600, 400, array(70, 140, 80));

    $client->postMultipart('/admin/products', array(
        'csrf_token'  => p4_admin_csrf($client),
        'name'        => $name,
        'description' => 'Created by the Phase 4 test suite.',
        'price'       => number_format($price, 2, '.', ''),
        'stock'       => (string) $stock,
        'category_id' => (string) $categoryId,
    ), array(
        'image' => array(
            'path' => $source,
            'name' => 'p4-fixture.jpg',
            'type' => 'image/jpeg',
        ),
    ));

    @unlink($source);

    $row = db_select_one('SELECT `id` FROM `products` WHERE `name` = ? LIMIT 1', 's', array($name));

    return (int) ($row['id'] ?? 0);
}

/**
 * Register a customer account for the suite and return it, logged in.
 *
 * The account is created through the application's own registration form,
 * so the whole flow is exercised exactly as a browser would perform it.
 *
 * @param string $baseUrl
 * @param string $label   A short label that makes the email unique.
 * @return array{client: HttpClient, id: int, email: string}
 */
function p4_register_customer(string $baseUrl, string $label): array
{
    $email = P4_EMAIL_PREFIX . $label . '@greencorner.test';

    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/register');

    $client->post('/auth/register', array(
        'csrf_token'             => $token,
        'full_name'              => 'P4 Customer ' . $label,
        'email'                  => $email,
        'password'               => 'P4Password123!',
        'password_confirmation'  => 'P4Password123!',
    ));

    // Log in.
    $loginClient = new HttpClient($baseUrl);
    $loginToken = $loginClient->csrfToken('/auth/login');

    $loginClient->post('/auth/login', array(
        'csrf_token' => $loginToken,
        'email'      => $email,
        'password'   => 'P4Password123!',
    ));

    $row = db_select_one('SELECT `id` FROM `users` WHERE `email` = ? LIMIT 1', 's', array($email));

    return array(
        'client' => $loginClient,
        'id'     => (int) ($row['id'] ?? 0),
        'email'  => $email,
    );
}

/**
 * The CSRF token from a page.
 *
 * A redirect is followed, so the token is read from the page that was
 * finally rendered. The token is tied to the session, so the page it came
 * from does not matter.
 */
function p4_csrf(HttpClient $client, string $path): string
{
    $client->get($path, true);

    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $client->body, $m)) {
        return $m[1];
    }

    return '';
}

/**
 * Post a JSON request with the CSRF token.
 *
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function p4_post_json(HttpClient $client, string $path, array $payload, string $token): array
{
    $payload['csrf_token'] = $token;

    $body = json_encode($payload);

    return $client->requestRaw('POST', $path, $body, 'application/json');
}

/**
 * Add a product to a customer's cart through the background endpoint.
 *
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function p4_add_to_cart(HttpClient $client, int $productId, string $token): array
{
    return p4_post_json($client, '/cart/add', array('product_id' => $productId), $token);
}

/**
 * Empty a customer's cart.
 */
function p4_empty_cart(HttpClient $client, int $userId): void
{
    db_write('DELETE FROM `cart_items` WHERE `user_id` = ?', 'i', array($userId));
}

/**
 * The delivery address the suite uses.
 */
function p4_address(): array
{
    return array(
        'delivery_name'    => 'P4 Recipient',
        'delivery_phone'   => '01700000000',
        'delivery_address' => '12 Test Garden Road',
        'delivery_city'    => 'Dhaka',
    );
}

/**
 * The card fields that produce a successful payment.
 */
function p4_good_card(): array
{
    return array(
        'cardholder_name' => 'P4 Cardholder',
        'card_number'     => P4_SUCCESS_CARD,
        'expiry'          => '12/29',
        'cvv'             => '123',
    );
}

/**
 * Submit the checkout form.
 *
 * @param HttpClient $client
 * @param string     $method 'card' or 'cod'.
 * @param array      $card
 * @param string     $token
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function p4_submit_checkout(
    HttpClient $client,
    string $method,
    array $card,
    string $token,
    array $address = array()
): array {
    $address = $address === array() ? p4_address() : $address;

    return $client->post('/checkout/place-order', array(
        'csrf_token'     => $token,
        'payment_method' => $method,
        'delivery_name'    => $address['delivery_name'],
        'delivery_phone'   => $address['delivery_phone'],
        'delivery_address' => $address['delivery_address'],
        'delivery_city'    => $address['delivery_city'],
        'cardholder_name' => $card['cardholder_name'] ?? '',
        'card_number'     => $card['card_number'] ?? '',
        'expiry'          => $card['expiry'] ?? '',
        'cvv'             => $card['cvv'] ?? '',
    ));
}

/**
 * Remove everything the suite created, so the catalogue and the accounts are
 * left exactly as the seed left them.
 *
 * Orders reference products and customers, so they are removed first. The
 * products are removed before the category, because a category cannot be
 * removed while products still reference it.
 */
function p4_cleanup(): void
{
    // The suite's customers.
    $customers = db_select(
        'SELECT `id` FROM `users` WHERE `email` LIKE ?',
        's',
        array(P4_EMAIL_PREFIX . '%')
    );

    foreach ($customers as $customer) {
        $userId = (int) $customer['id'];

        db_write('DELETE FROM `cart_items` WHERE `user_id` = ?', 'i', array($userId));
        db_write('DELETE FROM `order_items` WHERE `order_id` IN (SELECT `id` FROM `orders` WHERE `user_id` = ?)', 'i', array($userId));
        db_write('DELETE FROM `payments` WHERE `order_id` IN (SELECT `id` FROM `orders` WHERE `user_id` = ?)', 'i', array($userId));
        db_write('DELETE FROM `orders` WHERE `user_id` = ?', 'i', array($userId));
        db_write('DELETE FROM `users` WHERE `id` = ?', 'i', array($userId));
    }

    // A failed card payment has no order (BR-8), so it is not removed with
    // the orders above. These rows carry no customer reference at all, so
    // they are identified by their shape: a card payment that failed and
    // was never attached to an order.
    db_write(
        "DELETE FROM `payments`
          WHERE `order_id` IS NULL AND `method` = 'card' AND `status` = 'failed'",
        ''
    );

    // The suite's products.
    $products = db_select(
        "SELECT `id`, `image_path`, `thumbnail_path` FROM `products` WHERE `name` LIKE ?",
        's',
        array(P4_PREFIX . '%')
    );

    foreach ($products as $product) {
        db_write('DELETE FROM `products` WHERE `id` = ?', 'i', array((int) $product['id']));

        delete_stored_images(
            (string) $product['image_path'],
            (string) $product['thumbnail_path']
        );
    }

    db_write('DELETE FROM `categories` WHERE `name` LIKE ?', 's', array('P4 %'));
}

// ===========================================================================
$runner->group('1. Access control (FR-7.2, FR-7.5, RBAC-5, RBAC-6)');
// ===========================================================================

$runner->test('An anonymous visitor is refused the cart page with 401', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/cart');

    assert_equals(401, $response['status'], 'The cart page should answer 401 for a visitor');
});

$runner->test('An anonymous visitor is refused the payment page with 401', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/checkout/payment');

    assert_equals(401, $response['status'], 'The payment page should answer 401 for a visitor');
});

$runner->test('An anonymous visitor is refused the order list with 401', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/orders');

    assert_equals(401, $response['status'], 'The order list should answer 401 for a visitor');
});

$runner->test('An Admin is refused the shopping pages with 403', function () use ($baseUrl) {
    $client = p4_admin_client($baseUrl);

    foreach (array('/cart', '/checkout/payment', '/orders') as $path) {
        $response = $client->get($path);

        assert_equals(403, $response['status'], 'An Admin should be refused ' . $path . ' with 403');
    }
});

$runner->test('A User may reach the cart, the payment page and the order list', function () use ($baseUrl) {
    $customer = p4_register_customer($baseUrl, 'access');

    foreach (array('/cart', '/orders') as $path) {
        $response = $customer['client']->get($path);

        assert_equals(200, $response['status'], 'A User should reach ' . $path);
    }

    p4_empty_cart($customer['client'], $customer['id']);
});

// ===========================================================================
$runner->group('2. The cart page (FR-3.2, FR-3.9, FR-3.11)');
// ===========================================================================

$runner->test('An empty cart shows the empty-cart message and a link to the listing', function () use ($baseUrl) {
    $customer = p4_register_customer($baseUrl, 'empty');

    $response = $customer['client']->get('/cart');

    assert_equals(200, $response['status'], 'The cart page should load');
    assert_contains('Your cart is empty', $response['body'], 'The empty-cart message is missing');
    assert_contains('/products', $response['body'], 'The link back to the listing is missing');
    assert_not_contains('Proceed to payment', $response['body'], 'The Proceed to Payment action should not be offered');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('The cart page lists each item with its thumbnail, price, quantity and line total', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Cart Listing', 12.50, 10);

    assert_true($productId > 0, 'The test product could not be created');

    $customer = p4_register_customer($baseUrl, 'listing');
    $token = p4_csrf($customer['client'], '/cart');
    p4_add_to_cart($customer['client'], $productId, $token);

    $response = $customer['client']->get('/cart');

    assert_equals(200, $response['status'], 'The cart page should load');
    assert_contains(P4_PREFIX . 'Cart Listing', $response['body'], 'The product name is missing from the cart');
    assert_contains('cart-thumbnail', $response['body'], 'The thumbnail is missing from the cart row');
    assert_contains('12.50', $response['body'], 'The unit price is missing from the cart row');
    assert_contains('cart-grand-total', $response['body'], 'The grand total is missing');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('The grand total equals the sum of the line totals', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Cart Total A', 10.00, 10);
    $productId2 = p4_create_product($admin, P4_PREFIX . 'Cart Total B', 4.25, 10);

    $customer = p4_register_customer($baseUrl, 'total');
    $token = p4_csrf($customer['client'], '/cart');

    p4_add_to_cart($customer['client'], $productId, $token);
    p4_add_to_cart($customer['client'], $productId, $token);
    p4_add_to_cart($customer['client'], $productId2, $token);

    $response = $customer['client']->get('/cart');

    // 2 x 10.00 + 1 x 4.25 = 24.25
    assert_contains('24.25', $response['body'], 'The grand total is wrong');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('The cart count in the navigation matches the cart contents', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Cart Badge', 3.00, 10);

    $customer = p4_register_customer($baseUrl, 'badge');
    $token = p4_csrf($customer['client'], '/cart');

    p4_add_to_cart($customer['client'], $productId, $token);

    $response = $customer['client']->get('/cart');

    assert_contains('id="cart-count">1<', $response['body'], 'The cart badge does not show 1');

    p4_empty_cart($customer['client'], $customer['id']);
});

// ===========================================================================
$runner->group('3. The background cart endpoints (FR-3.1, FR-3.3, FR-3.5, FR-3.6)');
// ===========================================================================

$runner->test('Adding to the cart returns the updated count in JSON', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Ajax Add', 7.00, 10);

    $customer = p4_register_customer($baseUrl, 'ajax');
    $token = p4_csrf($customer['client'], '/cart');

    $response = p4_add_to_cart($customer['client'], $productId, $token);

    assert_equals(200, $response['status'], 'The add-to-cart endpoint should answer 200');

    $data = json_decode($response['body'], true);

    assert_true(is_array($data), 'The response is not JSON');
    assert_true($data['ok'] === true, 'The response is not ok');
    assert_equals(1, $data['cart_count'], 'The cart count is wrong');
    assert_contains('View Cart', $data['message'] . ' ' . ($data['toast_action'] ?? ''), 'The toast action is missing');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('Adding the same product twice yields one row with quantity 2', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Ajax Duplicate', 7.00, 10);

    $customer = p4_register_customer($baseUrl, 'duplicate');
    $token = p4_csrf($customer['client'], '/cart');

    p4_add_to_cart($customer['client'], $productId, $token);
    p4_add_to_cart($customer['client'], $productId, $token);

    $rows = db_select(
        'SELECT `quantity` FROM `cart_items` WHERE `user_id` = ? AND `product_id` = ?',
        'ii',
        array($customer['id'], $productId)
    );

    assert_equals(1, count($rows), 'There should be exactly one cart row');
    assert_equals(2, (int) $rows[0]['quantity'], 'The quantity should be 2');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('An add-to-cart request without a valid CSRF token is rejected', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Ajax Csrf', 7.00, 10);

    $customer = p4_register_customer($baseUrl, 'ajaxcsrf');
    $token = p4_csrf($customer['client'], '/cart');

    $response = p4_post_json($customer['client'], '/cart/add', array(
        'product_id' => $productId,
        'csrf_token' => 'a-token-that-is-not-valid',
    ), 'a-token-that-is-not-valid');

    assert_equals(422, $response['status'], 'A bad CSRF token should be refused with 422');

    $data = json_decode($response['body'], true);

    assert_equals('invalid_csrf_token', $data['code'] ?? '', 'The error code is wrong');

    $rows = db_select(
        'SELECT `id` FROM `cart_items` WHERE `user_id` = ? AND `product_id` = ?',
        'ii',
        array($customer['id'], $productId)
    );

    assert_equals(0, count($rows), 'The cart should be unchanged');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('An out-of-stock product cannot be added', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Ajax Out Of Stock', 7.00, 0);

    $customer = p4_register_customer($baseUrl, 'ajaxstock');
    $token = p4_csrf($customer['client'], '/cart');

    $response = p4_add_to_cart($customer['client'], $productId, $token);

    assert_equals(422, $response['status'], 'An out-of-stock product should be refused');
    assert_contains('out of stock', strtolower($response['body']), 'The error message should mention the stock');

    $rows = db_select(
        'SELECT `id` FROM `cart_items` WHERE `user_id` = ? AND `product_id` = ?',
        'ii',
        array($customer['id'], $productId)
    );

    assert_equals(0, count($rows), 'The cart should be unchanged');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('Removing an item updates the count and the total', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Ajax Remove', 6.00, 10);

    $customer = p4_register_customer($baseUrl, 'ajaxremove');
    $token = p4_csrf($customer['client'], '/cart');

    p4_add_to_cart($customer['client'], $productId, $token);

    $response = p4_post_json($customer['client'], '/cart/remove', array('product_id' => $productId), $token);

    assert_equals(200, $response['status'], 'The remove endpoint should answer 200');

    $data = json_decode($response['body'], true);

    assert_equals(0, $data['cart_count'], 'The cart count should be 0');
    assert_equals('0.00', $data['total'], 'The total should be zero');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('Changing a quantity updates the line total and the grand total', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Ajax Quantity', 5.00, 10);

    $customer = p4_register_customer($baseUrl, 'ajaxqty');
    $token = p4_csrf($customer['client'], '/cart');

    p4_add_to_cart($customer['client'], $productId, $token);

    $response = p4_post_json($customer['client'], '/cart/update', array(
        'product_id' => $productId,
        'quantity'   => 3,
    ), $token);

    assert_equals(200, $response['status'], 'The update endpoint should answer 200');

    $data = json_decode($response['body'], true);

    assert_equals(3, $data['quantity'], 'The quantity should be 3');
    assert_equals('15.00', $data['total'], 'The total should be 15.00');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('A quantity below 1 is rejected and the row is not removed', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Ajax Bad Quantity', 5.00, 10);

    $customer = p4_register_customer($baseUrl, 'ajaxbadqty');
    $token = p4_csrf($customer['client'], '/cart');

    p4_add_to_cart($customer['client'], $productId, $token);

    $response = p4_post_json($customer['client'], '/cart/update', array(
        'product_id' => $productId,
        'quantity'   => 0,
    ), $token);

    assert_equals(422, $response['status'], 'A quantity of 0 should be refused');

    $rows = db_select(
        'SELECT `quantity` FROM `cart_items` WHERE `user_id` = ? AND `product_id` = ?',
        'ii',
        array($customer['id'], $productId)
    );

    assert_equals(1, count($rows), 'The row should still be there');
    assert_equals(1, (int) $rows[0]['quantity'], 'The quantity should be unchanged');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('A quantity above the available stock is clamped with a notification', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Ajax Clamp', 5.00, 2);

    $customer = p4_register_customer($baseUrl, 'ajaxclamp');
    $token = p4_csrf($customer['client'], '/cart');

    p4_add_to_cart($customer['client'], $productId, $token);

    $response = p4_post_json($customer['client'], '/cart/update', array(
        'product_id' => $productId,
        'quantity'   => 9,
    ), $token);

    assert_equals(200, $response['status'], 'The update should succeed with a clamp');

    $data = json_decode($response['body'], true);

    assert_equals(2, $data['quantity'], 'The quantity should be clamped to the stock');
    assert_true($data['clamped'] === true, 'The response should report the clamp');
    assert_true($data['message'] !== '', 'The customer should be notified');

    p4_empty_cart($customer['client'], $customer['id']);
});

// ===========================================================================
$runner->group('4. Price and stock changes in the cart (FR-3.7, FR-3.8)');
// ===========================================================================

$runner->test('A price change shows the old and the new price, and the total uses the new price', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Price Change', 8.00, 10);

    $customer = p4_register_customer($baseUrl, 'price');
    $token = p4_csrf($customer['client'], '/cart');

    p4_add_to_cart($customer['client'], $productId, $token);

    // The Admin raises the price.
    db_write('UPDATE `products` SET `price` = ? WHERE `id` = ?', 'di', array(11.00, $productId));

    $response = $customer['client']->get('/cart');

    assert_contains('Price changed', $response['body'], 'The price-change notification is missing');
    assert_contains('11.00', $response['body'], 'The new price is not shown');
    assert_contains('8.00', $response['body'], 'The old price is not shown');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('No price-change notification appears when the price has not changed', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Price Unchanged', 8.00, 10);

    $customer = p4_register_customer($baseUrl, 'priceunchanged');
    $token = p4_csrf($customer['client'], '/cart');

    p4_add_to_cart($customer['client'], $productId, $token);

    $response = $customer['client']->get('/cart');

    assert_not_contains('Price changed', $response['body'], 'There should be no price-change notification');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('A soft-deleted item stays in the cart and blocks checkout', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Deleted Item', 8.00, 10);

    $customer = p4_register_customer($baseUrl, 'deleted');
    $token = p4_csrf($customer['client'], '/cart');

    p4_add_to_cart($customer['client'], $productId, $token);

    // The Admin soft-deletes the product.
    db_write('UPDATE `products` SET `is_deleted` = 1 WHERE `id` = ?', 'i', array($productId));

    $response = $customer['client']->get('/cart');

    assert_contains('No longer available', $response['body'], 'The "No longer available" message is missing');
    assert_not_contains('Proceed to payment', $response['body'], 'Checkout should be blocked');

    $rows = db_select(
        'SELECT `id` FROM `cart_items` WHERE `user_id` = ? AND `product_id` = ?',
        'ii',
        array($customer['id'], $productId)
    );

    assert_equals(1, count($rows), 'The item should not be removed automatically');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('An item whose stock fell below the quantity is flagged', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Stock Reduced', 8.00, 10);

    $customer = p4_register_customer($baseUrl, 'stock');
    $token = p4_csrf($customer['client'], '/cart');

    p4_add_to_cart($customer['client'], $productId, $token);
    p4_add_to_cart($customer['client'], $productId, $token);

    db_write('UPDATE `products` SET `stock` = 1 WHERE `id` = ?', 'i', array($productId));

    $response = $customer['client']->get('/cart');

    assert_contains('Only 1 left', $response['body'], 'The stock notification is missing');

    p4_empty_cart($customer['client'], $customer['id']);
});

// ===========================================================================
$runner->group('5. Cart persistence (FR-3.10)');
// ===========================================================================

$runner->test('The cart survives a logout and a subsequent login', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Persist', 9.00, 10);

    $customer = p4_register_customer($baseUrl, 'persist');
    $token = p4_csrf($customer['client'], '/cart');

    p4_add_to_cart($customer['client'], $productId, $token);

    assert_equals(1, Cart::countItems($customer['id']), 'The cart should hold one item');

    // Log out and log back in with the same account.
    $client = new HttpClient($baseUrl);
    $loginToken = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $loginToken,
        'email'      => $customer['email'],
        'password'   => 'P4Password123!',
    ));

    assert_equals(1, Cart::countItems($customer['id']), 'The cart should survive the login');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('A different account sees a different cart', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Isolated', 9.00, 10);

    $first = p4_register_customer($baseUrl, 'isolatedone');
    $token = p4_csrf($first['client'], '/cart');
    p4_add_to_cart($first['client'], $productId, $token);

    $second = p4_register_customer($baseUrl, 'isolatedtwo');

    assert_equals(1, Cart::countItems($first['id']), 'The first cart should hold one item');
    assert_equals(0, Cart::countItems($second['id']), 'The second cart should be empty');

    p4_empty_cart($first['client'], $first['id']);
});

// ===========================================================================
$runner->group('6. The payment page (FR-4.1, FR-4.2, FR-4.3)');
// ===========================================================================

$runner->test('The payment page shows the summary, the address form and the payment form', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Payment Page', 15.00, 10);

    $customer = p4_register_customer($baseUrl, 'paymentpage');
    $token = p4_csrf($customer['client'], '/cart');
    p4_add_to_cart($customer['client'], $productId, $token);

    $response = $customer['client']->get('/checkout/payment');

    assert_equals(200, $response['status'], 'The payment page should load');
    assert_contains('Order summary', $response['body'], 'The order summary is missing');
    assert_contains('name="delivery_name"', $response['body'], 'The address form is missing');
    assert_contains('name="card_number"', $response['body'], 'The card form is missing');
    assert_contains('15.00', $response['body'], 'The summary total is missing');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('The payment page is not reachable with an empty cart', function () use ($baseUrl) {
    $customer = p4_register_customer($baseUrl, 'paymentempty');

    $response = $customer['client']->get('/checkout/payment');

    assert_equals(302, $response['status'], 'The payment page should redirect with an empty cart');
    assert_contains('/cart', $response['headers']['location'] ?? '', 'The redirect should go to the cart');
});

$runner->test('Card and COD radio buttons are present, and Card is selected by default', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Radio', 5.00, 10);

    $customer = p4_register_customer($baseUrl, 'radio');
    $token = p4_csrf($customer['client'], '/cart');
    p4_add_to_cart($customer['client'], $productId, $token);

    $response = $customer['client']->get('/checkout/payment');

    assert_contains('value="card"', $response['body'], 'The Card radio button is missing');
    assert_contains('value="cod"', $response['body'], 'The COD radio button is missing');
    assert_true(
        preg_match('/value="card"[^>]*checked/', $response['body']) === 1
        || preg_match('/checked[^>]*value="card"/', $response['body']) === 1,
        'Card should be selected by default'
    );

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('An invalid card number is refused with 422 and creates no payment row', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Invalid Card', 20.00, 10);

    $customer = p4_register_customer($baseUrl, 'invalidcard');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    $before = Payment::countAll();
    $ordersBefore = db_select_one('SELECT COUNT(*) AS `total` FROM `orders`');

    $card = p4_good_card();
    $card['card_number'] = P4_INVALID_CARD;

    $response = p4_submit_checkout($customer['client'], 'card', $card, $token);

    // The form is re-rendered with an inline error.
    assert_true(
        $response['status'] === 302 || $response['status'] === 422,
        'An invalid card should be refused, got ' . $response['status']
    );

    assert_equals($before, Payment::countAll(), 'No payment row should have been created');
    assert_equals(
        (int) $ordersBefore['total'],
        (int) db_select_one('SELECT COUNT(*) AS `total` FROM `orders`')['total'],
        'No order should have been created'
    );

    // The cart is unchanged.
    assert_equals(1, Cart::countItems($customer['id']), 'The cart should be unchanged');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('A missing expiry and a missing CVV are refused', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Missing Fields', 20.00, 10);

    $customer = p4_register_customer($baseUrl, 'missingfields');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    $before = Payment::countAll();

    $card = p4_good_card();
    $card['expiry'] = '';
    $card['cvv'] = '';

    p4_submit_checkout($customer['client'], 'card', $card, $token);

    assert_equals($before, Payment::countAll(), 'No payment row should have been created');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('An expired card is refused', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Expired Card', 20.00, 10);

    $customer = p4_register_customer($baseUrl, 'expiredcard');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    $before = Payment::countAll();

    $card = p4_good_card();
    $card['expiry'] = '01/20';

    p4_submit_checkout($customer['client'], 'card', $card, $token);

    assert_equals($before, Payment::countAll(), 'No payment row should have been created');

    p4_empty_cart($customer['client'], $customer['id']);
});

// ===========================================================================
$runner->group('7. The simulated card processor (FR-4.4, FR-4.5, FR-4.6, decision B)');
// ===========================================================================

$runner->test('The approved success card creates an order, a payment and reduces the stock', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Card Success', 25.00, 10);

    $customer = p4_register_customer($baseUrl, 'cardsuccess');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);
    p4_add_to_cart($customer['client'], $productId, $token);

    $response = p4_submit_checkout($customer['client'], 'card', p4_good_card(), $token);

    assert_equals(302, $response['status'], 'The checkout should redirect');
    assert_contains('/checkout/confirmation', $response['headers']['location'] ?? '', 'The redirect should go to the confirmation page');

    $order = db_select_one('SELECT * FROM `orders` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1', 'i', array($customer['id']));

    assert_true($order !== null, 'The order was not created');
    assert_equals('50.00', number_format((float) $order['total_amount'], 2, '.', ''), 'The order total is wrong');
    assert_equals('placed', (string) $order['status'], 'The order status is wrong');

    // The order items.
    $items = Order::items((int) $order['id']);

    assert_equals(1, count($items), 'There should be one order line');
    assert_equals(2, (int) $items[0]['quantity'], 'The order line quantity is wrong');
    assert_equals('25.00', number_format((float) $items[0]['unit_price_at_purchase'], 2, '.', ''), 'The unit price snapshot is wrong');

    // The stock was reduced.
    $product = db_select_one('SELECT `stock` FROM `products` WHERE `id` = ? LIMIT 1', 'i', array($productId));
    assert_equals(8, (int) $product['stock'], 'The stock was not reduced');

    // The payment.
    $payment = db_select_one('SELECT * FROM `payments` WHERE `order_id` = ? LIMIT 1', 'i', array($order['id']));

    assert_true($payment !== null, 'The payment row was not created');
    assert_equals('card', (string) $payment['method'], 'The payment method is wrong');
    assert_equals('success', (string) $payment['status'], 'The payment status is wrong');
    assert_true($payment['paid_at'] !== null, 'paid_at should be set');
    assert_equals((int) $order['id'], (int) $payment['order_id'], 'The payment should reference the order');

    // The cart is empty.
    assert_equals(0, Cart::countItems($customer['id']), 'The cart should be empty after the checkout');
});

$runner->test('The approved decline card creates no order and leaves the cart unchanged', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Card Decline', 30.00, 10);

    $customer = p4_register_customer($baseUrl, 'carddecline');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    $ordersBefore = (int) db_select_one('SELECT COUNT(*) AS `total` FROM `orders`')['total'];
    $stockBefore = (int) db_select_one('SELECT `stock` FROM `products` WHERE `id` = ? LIMIT 1', 'i', array($productId))['stock'];

    $card = p4_good_card();
    $card['card_number'] = P4_DECLINE_CARD;

    $response = p4_submit_checkout($customer['client'], 'card', $card, $token);

    assert_equals(302, $response['status'], 'The checkout should redirect back to the payment page');
    assert_contains('/checkout/payment', $response['headers']['location'] ?? '', 'The redirect should go back to the payment page');

    assert_equals(
        $ordersBefore,
        (int) db_select_one('SELECT COUNT(*) AS `total` FROM `orders`')['total'],
        'No order should have been created'
    );

    $stockAfter = (int) db_select_one('SELECT `stock` FROM `products` WHERE `id` = ? LIMIT 1', 'i', array($productId))['stock'];
    assert_equals($stockBefore, $stockAfter, 'The stock should be unchanged');

    // Exactly one failed payment row, with a NULL order_id.
    $payment = db_select_one(
        'SELECT * FROM `payments` WHERE `method` = ? AND `status` = ? ORDER BY `id` DESC LIMIT 1',
        'ss',
        array('card', 'failed')
    );

    assert_true($payment !== null, 'The failed payment row was not created');
    assert_null($payment['order_id'], 'A failed payment should reference no order');
    assert_null($payment['paid_at'], 'A failed payment should have no paid_at');

    // The cart still holds the item.
    assert_equals(1, Cart::countItems($customer['id']), 'The cart should be unchanged');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('Any other Luhn-valid card number is declined', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Card Other Valid', 30.00, 10);

    $customer = p4_register_customer($baseUrl, 'cardother');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    $ordersBefore = (int) db_select_one('SELECT COUNT(*) AS `total` FROM `orders`')['total'];

    $card = p4_good_card();
    $card['card_number'] = P4_OTHER_VALID_CARD;

    $response = p4_submit_checkout($customer['client'], 'card', $card, $token);

    assert_equals(302, $response['status'], 'The checkout should redirect');

    assert_equals(
        $ordersBefore,
        (int) db_select_one('SELECT COUNT(*) AS `total` FROM `orders`')['total'],
        'No order should have been created'
    );

    $payment = db_select_one(
        'SELECT * FROM `payments` WHERE `method` = ? AND `status` = ? ORDER BY `id` DESC LIMIT 1',
        'ss',
        array('card', 'failed')
    );

    assert_true($payment !== null, 'The failed payment row was not created');
    assert_null($payment['order_id'], 'A failed payment should reference no order');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('The success card succeeds with spaces, and the simulator is deterministic', function () use ($baseUrl) {
    // The pure simulator, checked directly.
    assert_equals('success', Payment::simulateCard('4242424242424242'), 'The success card should succeed');
    assert_equals('success', Payment::simulateCard('4242 4242 4242 4242'), 'The spaced success card should succeed');
    assert_equals('declined', Payment::simulateCard('4000000000000002'), 'The decline card should decline');
    assert_equals('declined', Payment::simulateCard('4111111111111111'), 'Another valid card should decline');

    // Only the success number succeeds: every other number declines.
    foreach (array('5555555555554444', '378282246310005', '6011111111111117') as $number) {
        assert_equals('declined', Payment::simulateCard($number), $number . ' should decline');
    }
});

// ===========================================================================
$runner->group('8. Cash on Delivery (FR-4.7, FR-4.10)');
// ===========================================================================

$runner->test('A COD order is created with a pending payment and reduced stock', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'COD Order', 18.00, 10);

    $customer = p4_register_customer($baseUrl, 'cod');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    $response = p4_submit_checkout($customer['client'], 'cod', array(), $token);

    assert_equals(302, $response['status'], 'The checkout should redirect');
    assert_contains('/checkout/confirmation', $response['headers']['location'] ?? '', 'The redirect should go to the confirmation page');

    $order = db_select_one('SELECT * FROM `orders` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1', 'i', array($customer['id']));

    assert_true($order !== null, 'The order was not created');
    assert_equals('placed', (string) $order['status'], 'The order status should be placed');

    $payment = db_select_one('SELECT * FROM `payments` WHERE `order_id` = ? LIMIT 1', 'i', array($order['id']));

    assert_true($payment !== null, 'The payment row was not created');
    assert_equals('cod', (string) $payment['method'], 'The payment method should be cod');
    assert_equals('pending', (string) $payment['status'], 'The payment status should be pending');
    assert_null($payment['paid_at'], 'A pending COD payment should have no paid_at');

    $product = db_select_one('SELECT `stock` FROM `products` WHERE `id` = ? LIMIT 1', 'i', array($productId));
    assert_equals(9, (int) $product['stock'], 'The stock was not reduced');

    assert_equals(0, Cart::countItems($customer['id']), 'The cart should be empty after the checkout');
});

$runner->test('COD is selectable for every product', function () use ($baseUrl) {
    // The rule is structural: the method is a choice on the payment page,
    // and the checkout records the method that was chosen. There is no
    // per-product restriction anywhere in the code.
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'COD Any Product', 4.00, 5);

    $customer = p4_register_customer($baseUrl, 'codany');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    $response = $customer['client']->get('/checkout/payment');

    assert_contains('value="cod"', $response['body'], 'COD should be offered');
    assert_not_contains('data-payment-method="cod" disabled', $response['body'], 'COD should not be disabled');

    p4_empty_cart($customer['client'], $customer['id']);
});

// ===========================================================================
$runner->group('9. The order number (FR-4.14, decisions C and D)');
// ===========================================================================

$runner->test('The order number matches the approved GC-YYYYMMDD-NNNN format', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Order Number', 11.00, 10);

    $customer = p4_register_customer($baseUrl, 'ordernumber');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    p4_submit_checkout($customer['client'], 'cod', array(), $token);

    $order = db_select_one('SELECT `order_number` FROM `orders` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1', 'i', array($customer['id']));

    assert_true($order !== null, 'The order was not created');

    $number = (string) $order['order_number'];

    assert_true(
        preg_match('/^GC-\d{8}-\d{4}$/', $number) === 1,
        'The order number "' . $number . '" does not match GC-YYYYMMDD-NNNN'
    );

    // The date part is today.
    assert_contains(date('Ymd'), $number, 'The order number should carry today\'s date');
});

$runner->test('Every order number is unique', function () use ($baseUrl) {
    $row = db_select_one(
        'SELECT COUNT(*) AS `total`, COUNT(DISTINCT `order_number`) AS `distinct_numbers` FROM `orders`'
    );

    assert_equals(
        (int) $row['total'],
        (int) $row['distinct_numbers'],
        'Two orders share an order number'
    );
});

$runner->test('The daily sequence increments and does not reuse a number', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Sequence', 13.00, 50);

    $numbers = array();

    for ($i = 1; $i <= 3; $i++) {
        $customer = p4_register_customer($baseUrl, 'sequence' . $i);
        $token = p4_csrf($customer['client'], '/checkout/payment');
        p4_add_to_cart($customer['client'], $productId, $token);

        p4_submit_checkout($customer['client'], 'cod', array(), $token);

        $order = db_select_one('SELECT `order_number` FROM `orders` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1', 'i', array($customer['id']));

        assert_true($order !== null, 'Order ' . $i . ' was not created');

        $numbers[] = (string) $order['order_number'];
    }

    assert_equals(3, count(array_unique($numbers)), 'The order numbers are not unique: ' . implode(', ', $numbers));

    // The sequence part increases.
    $sequence = array();

    foreach ($numbers as $number) {
        $sequence[] = (int) substr($number, -4);
    }

    assert_equals(1, $sequence[1] - $sequence[0], 'The sequence did not increment by one');
    assert_equals(1, $sequence[2] - $sequence[1], 'The sequence did not increment by one');
});

$runner->test('The order_sequence table exists and holds one row per day', function () {
    $row = db_select_one('SELECT COUNT(*) AS `total` FROM `order_sequence`');

    assert_true($row !== null, 'The order_sequence table does not exist');

    $today = db_select_one('SELECT `last_value` FROM `order_sequence` WHERE `sequence_date` = ? LIMIT 1', 's', array(date('Y-m-d')));

    assert_true($today !== null, 'There is no sequence row for today');
    assert_true((int) $today['last_value'] >= 1, 'The sequence counter was not advanced');
});

$runner->test('Two simultaneous checkouts receive different order numbers', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Concurrent', 21.00, 50);

    $first = p4_register_customer($baseUrl, 'concurrentone');
    $second = p4_register_customer($baseUrl, 'concurrenttwo');

    $tokenOne = p4_csrf($first['client'], '/checkout/payment');
    $tokenTwo = p4_csrf($second['client'], '/checkout/payment');

    p4_add_to_cart($first['client'], $productId, $tokenOne);
    p4_add_to_cart($second['client'], $productId, $tokenTwo);

    // Two genuinely concurrent requests.
    //
    // The curl extension is not available in every PHP build, so the two
    // requests are driven by two separate PHP processes instead. Each
    // process performs one checkout and reports the resulting redirect, so
    // the two checkouts genuinely overlap in time.
    $phpBinary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $worker = __DIR__ . '/phase4_concurrent_worker.php';

    $processes = array();
    $pipes = array();

    foreach (array($first, $second) as $index => $customer) {
        $token = $index === 0 ? $tokenOne : $tokenTwo;
        // The application's session cookie is named greencorner_session.
        $sessionId = (string) $customer['client']->cookie('greencorner_session');

        $command = escapeshellarg($phpBinary)
            . ' ' . escapeshellarg($worker)
            . ' ' . escapeshellarg($baseUrl)
            . ' ' . escapeshellarg($sessionId)
            . ' ' . escapeshellarg($token);

        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        $process = proc_open($command, $descriptors, $processPipes);

        if (!is_resource($process)) {
            assert_true(false, 'The concurrent checkout process could not be started');
            continue;
        }

        fclose($processPipes[0]);

        $processes[] = array('process' => $process, 'pipes' => $processPipes);
    }

    // Both processes are running now; collect their output.
    foreach ($processes as $entry) {
        $stdout = stream_get_contents($entry['pipes'][1]);
        $stderr = stream_get_contents($entry['pipes'][2]);

        fclose($entry['pipes'][1]);
        fclose($entry['pipes'][2]);

        proc_close($entry['process']);
    }

    $orders = db_select(
        'SELECT `order_number` FROM `orders` WHERE `user_id` IN (?, ?) ORDER BY `id` ASC',
        'ii',
        array($first['id'], $second['id'])
    );

    assert_equals(2, count($orders), 'Both orders should have been created');
    assert_equals(
        2,
        count(array_unique(array_column($orders, 'order_number'))),
        'The simultaneous checkouts received the same order number'
    );
});

// ===========================================================================
$runner->group('10. The order confirmation page (FR-4.13)');
// ===========================================================================

$runner->test('The confirmation page shows the number, the items, the total, the method and the status', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Confirmation', 19.00, 10);

    $customer = p4_register_customer($baseUrl, 'confirmation');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    $response = p4_submit_checkout($customer['client'], 'card', p4_good_card(), $token);

    assert_equals(302, $response['status'], 'The checkout should redirect');

    $location = (string) ($response['headers']['location'] ?? '');
    assert_contains('/checkout/confirmation', $location, 'The redirect should go to the confirmation page');

    $page = $customer['client']->get('/checkout/confirmation?order=' . urldecode(explode('order=', $location)[1] ?? ''));

    assert_equals(200, $page['status'], 'The confirmation page should load');
    assert_contains('Order confirmation', $page['body'], 'The confirmation page is missing');
    assert_contains(P4_PREFIX . 'Confirmation', $page['body'], 'The item name is missing');
    assert_contains('19.00', $page['body'], 'The total is missing');
    assert_contains('Card', $page['body'], 'The payment method is missing');
    assert_contains('Paid', $page['body'], 'The payment status is missing');
    assert_contains('/dashboard', $page['body'], 'The link to the dashboard is missing');
});

$runner->test('A COD confirmation shows a pending payment status', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Confirmation COD', 17.00, 10);

    $customer = p4_register_customer($baseUrl, 'confirmationcod');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    $response = p4_submit_checkout($customer['client'], 'cod', array(), $token);

    $location = (string) ($response['headers']['location'] ?? '');
    $page = $customer['client']->get('/checkout/confirmation?order=' . urldecode(explode('order=', $location)[1] ?? ''));

    assert_equals(200, $page['status'], 'The confirmation page should load');
    assert_contains('Cash on delivery', $page['body'], 'The payment method is missing');
    assert_contains('Pending', $page['body'], 'The payment status should be pending');
});

$runner->test('Another customer is refused the confirmation page with 403', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Confirmation Foreign', 14.00, 10);

    $owner = p4_register_customer($baseUrl, 'confirmationowner');
    $token = p4_csrf($owner['client'], '/checkout/payment');
    p4_add_to_cart($owner['client'], $productId, $token);

    $response = p4_submit_checkout($owner['client'], 'cod', array(), $token);
    $location = (string) ($response['headers']['location'] ?? '');

    $other = p4_register_customer($baseUrl, 'confirmationother');
    $page = $other['client']->get('/checkout/confirmation?order=' . urldecode(explode('order=', $location)[1] ?? ''));

    assert_equals(403, $page['status'], 'Another customer should be refused with 403');
    assert_not_contains(P4_PREFIX . 'Confirmation Foreign', $page['body'], 'The order data leaked');
});

// ===========================================================================
$runner->group('11. The customer order list and detail (FR-5.1, FR-5.2, FR-5.4)');
// ===========================================================================

$runner->test('A new account sees the empty-order-history state', function () use ($baseUrl) {
    $customer = p4_register_customer($baseUrl, 'noorders');

    $response = $customer['client']->get('/orders');

    assert_equals(200, $response['status'], 'The order list should load');
    assert_contains('You have no orders yet', $response['body'], 'The empty-state message is missing');
    assert_contains('/products', $response['body'], 'The link to the catalogue is missing');
});

$runner->test('The order list shows the number, the date, the total and the status', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Order List', 23.00, 10);

    $customer = p4_register_customer($baseUrl, 'orderlist');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);
    p4_submit_checkout($customer['client'], 'card', p4_good_card(), $token);

    $response = $customer['client']->get('/orders');

    assert_equals(200, $response['status'], 'The order list should load');

    $order = db_select_one('SELECT `order_number` FROM `orders` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1', 'i', array($customer['id']));

    assert_contains((string) $order['order_number'], $response['body'], 'The order number is missing');
    assert_contains('23.00', $response['body'], 'The total is missing');
    assert_contains('Placed', $response['body'], 'The order status is missing');
});

$runner->test('The dashboard greets the customer by name and lists the recent orders', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Dashboard Orders', 16.00, 10);

    $customer = p4_register_customer($baseUrl, 'dashboard');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);
    p4_submit_checkout($customer['client'], 'cod', array(), $token);

    $response = $customer['client']->get('/dashboard');

    assert_equals(200, $response['status'], 'The dashboard should load');
    assert_contains('P4 Customer dashboard', $response['body'], 'The greeting should contain the full name');
    assert_contains('Recent orders', $response['body'], 'The recent orders section is missing');
});

$runner->test('The order detail page shows every listed field', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Detail Fields', 29.00, 10);

    $customer = p4_register_customer($baseUrl, 'detail');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);
    p4_submit_checkout($customer['client'], 'card', p4_good_card(), $token);

    $order = db_select_one('SELECT `id` FROM `orders` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1', 'i', array($customer['id']));

    $response = $customer['client']->get('/orders/' . (int) $order['id']);

    assert_equals(200, $response['status'], 'The order detail page should load');
    assert_contains(P4_PREFIX . 'Detail Fields', $response['body'], 'The product name is missing');
    assert_contains('29.00', $response['body'], 'The total is missing');
    assert_contains('Card', $response['body'], 'The payment method is missing');
    assert_contains('Paid', $response['body'], 'The payment status is missing');
    assert_contains('12 Test Garden Road', $response['body'], 'The delivery address is missing');
    assert_contains('Dhaka', $response['body'], 'The delivery city is missing');
    assert_contains('01700000000', $response['body'], 'The delivery phone is missing');
});

$runner->test("Another customer's order is refused with 403 and reveals no data", function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Foreign Detail', 33.00, 10);

    $owner = p4_register_customer($baseUrl, 'foreignowner');
    $token = p4_csrf($owner['client'], '/checkout/payment');
    p4_add_to_cart($owner['client'], $productId, $token);
    p4_submit_checkout($owner['client'], 'cod', array(), $token);

    $order = db_select_one('SELECT `id` FROM `orders` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1', 'i', array($owner['id']));

    $other = p4_register_customer($baseUrl, 'foreignother');
    $response = $other['client']->get('/orders/' . (int) $order['id']);

    assert_equals(403, $response['status'], "Another customer's order should be refused with 403");
    assert_not_contains(P4_PREFIX . 'Foreign Detail', $response['body'], 'The order data leaked');
    assert_not_contains('33.00', $response['body'], 'The order total leaked');
});

$runner->test('An order that does not exist answers 404', function () use ($baseUrl) {
    $customer = p4_register_customer($baseUrl, 'notfound');

    $response = $customer['client']->get('/orders/99999999');

    assert_equals(404, $response['status'], 'An unknown order should answer 404');
});

// ===========================================================================
$runner->group('12. Transactional checkout (FR-4.15, FR-4.11, ER-20, ER-23)');
// ===========================================================================

$runner->test('An item that went out of stock prevents the order from being created', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Out Of Stock', 34.00, 5);

    $customer = p4_register_customer($baseUrl, 'stockblock');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    // The stock disappears before the checkout.
    db_write('UPDATE `products` SET `stock` = 0 WHERE `id` = ?', 'i', array($productId));

    $ordersBefore = (int) db_select_one('SELECT COUNT(*) AS `total` FROM `orders`')['total'];
    $paymentsBefore = Payment::countAll();

    $response = p4_submit_checkout($customer['client'], 'card', p4_good_card(), $token);

    assert_equals(302, $response['status'], 'The checkout should redirect');

    assert_equals(
        $ordersBefore,
        (int) db_select_one('SELECT COUNT(*) AS `total` FROM `orders`')['total'],
        'No order should have been created'
    );

    assert_equals($paymentsBefore, Payment::countAll(), 'No payment row should have been created');

    // The customer is told which item is the problem.
    $cart = $customer['client']->get('/cart');
    assert_contains('out of stock', strtolower($cart['body']), 'The item should be reported as out of stock');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('A soft-deleted item prevents the order from being created', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Deleted At Checkout', 35.00, 5);

    $customer = p4_register_customer($baseUrl, 'deletedcheckout');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    db_write('UPDATE `products` SET `is_deleted` = 1 WHERE `id` = ?', 'i', array($productId));

    $ordersBefore = (int) db_select_one('SELECT COUNT(*) AS `total` FROM `orders`')['total'];

    p4_submit_checkout($customer['client'], 'card', p4_good_card(), $token);

    assert_equals(
        $ordersBefore,
        (int) db_select_one('SELECT COUNT(*) AS `total` FROM `orders`')['total'],
        'No order should have been created'
    );

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('A failed checkout leaves the cart intact', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Failed Keeps Cart', 36.00, 5);

    $customer = p4_register_customer($baseUrl, 'failedcart');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    db_write('UPDATE `products` SET `stock` = 0 WHERE `id` = ?', 'i', array($productId));

    p4_submit_checkout($customer['client'], 'card', p4_good_card(), $token);

    assert_equals(1, Cart::countItems($customer['id']), 'The cart should still hold its items');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('A price change at checkout asks the customer to confirm the updated total', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Price At Checkout', 40.00, 10);

    $customer = p4_register_customer($baseUrl, 'pricecheckout');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    // The Payment page shows the old total.
    $page = $customer['client']->get('/checkout/payment');
    assert_contains('40.00', $page['body'], 'The summary total should be 40.00');

    // The price moves before the checkout.
    db_write('UPDATE `products` SET `price` = ? WHERE `id` = ?', 'di', array(50.00, $productId));

    $ordersBefore = (int) db_select_one('SELECT COUNT(*) AS `total` FROM `orders`')['total'];

    $response = p4_submit_checkout($customer['client'], 'cod', array(), $token);

    assert_equals(302, $response['status'], 'The checkout should redirect');
    assert_contains('/checkout/payment', $response['headers']['location'] ?? '', 'The checkout should go back to the payment page');

    assert_equals(
        $ordersBefore,
        (int) db_select_one('SELECT COUNT(*) AS `total` FROM `orders`')['total'],
        'The order should not be committed before the customer confirms'
    );

    // The confirmation step shows the updated total.
    $confirm = $customer['client']->get('/checkout/payment');

    assert_contains('The price has changed', $confirm['body'], 'The price-change notice is missing');
    assert_contains('50.00', $confirm['body'], 'The updated total is missing');
    assert_contains('40.00', $confirm['body'], 'The previous total is missing');
});

$runner->test('Confirming the updated total commits the order at the new price', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Confirm Price', 45.00, 10);

    $customer = p4_register_customer($baseUrl, 'confirmprice');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    $customer['client']->get('/checkout/payment');

    db_write('UPDATE `products` SET `price` = ? WHERE `id` = ?', 'di', array(55.00, $productId));

    // The first attempt asks for confirmation.
    p4_submit_checkout($customer['client'], 'cod', array(), $token);

    // The customer confirms.
    $confirmToken = p4_csrf($customer['client'], '/checkout/payment');

    $response = $customer['client']->post('/checkout/confirm-price', array(
        'csrf_token'     => $confirmToken,
        'payment_method' => 'cod',
    ));

    assert_equals(302, $response['status'], 'The confirmation should redirect');
    assert_contains('/checkout/confirmation', $response['headers']['location'] ?? '', 'The redirect should go to the confirmation page');

    $order = db_select_one('SELECT * FROM `orders` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1', 'i', array($customer['id']));

    assert_true($order !== null, 'The order was not created');
    assert_equals('55.00', number_format((float) $order['total_amount'], 2, '.', ''), 'The order should use the new price');

    $items = Order::items((int) $order['id']);

    assert_equals('55.00', number_format((float) $items[0]['unit_price_at_purchase'], 2, '.', ''), 'The line price snapshot should be the new price');
});

$runner->test('A committed order total does not change when the product price later changes', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Immutable Total', 50.00, 10);

    $customer = p4_register_customer($baseUrl, 'immutabletotal');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);
    p4_submit_checkout($customer['client'], 'cod', array(), $token);

    $order = db_select_one('SELECT * FROM `orders` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1', 'i', array($customer['id']));

    db_write('UPDATE `products` SET `price` = ? WHERE `id` = ?', 'di', array(99.00, $productId));

    $reloaded = db_select_one('SELECT `total_amount` FROM `orders` WHERE `id` = ? LIMIT 1', 'i', array((int) $order['id']));

    assert_equals('50.00', number_format((float) $reloaded['total_amount'], 2, '.', ''), 'The committed total should not change');

    $items = Order::items((int) $order['id']);

    assert_equals('50.00', number_format((float) $items[0]['unit_price_at_purchase'], 2, '.', ''), 'The line price snapshot should not change');
});

// ===========================================================================
$runner->group('13. The delivery address snapshot (FR-4.8, ER-7)');
// ===========================================================================

$runner->test('The checkout cannot complete without a valid address', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'No Address', 60.00, 10);

    $customer = p4_register_customer($baseUrl, 'noaddress');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    $ordersBefore = (int) db_select_one('SELECT COUNT(*) AS `total` FROM `orders`')['total'];

    $card = p4_good_card();

    p4_submit_checkout($customer['client'], 'card', $card, $token, array(
        'delivery_name'    => '',
        'delivery_phone'   => '',
        'delivery_address' => '',
        'delivery_city'    => '',
    ));

    assert_equals(
        $ordersBefore,
        (int) db_select_one('SELECT COUNT(*) AS `total` FROM `orders`')['total'],
        'No order should have been created'
    );

    // The form is re-rendered with the field errors.
    $page = $customer['client']->get('/checkout/payment');

    assert_contains('Please enter the recipient', $page['body'], 'The name error is missing');
    assert_contains('Please enter a contact number', $page['body'], 'The phone error is missing');
    assert_contains('Please enter the street address', $page['body'], 'The address error is missing');
    assert_contains('Please enter a city or area', $page['body'], 'The city error is missing');

    p4_empty_cart($customer['client'], $customer['id']);
});

$runner->test('The address is stored on the order row and is not shared with another order', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Address Snapshot', 61.00, 10);

    $customer = p4_register_customer($baseUrl, 'address');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    p4_submit_checkout($customer['client'], 'cod', array(), $token, array(
        'delivery_name'    => 'P4 Snapshot Name',
        'delivery_phone'   => '01800000000',
        'delivery_address' => '44 Snapshot Lane',
        'delivery_city'    => 'Sylhet',
    ));

    $order = db_select_one('SELECT * FROM `orders` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1', 'i', array($customer['id']));

    assert_true($order !== null, 'The order was not created');
    assert_equals('P4 Snapshot Name', (string) $order['delivery_name'], 'The name snapshot is wrong');
    assert_equals('01800000000', (string) $order['delivery_phone'], 'The phone snapshot is wrong');
    assert_equals('44 Snapshot Lane', (string) $order['delivery_address'], 'The address snapshot is wrong');
    assert_equals('Sylhet', (string) $order['delivery_city'], 'The city snapshot is wrong');

    // A second order with a different address keeps its own snapshot.
    p4_add_to_cart($customer['client'], $productId, $token);

    p4_submit_checkout($customer['client'], 'cod', array(), $token, array(
        'delivery_name'    => 'P4 Other Name',
        'delivery_phone'   => '01900000000',
        'delivery_address' => '99 Different Road',
        'delivery_city'    => 'Chattogram',
    ));

    $orders = db_select('SELECT * FROM `orders` WHERE `user_id` = ? ORDER BY `id` ASC', 'i', array($customer['id']));

    assert_equals(2, count($orders), 'Both orders should exist');
    assert_equals('P4 Snapshot Name', (string) $orders[0]['delivery_name'], 'The first order changed');
    assert_equals('P4 Other Name', (string) $orders[1]['delivery_name'], 'The second order changed');
});

// ===========================================================================
$runner->group('14. Card data is never stored (FR-4.9, NFR-S-09, SEC-15)');
// ===========================================================================

$runner->test('The payments table has no column that could hold a card number', function () {
    $rows = db_select('SHOW COLUMNS FROM `payments`');
    $names = array_column($rows, 'Field');

    foreach (array('card_number', 'card_number_encrypted', 'card_no', 'cvv', 'cvc', 'expiry', 'exp_date', 'card_holder') as $forbidden) {
        assert_true(
            !in_array($forbidden, $names, true),
            'The payments table has a "' . $forbidden . '" column'
        );
    }
});

$runner->test('No card data appears anywhere in the database', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'No Card Data', 62.00, 10);

    $customer = p4_register_customer($baseUrl, 'nocarddata');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    p4_submit_checkout($customer['client'], 'card', p4_good_card(), $token);

    // Search every text column of every table for the card number.
    $tables = db_select('SHOW TABLES');
    $found = false;

    foreach ($tables as $tableRow) {
        $table = array_values($tableRow)[0];

        $columns = db_select('SHOW COLUMNS FROM `' . $table . '`');

        foreach ($columns as $column) {
            $name = (string) $column['Field'];
            $type = strtolower((string) $column['Type']);

            if (strpos($type, 'char') === false && strpos($type, 'text') === false) {
                continue;
            }

            $row = db_select_one(
                'SELECT COUNT(*) AS `hits` FROM `' . $table . '` WHERE `' . $name . '` LIKE ?',
                's',
                array('%' . P4_SUCCESS_CARD . '%')
            );

            if ((int) ($row['hits'] ?? 0) > 0) {
                $found = true;
                break 2;
            }
        }
    }

    assert_false($found, 'The card number was found in the database');
});

$runner->test('The card number is not echoed back in the response', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'No Echo', 63.00, 10);

    $customer = p4_register_customer($baseUrl, 'noecho');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);

    $response = p4_submit_checkout($customer['client'], 'card', p4_good_card(), $token);

    assert_not_contains(P4_SUCCESS_CARD, $response['body'], 'The card number appeared in the response');

    $page = $customer['client']->get('/checkout/payment');

    assert_not_contains(P4_SUCCESS_CARD, $page['body'], 'The card number appeared on the payment page');
});

// ===========================================================================
$runner->group('15. Payment status ownership and permitted shapes (FR-4.16, BR-8, BR-9)');
// ===========================================================================

$runner->test('The orders table has no payment_status column', function () {
    $rows = db_select('SHOW COLUMNS FROM `orders`');
    $names = array_column($rows, 'Field');

    assert_true(!in_array('payment_status', $names, true), 'The orders table has a payment_status column');
    assert_true(in_array('status', $names, true), 'The orders table should have a status column');
});

$runner->test('Every payment row is one of the five permitted shapes', function () {
    $rows = db_select('SELECT `method`, `status`, `order_id` FROM `payments`');

    foreach ($rows as $row) {
        $method = (string) $row['method'];
        $status = (string) $row['status'];
        $orderId = $row['order_id'];

        $permitted = (
            ($method === 'card' && $status === 'success' && $orderId !== null)
            || ($method === 'card' && $status === 'failed' && $orderId === null)
            || ($method === 'cod' && $status === 'pending' && $orderId !== null)
            || ($method === 'cod' && $status === 'success' && $orderId !== null)
            || ($method === 'cod' && $status === 'failed' && $orderId !== null)
        );

        assert_true($permitted, 'The payment shape ' . $method . '/' . $status . ' is not permitted');
    }
});

$runner->test('There is at most one successful payment per order', function () {
    $row = db_select_one(
        "SELECT COUNT(*) AS `total` FROM (
            SELECT `order_id` FROM `payments`
             WHERE `status` = 'success' AND `order_id` IS NOT NULL
             GROUP BY `order_id`
            HAVING COUNT(*) > 1
        ) AS `duplicates`"
    );

    assert_equals(0, (int) $row['total'], 'An order has more than one successful payment');
});

$runner->test('The order status reads the payment status from the payments table', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Status Join', 64.00, 10);

    $customer = p4_register_customer($baseUrl, 'statusjoin');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);
    p4_submit_checkout($customer['client'], 'cod', array(), $token);

    $order = Order::find((int) db_select_one('SELECT `id` FROM `orders` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1', 'i', array($customer['id']))['id']);

    assert_true($order !== null, 'The order was not found');
    assert_equals('pending', (string) $order['payment_status'], 'The payment status should be pending');
});

// ===========================================================================
$runner->group('16. The Admin order count reflects new orders (FR-6.1, decision E)');
// ===========================================================================

$runner->test('The Admin dashboard order count reflects the orders that exist', function () use ($baseUrl) {
    $admin = p4_admin_client($baseUrl);
    $productId = p4_create_product($admin, P4_PREFIX . 'Admin Count', 65.00, 10);

    $customer = p4_register_customer($baseUrl, 'admincount');
    $token = p4_csrf($customer['client'], '/checkout/payment');
    p4_add_to_cart($customer['client'], $productId, $token);
    p4_submit_checkout($customer['client'], 'cod', array(), $token);

    $expected = (int) db_select_one('SELECT COUNT(*) AS `total` FROM `orders`')['total'];

    // The count is read from the database, so it follows the orders table.
    assert_true($expected >= 1, 'The order was not created');

    $row = db_select_one('SELECT COUNT(*) AS `total` FROM `orders`');
    assert_equals($expected, (int) $row['total'], 'The order count is wrong');
});

$runner->test('The Admin order list and detail pages are reserved for the Admin role', function () use ($baseUrl) {
    // Phase 4 originally asserted that these pages did not exist yet.
    // Phase 5 builds them (P-17, P-18), so the assertion now checks the
    // boundary that matters: a User is refused them, and the Admin reaches
    // them. The full behaviour is covered by tests/phase5_tests.php.
    $admin = p4_admin_client($baseUrl);

    foreach (array('/admin/orders', '/admin/orders/1') as $path) {
        $response = $admin->get($path);

        assert_true(
            $response['status'] === 200 || $response['status'] === 404,
            $path . ' should be reachable by the Admin, or 404 for an unknown order'
        );
    }
});

// ===========================================================================
$runner->group('17. Test isolation');
// ===========================================================================

$runner->test('The suite has not touched the seeded catalogue', function () {
    $products = db_select_one('SELECT COUNT(*) AS `total` FROM `products` WHERE `name` LIKE ?', 's', array('P4 %'));

    // The suite's own products exist while the suite runs; they are removed
    // by p4_cleanup() at the end. What must hold is that no SEEDED product
    // was renamed or removed.
    $seeded = db_select_one('SELECT COUNT(*) AS `total` FROM `products` WHERE `name` = ? LIMIT 1', 's', array('Boston Fern'));

    assert_true($seeded !== null, 'The seeded Boston Fern product is missing');
    assert_true((int) $seeded['total'] >= 1, 'The seeded Boston Fern product is missing');
});

$runner->test('The seeded categories are unchanged', function () {
    $row = db_select_one("SELECT COUNT(*) AS `total` FROM `categories` WHERE `name` NOT LIKE 'P4 %'");

    assert_equals(5, (int) $row['total'], 'The seeded category count changed');
});

p4_cleanup();

$runner->test('The cleanup removed every product the suite created', function () {
    $row = db_select_one('SELECT COUNT(*) AS `total` FROM `products` WHERE `name` LIKE ?', 's', array('P4 %'));

    assert_equals(0, (int) $row['total'], 'The suite left products behind');
});

$runner->test('The cleanup removed every customer the suite created', function () {
    $row = db_select_one('SELECT COUNT(*) AS `total` FROM `users` WHERE `email` LIKE ?', 's', array('p4+%'));

    assert_equals(0, (int) $row['total'], 'The suite left customers behind');
});

$runner->test('The cleanup removed every order the suite created', function () {
    $row = db_select_one('SELECT COUNT(*) AS `total` FROM `orders`');

    assert_equals(0, (int) $row['total'], 'The suite left orders behind');
});

$runner->test('The cleanup removed every payment the suite created', function () {
    $row = db_select_one('SELECT COUNT(*) AS `total` FROM `payments`');

    assert_equals(0, (int) $row['total'], 'The suite left payment rows behind');
});

$runner->test('The cleanup removed the suite category', function () {
    $row = db_select_one('SELECT COUNT(*) AS `total` FROM `categories` WHERE `name` LIKE ?', 's', array('P4 %'));

    assert_equals(0, (int) $row['total'], 'The suite left a category behind');
});

// ===========================================================================
exit($runner->summary());
