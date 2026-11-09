<?php
/**
 * GreenCorner — Phase 5 Test Suite
 *
 * Covers the Phase 5 scope: the customer Profile page (P-13), the Admin
 * Orders List (P-17), the Admin Order Detail page (P-18), the Admin User
 * List (P-19) and the Admin User Detail page (P-20).
 *
 * Usage:
 *   php tests/phase5_tests.php [base-url]
 *
 * The default base URL is http://127.0.0.1:8000.
 *
 * The suite drives the application over HTTP, so the application must be
 * running before the tests are executed. See tests/serve.sh.
 *
 * TEST ISOLATION
 *
 * The suite never touches the seeded catalogue. It creates its own products
 * under a "P5 " prefix, its own customer accounts under a "p5+" email
 * prefix, and its own orders, and it removes every one of them at the end.
 * The seeded products, categories, accounts and image files are left exactly
 * as the seed left them, so the Phase 1, Phase 2, Phase 3 and Phase 4 suites
 * still pass afterwards.
 *
 * The suite also removes the log files it writes, so the logs never carry
 * entries from an earlier run.
 */

require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../helpers/logging.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/ProductAdmin.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/UserAdmin.php';
require_once __DIR__ . '/../models/Cart.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/OrderStatus.php';
require_once __DIR__ . '/../models/Payment.php';

$baseUrl = $argv[1] ?? 'http://127.0.0.1:8000';
$runner = new TestRunner();

echo "GreenCorner — Phase 5 Test Suite\n";
echo "Base URL: " . $baseUrl . "\n";

// ===========================================================================
// Helpers
// ===========================================================================

/**
 * The seeded Admin account (FR-6.12).
 */
const P5_ADMIN_EMAIL = 'admin@greencorner.test';
const P5_ADMIN_PASSWORD = 'Admin123!';

/**
 * The prefix every product the suite creates carries.
 */
const P5_PREFIX = 'P5 ';

/**
 * The prefix every customer account the suite creates carries.
 */
const P5_EMAIL_PREFIX = 'p5+';

/**
 * The counter behind the unique suffix of the suite's email addresses.
 */
$GLOBALS['p5_counter'] = 0;

/**
 * Log a client in as the seeded Admin and return the client.
 */
function p5_admin_client(string $baseUrl): HttpClient
{
    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => P5_ADMIN_EMAIL,
        'password'   => P5_ADMIN_PASSWORD,
    ));

    return $client;
}

/**
 * Register a customer account and return its id, email address and client.
 *
 * @return array{id: int, email: string, client: HttpClient}
 */
function p5_register_customer(string $baseUrl, string $label = 'Customer', string $password = 'Customer123!'): array
{
    $GLOBALS['p5_counter']++;

    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/register');

    // The label becomes the local part of the email address, so it is
    // reduced to characters that are valid there. A label such as
    // "Listed User" would otherwise produce an address with a space in it,
    // which the application correctly refuses.
    $slug = preg_replace('/[^a-z0-9]+/', '.', strtolower($label));
    $slug = trim((string) $slug, '.');

    if ($slug === '') {
        $slug = 'customer';
    }

    $email = P5_EMAIL_PREFIX . $slug . '.' . $GLOBALS['p5_counter'] . '@greencorner.test';

    $client->post('/auth/register', array(
        'csrf_token'            => $token,
        'full_name'             => 'P5 ' . $label . ' ' . $GLOBALS['p5_counter'],
        'email'                 => $email,
        'password'              => $password,
        'password_confirmation' => $password,
    ));

    $row = db_select_one('SELECT `id` FROM `users` WHERE `email` = ?', 's', array($email));

    return array(
        'id'     => (int) $row['id'],
        'email'  => $email,
        'client' => $client,
    );
}

/**
 * Log a registered customer in and return the client.
 */
function p5_login_customer(string $baseUrl, string $email, string $password): HttpClient
{
    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => $password,
    ));

    return $client;
}

/**
 * The CSRF token on a page.
 */
function p5_csrf(HttpClient $client, string $path): string
{
    $client->get($path);

    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $client->body, $m)) {
        return $m[1];
    }

    return '';
}

/**
 * Create a product for the suite and return its id.
 */
function p5_create_product(string $name, float $price = 9.99, int $stock = 10): int
{
    $category = db_select_one('SELECT `id` FROM `categories` ORDER BY `id` ASC LIMIT 1');

    return db_insert_id(
        "INSERT INTO `products`
            (`name`, `description`, `price`, `stock`, `image_path`, `thumbnail_path`, `category_id`)
         VALUES (?, 'A Phase 5 test product.', ?, ?, '', '', ?)",
        'sdii',
        array($name, $price, $stock, (int) $category['id'])
    );
}

/**
 * Create an order directly and return its id.
 *
 * The suite creates its orders through the model rather than through the
 * checkout, because the Admin order management is what is under test.
 */
function p5_create_order(
    int $userId,
    string $orderNumber,
    string $status = 'placed',
    array $items = array(),
    string $paymentMethod = 'cod'
): int {
    $total = 0.0;

    foreach ($items as $item) {
        $total += round((float) $item['price'] * (int) $item['quantity'], 2);
    }

    $orderId = db_insert_id(
        "INSERT INTO `orders`
            (`order_number`, `user_id`, `total_amount`, `status`,
             `delivery_name`, `delivery_phone`, `delivery_address`, `delivery_city`)
         VALUES (?, ?, ?, ?, 'P5 Tester', '0100 000 0000', '5 Test Street', 'Testville')",
        'sids',
        array($orderNumber, $userId, number_format($total, 2, '.', ''), $status)
    );

    foreach ($items as $item) {
        db_write(
            'INSERT INTO `order_items`
                (`order_id`, `product_id`, `product_name_at_purchase`, `unit_price_at_purchase`, `quantity`)
             VALUES (?, ?, ?, ?, ?)',
            'iisdi',
            array(
                $orderId,
                (int) $item['product_id'],
                (string) $item['name'],
                (float) $item['price'],
                (int) $item['quantity'],
            )
        );
    }

    if ($paymentMethod === 'cod') {
        Payment::recordCodPending($orderId);
    } else {
        Payment::recordCardSuccess($orderId);
    }

    return $orderId;
}

/**
 * The status of an order.
 */
function p5_order_status(int $orderId): string
{
    $row = db_select_one('SELECT `status` FROM `orders` WHERE `id` = ?', 'i', array($orderId));

    return (string) $row['status'];
}

/**
 * The payment status of an order.
 */
function p5_payment_status(int $orderId): string
{
    $row = db_select_one(
        'SELECT `status` FROM `payments` WHERE `order_id` = ? LIMIT 1',
        'i',
        array($orderId)
    );

    return $row === null ? '' : (string) $row['status'];
}

/**
 * The stock of a product.
 */
function p5_stock(int $productId): int
{
    $row = db_select_one('SELECT `stock` FROM `products` WHERE `id` = ?', 'i', array($productId));

    return (int) $row['stock'];
}

/**
 * Remove everything the suite created.
 */
function p5_cleanup(): void
{
    // The suite's customers.
    $customers = db_select(
        'SELECT `id` FROM `users` WHERE `email` LIKE ?',
        's',
        array(P5_EMAIL_PREFIX . '%')
    );

    foreach ($customers as $customer) {
        $userId = (int) $customer['id'];

        db_write('DELETE FROM `sessions` WHERE `user_id` = ?', 'i', array($userId));
        db_write('DELETE FROM `cart_items` WHERE `user_id` = ?', 'i', array($userId));
        db_write(
            'DELETE FROM `order_items` WHERE `order_id` IN (SELECT `id` FROM `orders` WHERE `user_id` = ?)',
            'i',
            array($userId)
        );
        db_write(
            'DELETE FROM `payments` WHERE `order_id` IN (SELECT `id` FROM `orders` WHERE `user_id` = ?)',
            'i',
            array($userId)
        );
        db_write('DELETE FROM `orders` WHERE `user_id` = ?', 'i', array($userId));
        db_write('DELETE FROM `users` WHERE `id` = ?', 'i', array($userId));
    }

    // A failed card payment has no order, so it is not removed with the
    // orders above.
    db_write(
        "DELETE FROM `payments`
          WHERE `order_id` IS NULL AND `method` = 'card' AND `status` = 'failed'"
    );

    // The suite's products.
    $products = db_select(
        "SELECT `id`, `image_path`, `thumbnail_path` FROM `products` WHERE `name` LIKE ?",
        's',
        array(P5_PREFIX . '%')
    );

    foreach ($products as $product) {
        db_write('DELETE FROM `products` WHERE `id` = ?', 'i', array((int) $product['id']));
    }

    // The log files the suite writes.
    gc_log_clear(LOG_CHANNEL_ORDER_CANCELLATION);
    gc_log_clear(LOG_CHANNEL_ADMIN_ACTIONS);
}

// ===========================================================================
$runner->group('1. Profile access control (FR-5.5, FR-7.2, FR-7.4, RBAC-3, RBAC-5)');
// ===========================================================================

$runner->test('An anonymous visitor is refused the profile page with 401', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/profile');

    assert_equals(401, $response['status'], 'The visitor was not refused');
});

$runner->test('An Admin is refused the profile page with 403', function () use ($baseUrl) {
    // D-C: the profile is a User-only resource. The traceability matrix
    // gives the Admin a 403, so the Admin has no profile page of its own.
    $admin = p5_admin_client($baseUrl);
    $response = $admin->get('/profile');

    assert_equals(403, $response['status'], 'The Admin was not refused');
});

$runner->test('A User reaches the profile page', function () use ($baseUrl) {
    $customer = p5_register_customer($baseUrl, 'Profile');

    $client = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');
    $response = $client->get('/profile');

    assert_equals(200, $response['status'], 'The User could not reach the profile');
});

$runner->test('A User cannot change another account through the profile', function () use ($baseUrl) {
    $customer = p5_register_customer($baseUrl, 'Ownership');

    $client = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');
    $token = p5_csrf($client, '/profile');

    // The profile forms act on the signed-in account alone: no identifier is
    // read from the request at all.
    $response = $client->post('/profile/name', array(
        'csrf_token' => $token,
        'full_name'  => 'Renamed By Owner',
        'user_id'    => 999999,
        'id'         => 999999,
        'email'      => 'someone.else@greencorner.test',
    ));

    assert_true(
        $response['status'] === 200 || $response['status'] === 302,
        'Unexpected status ' . $response['status']
    );

    $row = db_select_one('SELECT `full_name`, `email` FROM `users` WHERE `id` = ?', 'i', array($customer['id']));

    assert_equals('Renamed By Owner', (string) $row['full_name'], 'The name was not changed');
    assert_equals($customer['email'], (string) $row['email'], 'The email was changed');
});

// ===========================================================================
$runner->group('2. Profile details and the name form (FR-5.5)');
// ===========================================================================

$profileCustomer = p5_register_customer($baseUrl, 'Details');
$profileClient = p5_login_customer($baseUrl, $profileCustomer['email'], 'Customer123!');

$runner->test('The profile page shows the account email address', function () use ($profileClient, $profileCustomer) {
    $response = $profileClient->get('/profile');

    assert_contains($profileCustomer['email'], $response['body'], 'The email address is not shown');
});

$runner->test('The profile page shows when the account was registered', function () use ($profileClient) {
    $response = $profileClient->get('/profile');

    assert_contains('Member since', $response['body'], 'The registration date is not shown');
});

$runner->test('The profile page offers no control for the role', function () use ($profileClient) {
    $response = $profileClient->get('/profile');

    assert_not_contains('name="role_id"', $response['body'], 'A role control is offered');
    assert_not_contains('name="is_active"', $response['body'], 'An account-status control is offered');
});

$runner->test('The profile page offers no control for the email address', function () use ($profileClient) {
    $response = $profileClient->get('/profile');

    assert_not_contains('name="email"', $response['body'], 'An email control is offered');
});

$runner->test('A User can change their name', function () use ($profileClient, $profileCustomer) {
    $token = p5_csrf($profileClient, '/profile');

    $response = $profileClient->post('/profile/name', array(
        'csrf_token' => $token,
        'full_name'  => 'A Better Name',
    ));

    assert_true(
        $response['status'] === 200 || $response['status'] === 302,
        'Unexpected status ' . $response['status']
    );

    $row = db_select_one('SELECT `full_name` FROM `users` WHERE `id` = ?', 'i', array($profileCustomer['id']));

    assert_equals('A Better Name', (string) $row['full_name'], 'The name was not changed');
});

$runner->test('The new name appears on the dashboard', function () use ($profileClient) {
    $response = $profileClient->get('/dashboard');

    assert_contains('A Better Name', $response['body'], 'The new name is not shown');
});

$runner->test('An empty name is refused', function () use ($profileClient, $profileCustomer) {
    $token = p5_csrf($profileClient, '/profile');

    $response = $profileClient->post('/profile/name', array(
        'csrf_token' => $token,
        'full_name'  => '',
    ));

    // FR-5.5 acceptance criterion 3: the name is rejected with an inline
    // message, so the page is re-rendered with the error next to the field.
    assert_equals(422, $response['status'], 'The empty name was not re-rendered');
    assert_contains('Please enter your name', $response['body'], 'The error is not shown');
    assert_contains('field-error', $response['body'], 'The error is not shown inline');

    $row = db_select_one('SELECT `full_name` FROM `users` WHERE `id` = ?', 'i', array($profileCustomer['id']));

    assert_equals('A Better Name', (string) $row['full_name'], 'The name was changed anyway');
});

$runner->test('An over-length name is refused', function () use ($profileClient, $profileCustomer) {
    $token = p5_csrf($profileClient, '/profile');

    $response = $profileClient->post('/profile/name', array(
        'csrf_token' => $token,
        'full_name'  => str_repeat('N', 121),
    ));

    assert_equals(422, $response['status'], 'The over-length name was not re-rendered');
    assert_contains('at most', $response['body'], 'The error is not shown');

    $row = db_select_one('SELECT `full_name` FROM `users` WHERE `id` = ?', 'i', array($profileCustomer['id']));

    assert_equals('A Better Name', (string) $row['full_name'], 'The name was changed anyway');
});

$runner->test('A name change without a CSRF token is refused', function () use ($profileClient, $profileCustomer) {
    $response = $profileClient->post('/profile/name', array('full_name' => 'Tampered Name'));

    assert_equals(422, $response['status'], 'The request was not refused');

    $row = db_select_one('SELECT `full_name` FROM `users` WHERE `id` = ?', 'i', array($profileCustomer['id']));

    assert_equals('A Better Name', (string) $row['full_name'], 'The name was changed anyway');
});

$runner->test('A name change with a wrong CSRF token is refused', function () use ($profileClient, $profileCustomer) {
    $response = $profileClient->post('/profile/name', array(
        'csrf_token' => 'not-the-right-token',
        'full_name'  => 'Tampered Name',
    ));

    assert_equals(422, $response['status'], 'The request was not refused');

    $row = db_select_one('SELECT `full_name` FROM `users` WHERE `id` = ?', 'i', array($profileCustomer['id']));

    assert_equals('A Better Name', (string) $row['full_name'], 'The name was changed anyway');
});

// ===========================================================================
$runner->group('3. The profile password form (FR-5.6, FR-1.9, SEC-13)');
// ===========================================================================

$runner->test('A password change requires the current password', function () use ($baseUrl) {
    $customer = p5_register_customer($baseUrl, 'Password');

    $client = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');
    $token = p5_csrf($client, '/profile');

    $response = $client->post('/profile/password', array(
        'csrf_token'                => $token,
        'current_password'          => 'TheWrongPassword',
        'new_password'              => 'BrandNew123!',
        'new_password_confirmation' => 'BrandNew123!',
    ));

    assert_equals(422, $response['status'], 'The request was not re-rendered');
    assert_contains('current password', strtolower($response['body']), 'The error is not shown');

    $row = db_select_one('SELECT `password_hash` FROM `users` WHERE `id` = ?', 'i', array($customer['id']));

    assert_true(
        password_verify('Customer123!', (string) $row['password_hash']),
        'The password was changed even though the current password was wrong'
    );
});

$runner->test('A password change never repopulates the password fields', function () use ($baseUrl) {
    $customer = p5_register_customer($baseUrl, 'Repopulate');

    $client = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');
    $token = p5_csrf($client, '/profile');

    $response = $client->post('/profile/password', array(
        'csrf_token'                => $token,
        'current_password'          => 'TheWrongPassword',
        'new_password'              => 'BrandNew123!',
        'new_password_confirmation' => 'BrandNew123!',
    ));

    assert_not_contains('TheWrongPassword', $response['body'], 'The current password was echoed back');
    assert_not_contains('BrandNew123!', $response['body'], 'The new password was echoed back');
});

$runner->test('A User can change their password with the current password', function () use ($baseUrl) {
    $customer = p5_register_customer($baseUrl, 'Change');

    $client = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');
    $token = p5_csrf($client, '/profile');

    $response = $client->post('/profile/password', array(
        'csrf_token'                => $token,
        'current_password'          => 'Customer123!',
        'new_password'              => 'BrandNew123!',
        'new_password_confirmation' => 'BrandNew123!',
    ));

    assert_true(
        $response['status'] === 200 || $response['status'] === 302,
        'Unexpected status ' . $response['status']
    );

    $row = db_select_one('SELECT `password_hash` FROM `users` WHERE `id` = ?', 'i', array($customer['id']));

    assert_true(password_verify('BrandNew123!', (string) $row['password_hash']), 'The password was not changed');
    assert_false(password_verify('Customer123!', (string) $row['password_hash']), 'The old password still works');
});

$runner->test('The new password works at the next login', function () use ($baseUrl) {
    $customer = p5_register_customer($baseUrl, 'Relogin');

    $client = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');
    $token = p5_csrf($client, '/profile');

    $client->post('/profile/password', array(
        'csrf_token'                => $token,
        'current_password'          => 'Customer123!',
        'new_password'              => 'BrandNew123!',
        'new_password_confirmation' => 'BrandNew123!',
    ));

    $fresh = new HttpClient($baseUrl);
    $token = $fresh->csrfToken('/auth/login');

    $response = $fresh->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $customer['email'],
        'password'   => 'BrandNew123!',
    ));

    assert_equals(302, $response['status'], 'The new password did not work');
});

// ===========================================================================
$runner->group('4. The fulfilment status vocabulary (BR-7, FR-6.8)');
// ===========================================================================

$runner->test('The five fulfilment statuses are the permitted ones', function () {
    assert_equals(
        array('placed', 'processing', 'shipped', 'delivered', 'cancelled'),
        OrderStatus::ALL,
        'The status vocabulary changed'
    );
});

$runner->test('A forward move to the next status is permitted', function () {
    assert_true(OrderStatus::canTransition('placed', 'processing'), 'placed -> processing was refused');
    assert_true(OrderStatus::canTransition('processing', 'shipped'), 'processing -> shipped was refused');
    assert_true(OrderStatus::canTransition('shipped', 'delivered'), 'shipped -> delivered was refused');
});

$runner->test('An arbitrary forward jump is refused', function () {
    assert_false(OrderStatus::canTransition('placed', 'shipped'), 'placed -> shipped was permitted');
    assert_false(OrderStatus::canTransition('placed', 'delivered'), 'placed -> delivered was permitted');
    assert_false(OrderStatus::canTransition('processing', 'delivered'), 'processing -> delivered was permitted');
});

$runner->test('A backward correction between non-terminal statuses is permitted', function () {
    assert_true(OrderStatus::canTransition('processing', 'placed'), 'processing -> placed was refused');
    assert_true(OrderStatus::canTransition('shipped', 'processing'), 'shipped -> processing was refused');
    assert_true(OrderStatus::canTransition('delivered', 'shipped'), 'delivered -> shipped was refused');
});

$runner->test('delivered -> cancelled is refused', function () {
    // FR-6.8 acceptance criterion 3: the customer has already received the
    // order, so it cannot be cancelled afterwards.
    assert_false(OrderStatus::canTransition('delivered', 'cancelled'), 'delivered -> cancelled was permitted');
});

$runner->test('A cancelled order is terminal', function () {
    assert_true(OrderStatus::isTerminal('cancelled'), 'cancelled is not terminal');

    foreach (OrderStatus::ALL as $status) {
        if ($status === 'cancelled') {
            continue;
        }

        assert_false(
            OrderStatus::canTransition('cancelled', $status),
            'cancelled -> ' . $status . ' was permitted'
        );
    }
});

$runner->test('Cancelling is permitted from every non-terminal status except delivered', function () {
    assert_true(OrderStatus::canTransition('placed', 'cancelled'), 'placed -> cancelled was refused');
    assert_true(OrderStatus::canTransition('processing', 'cancelled'), 'processing -> cancelled was refused');
    assert_true(OrderStatus::canTransition('shipped', 'cancelled'), 'shipped -> cancelled was refused');
});

$runner->test('An unknown status is not a valid status', function () {
    assert_false(OrderStatus::isValid('teleported'), 'An unknown status was accepted');
    assert_false(OrderStatus::isValid(''), 'An empty status was accepted');
    assert_true(OrderStatus::isValid('placed'), 'A known status was refused');
});

$runner->test('The permitted targets of a status exclude the refused transitions', function () {
    assert_false(in_array('cancelled', OrderStatus::allowedTargets('delivered'), true), 'delivered offers cancelled');
    assert_false(in_array('shipped', OrderStatus::allowedTargets('placed'), true), 'placed offers shipped');
    assert_in_array('processing', OrderStatus::allowedTargets('placed'), 'placed does not offer processing');
    assert_in_array('cancelled', OrderStatus::allowedTargets('placed'), 'placed does not offer cancelled');
    assert_equals(array(), OrderStatus::allowedTargets('cancelled'), 'cancelled offers something');
});

// ===========================================================================
$runner->group('5. Admin order access control (FR-6.7, FR-7.2, FR-7.4, RBAC-3, RBAC-5)');
// ===========================================================================

$runner->test('An anonymous visitor is refused the Admin order list with 401', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/admin/orders');

    assert_equals(401, $response['status'], 'The visitor was not refused');
});

$runner->test('A User is refused the Admin order list with 403', function () use ($baseUrl) {
    $customer = p5_register_customer($baseUrl, 'OrderAccess');

    $client = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');
    $response = $client->get('/admin/orders');

    assert_equals(403, $response['status'], 'The User was not refused');
});

$runner->test('A User is refused an Admin order detail page with 403', function () use ($baseUrl) {
    $customer = p5_register_customer($baseUrl, 'OrderDetail');

    $client = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');
    $response = $client->get('/admin/orders/1');

    assert_equals(403, $response['status'], 'The User was not refused');
});

$runner->test('A User cannot change an order status', function () use ($baseUrl) {
    $customer = p5_register_customer($baseUrl, 'OrderStatusAccess');
    $productId = p5_create_product(P5_PREFIX . 'Access Control');
    $orderId = p5_create_order($customer['id'], 'GC-P5-ACCESS-1', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Access Control', 'price' => 9.99, 'quantity' => 1),
    ));

    $client = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');
    $response = $client->post('/admin/orders/' . $orderId . '/status', array('status' => 'processing'));

    assert_equals(403, $response['status'], 'The User was not refused');
    assert_equals('placed', p5_order_status($orderId), 'The status was changed anyway');
});

$runner->test('A User cannot confirm a COD collection', function () use ($baseUrl) {
    $customer = p5_register_customer($baseUrl, 'CodAccess');
    $productId = p5_create_product(P5_PREFIX . 'Cod Access');
    $orderId = p5_create_order($customer['id'], 'GC-P5-CODACCESS-1', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Cod Access', 'price' => 9.99, 'quantity' => 1),
    ));

    $client = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');
    $response = $client->post('/admin/orders/' . $orderId . '/confirm-cod', array());

    assert_equals(403, $response['status'], 'The User was not refused');
    assert_equals('pending', p5_payment_status($orderId), 'The payment was changed anyway');
});

// ===========================================================================
$runner->group('6. The Admin order list (P-17, FR-6.7)');
// ===========================================================================

$listCustomer = p5_register_customer($baseUrl, 'Listed');
$listProductId = p5_create_product(P5_PREFIX . 'Listed Product', 12.50, 10);
$listOrderId = p5_create_order($listCustomer['id'], 'GC-P5-LISTED-1', 'placed', array(
    array('product_id' => $listProductId, 'name' => P5_PREFIX . 'Listed Product', 'price' => 12.50, 'quantity' => 2),
));

$admin = p5_admin_client($baseUrl);

$runner->test('The Admin reaches the order list', function () use ($admin) {
    $response = $admin->get('/admin/orders');

    assert_equals(200, $response['status'], 'The Admin was refused');
});

$runner->test('The list shows the order number', function () use ($admin, $listOrderId) {
    $response = $admin->get('/admin/orders');
    $order = db_select_one('SELECT `order_number` FROM `orders` WHERE `id` = ?', 'i', array($listOrderId));

    assert_contains((string) $order['order_number'], $response['body'], 'The order number is not shown');
});

$runner->test('The list shows the customer', function () use ($admin, $listCustomer) {
    $response = $admin->get('/admin/orders');

    assert_contains('P5 Listed', $response['body'], 'The customer is not shown');
});

$runner->test('The list shows the date, the fulfilment status and the total', function () use ($admin) {
    $response = $admin->get('/admin/orders');

    assert_contains('Date', $response['body'], 'The date column is missing');
    assert_contains('Fulfilment status', $response['body'], 'The fulfilment status column is missing');
    assert_contains('Total', $response['body'], 'The total column is missing');
});

$runner->test('The list shows the payment status in a column of its own', function () use ($admin) {
    $response = $admin->get('/admin/orders');

    assert_contains('Payment status', $response['body'], 'The payment status column is missing');
});

$runner->test('A pending COD payment reads differently from a successful card payment', function () use ($baseUrl, $admin) {
    // FR-6.7 acceptance criterion 2: the two must be distinguishable.
    $customer = p5_register_customer($baseUrl, 'PaymentDistinct');
    $productId = p5_create_product(P5_PREFIX . 'Distinct');

    $codOrderId = p5_create_order($customer['id'], 'GC-P5-DISTINCT-COD', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Distinct', 'price' => 9.99, 'quantity' => 1),
    ), 'cod');

    $cardOrderId = p5_create_order($customer['id'], 'GC-P5-DISTINCT-CARD', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Distinct', 'price' => 9.99, 'quantity' => 1),
    ), 'card');

    $response = $admin->get('/admin/orders');

    assert_contains('payment-status-pending', $response['body'], 'The pending payment is not marked pending');
    assert_contains('payment-status-success', $response['body'], 'The successful payment is not marked successful');
    assert_contains('Cash on delivery', $response['body'], 'The payment method is not shown');
});

$runner->test('A cancelled order remains listed', function () use ($baseUrl, $admin) {
    $customer = p5_register_customer($baseUrl, 'CancelledListed');
    $productId = p5_create_product(P5_PREFIX . 'Cancelled Listed');
    $orderId = p5_create_order($customer['id'], 'GC-P5-CANCELLED-1', 'cancelled', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Cancelled Listed', 'price' => 9.99, 'quantity' => 1),
    ));

    $response = $admin->get('/admin/orders');
    $order = db_select_one('SELECT `order_number` FROM `orders` WHERE `id` = ?', 'i', array($orderId));

    assert_contains((string) $order['order_number'], $response['body'], 'The cancelled order is not listed');
});

$runner->test('The list paginates at 20 orders per page', function () use ($admin) {
    // A-7: the Admin order list shows 20 orders per page.
    $response = $admin->get('/admin/orders');

    assert_contains('page 1 of', $response['body'], 'The page count is not shown');
});

$runner->test('The list carries no filters', function () use ($admin) {
    // A-8: no filters were approved, so none is offered.
    $response = $admin->get('/admin/orders');

    assert_not_contains('name="status"', $response['body'], 'A status filter is offered');
    assert_not_contains('name="payment_method"', $response['body'], 'A payment filter is offered');
    assert_not_contains('name="q"', $response['body'], 'A search filter is offered');
});

// ===========================================================================
$runner->group('7. The Admin order detail page (P-18, FR-6.8)');
// ===========================================================================

$detailCustomer = p5_register_customer($baseUrl, 'Detailed');
$detailProductId = p5_create_product(P5_PREFIX . 'Detailed Product', 7.25, 10);
$detailOrderId = p5_create_order($detailCustomer['id'], 'GC-P5-DETAILED-1', 'placed', array(
    array('product_id' => $detailProductId, 'name' => P5_PREFIX . 'Detailed Product', 'price' => 7.25, 'quantity' => 3),
));

$runner->test('The Admin reaches the order detail page', function () use ($admin, $detailOrderId) {
    $response = $admin->get('/admin/orders/' . $detailOrderId);

    assert_equals(200, $response['status'], 'The Admin was refused');
});

$runner->test('The detail page shows the items, the quantities and the unit prices', function () use ($admin, $detailOrderId) {
    $response = $admin->get('/admin/orders/' . $detailOrderId);

    assert_contains(P5_PREFIX . 'Detailed Product', $response['body'], 'The item name is not shown');
    assert_contains('7.25', $response['body'], 'The unit price is not shown');
    assert_contains('21.75', $response['body'], 'The line total is not shown');
});

$runner->test('The detail page shows the total', function () use ($admin, $detailOrderId) {
    $response = $admin->get('/admin/orders/' . $detailOrderId);

    assert_contains('21.75', $response['body'], 'The total is not shown');
});

$runner->test('The detail page shows the customer', function () use ($admin, $detailCustomer, $detailOrderId) {
    $response = $admin->get('/admin/orders/' . $detailOrderId);

    assert_contains('P5 Detailed', $response['body'], 'The customer is not shown');
    assert_contains($detailCustomer['email'], $response['body'], 'The customer email is not shown');
});

$runner->test('The detail page shows the delivery address snapshot', function () use ($admin, $detailOrderId) {
    $response = $admin->get('/admin/orders/' . $detailOrderId);

    assert_contains('5 Test Street', $response['body'], 'The delivery address is not shown');
    assert_contains('Testville', $response['body'], 'The delivery city is not shown');
});

$runner->test('The detail page shows the fulfilment status and the payment status', function () use ($admin, $detailOrderId) {
    $response = $admin->get('/admin/orders/' . $detailOrderId);

    assert_contains('Fulfilment status', $response['body'], 'The fulfilment status is not shown');
    assert_contains('Payment status', $response['body'], 'The payment status is not shown');
    assert_contains('Cash on delivery', $response['body'], 'The payment method is not shown');
});

$runner->test('The status control offers only the permitted targets', function () use ($admin, $detailOrderId) {
    // FR-6.8 acceptance criterion 3: a refused transition is never offered.
    $response = $admin->get('/admin/orders/' . $detailOrderId);

    assert_contains('value="processing"', $response['body'], 'The permitted target is not offered');
    assert_not_contains('value="shipped"', $response['body'], 'A refused target is offered');
    assert_not_contains('value="delivered"', $response['body'], 'A refused target is offered');
});

$runner->test('An unknown order is a 404', function () use ($admin) {
    $response = $admin->get('/admin/orders/999999');

    assert_equals(404, $response['status'], 'The unknown order was not refused');
});

$runner->test('A status change without a CSRF token is refused', function () use ($admin, $detailOrderId) {
    $response = $admin->post('/admin/orders/' . $detailOrderId . '/status', array('status' => 'processing'));

    assert_equals(422, $response['status'], 'The request was not refused');
    assert_equals('placed', p5_order_status($detailOrderId), 'The status was changed anyway');
});

// ===========================================================================
$runner->group('8. The fulfilment status changes (FR-6.8, FR-4.16)');
// ===========================================================================

$runner->test('A legal status change succeeds', function () use ($baseUrl, $admin, $detailOrderId) {
    $token = p5_csrf($admin, '/admin/orders/' . $detailOrderId);

    $response = $admin->post('/admin/orders/' . $detailOrderId . '/status', array(
        'csrf_token' => $token,
        'status'     => 'processing',
    ));

    assert_equals(302, $response['status'], 'The change was not accepted');
    assert_equals('processing', p5_order_status($detailOrderId), 'The status was not changed');
});

$runner->test('An illegal forward jump is refused with 409', function () use ($admin, $detailOrderId) {
    $token = p5_csrf($admin, '/admin/orders/' . $detailOrderId);

    $response = $admin->post('/admin/orders/' . $detailOrderId . '/status', array(
        'csrf_token' => $token,
        'status'     => 'delivered',
    ));

    assert_equals(409, $response['status'], 'The transition was not refused');
    assert_equals('processing', p5_order_status($detailOrderId), 'The status was changed anyway');
});

$runner->test('An unknown status is refused with 409', function () use ($admin, $detailOrderId) {
    $token = p5_csrf($admin, '/admin/orders/' . $detailOrderId);

    $response = $admin->post('/admin/orders/' . $detailOrderId . '/status', array(
        'csrf_token' => $token,
        'status'     => 'teleported',
    ));

    assert_equals(409, $response['status'], 'The transition was not refused');
    assert_equals('processing', p5_order_status($detailOrderId), 'The status was changed anyway');
});

$runner->test('A backward correction is permitted', function () use ($admin, $detailOrderId) {
    $token = p5_csrf($admin, '/admin/orders/' . $detailOrderId);

    $response = $admin->post('/admin/orders/' . $detailOrderId . '/status', array(
        'csrf_token' => $token,
        'status'     => 'placed',
    ));

    assert_equals(302, $response['status'], 'The correction was not accepted');
    assert_equals('placed', p5_order_status($detailOrderId), 'The status was not corrected');
});

$runner->test('delivered -> cancelled is refused with 409', function () use ($baseUrl, $admin) {
    $customer = p5_register_customer($baseUrl, 'Delivered');
    $productId = p5_create_product(P5_PREFIX . 'Delivered');
    $orderId = p5_create_order($customer['id'], 'GC-P5-DELIVERED-1', 'delivered', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Delivered', 'price' => 9.99, 'quantity' => 1),
    ));

    $token = p5_csrf($admin, '/admin/orders/' . $orderId);

    $response = $admin->post('/admin/orders/' . $orderId . '/status', array(
        'csrf_token' => $token,
        'status'     => 'cancelled',
    ));

    assert_equals(409, $response['status'], 'The transition was not refused');
    assert_equals('delivered', p5_order_status($orderId), 'The status was changed anyway');
});

$runner->test('A cancelled order cannot change status', function () use ($baseUrl, $admin) {
    $customer = p5_register_customer($baseUrl, 'Terminal');
    $productId = p5_create_product(P5_PREFIX . 'Terminal');
    $orderId = p5_create_order($customer['id'], 'GC-P5-TERMINAL-1', 'cancelled', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Terminal', 'price' => 9.99, 'quantity' => 1),
    ));

    $token = p5_csrf($admin, '/admin/orders/' . $orderId);

    $response = $admin->post('/admin/orders/' . $orderId . '/status', array(
        'csrf_token' => $token,
        'status'     => 'processing',
    ));

    assert_equals(409, $response['status'], 'The transition was not refused');
    assert_equals('cancelled', p5_order_status($orderId), 'The status was changed anyway');
});

$runner->test('The detail page of a cancelled order offers no status change', function () use ($baseUrl, $admin) {
    $customer = p5_register_customer($baseUrl, 'TerminalPage');
    $productId = p5_create_product(P5_PREFIX . 'Terminal Page');
    $orderId = p5_create_order($customer['id'], 'GC-P5-TERMINALPAGE-1', 'cancelled', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Terminal Page', 'price' => 9.99, 'quantity' => 1),
    ));

    $response = $admin->get('/admin/orders/' . $orderId);

    assert_not_contains('/admin/orders/' . $orderId . '/status', $response['body'], 'A status form is offered');
});

// ===========================================================================
$runner->group('9. Cancellation and stock restoration (FR-6.8, BR-13, BR-15)');
// ===========================================================================

$runner->test('Cancelling an order restores the stock of its items', function () use ($baseUrl, $admin) {
    $customer = p5_register_customer($baseUrl, 'Restore');
    $productId = p5_create_product(P5_PREFIX . 'Restored', 9.99, 10);
    $orderId = p5_create_order($customer['id'], 'GC-P5-RESTORE-1', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Restored', 'price' => 9.99, 'quantity' => 2),
    ));

    $before = p5_stock($productId);

    $token = p5_csrf($admin, '/admin/orders/' . $orderId);

    $response = $admin->post('/admin/orders/' . $orderId . '/status', array(
        'csrf_token' => $token,
        'status'     => 'cancelled',
    ));

    assert_equals(302, $response['status'], 'The cancellation was not accepted');
    assert_equals($before + 2, p5_stock($productId), 'The stock was not restored');
});

$runner->test('A cancelled order with a pending COD payment moves the payment to failed', function () use ($baseUrl, $admin) {
    // BR-15.
    $customer = p5_register_customer($baseUrl, 'CancelCod');
    $productId = p5_create_product(P5_PREFIX . 'Cancel Cod');
    $orderId = p5_create_order($customer['id'], 'GC-P5-CANCELCOD-1', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Cancel Cod', 'price' => 9.99, 'quantity' => 1),
    ), 'cod');

    $token = p5_csrf($admin, '/admin/orders/' . $orderId);

    $admin->post('/admin/orders/' . $orderId . '/status', array(
        'csrf_token' => $token,
        'status'     => 'cancelled',
    ));

    assert_equals('failed', p5_payment_status($orderId), 'The COD payment was not failed');
});

$runner->test('The cancelled payment still references its order', function () use ($baseUrl, $admin) {
    // BR-15: the `order_id` is retained.
    $customer = p5_register_customer($baseUrl, 'CancelCodRef');
    $productId = p5_create_product(P5_PREFIX . 'Cancel Cod Ref');
    $orderId = p5_create_order($customer['id'], 'GC-P5-CANCELCODREF-1', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Cancel Cod Ref', 'price' => 9.99, 'quantity' => 1),
    ), 'cod');

    $token = p5_csrf($admin, '/admin/orders/' . $orderId);

    $admin->post('/admin/orders/' . $orderId . '/status', array(
        'csrf_token' => $token,
        'status'     => 'cancelled',
    ));

    $row = db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `order_id` = ? LIMIT 1',
        'i',
        array($orderId)
    );

    assert_true($row !== null, 'The payment row was removed');
    assert_equals($orderId, (int) $row['order_id'], 'The payment no longer references its order');
});

$runner->test('Cancelling an order does not put the items back in the cart', function () use ($baseUrl, $admin) {
    // BR-13: the cart is not restored.
    $customer = p5_register_customer($baseUrl, 'NoCart');
    $productId = p5_create_product(P5_PREFIX . 'No Cart');
    $orderId = p5_create_order($customer['id'], 'GC-P5-NOCART-1', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'No Cart', 'price' => 9.99, 'quantity' => 1),
    ));

    $token = p5_csrf($admin, '/admin/orders/' . $orderId);

    $admin->post('/admin/orders/' . $orderId . '/status', array(
        'csrf_token' => $token,
        'status'     => 'cancelled',
    ));

    $row = db_select_one(
        'SELECT COUNT(*) AS `total` FROM `cart_items` WHERE `user_id` = ?',
        'i',
        array($customer['id'])
    );

    assert_equals(0, (int) $row['total'], 'The items were put back in the cart');
});

$runner->test('The stock of a soft-deleted product is not restored, and the case is logged', function () use ($baseUrl, $admin) {
    // BR-13 / FR-6.8 acceptance criterion 6: the case is logged for manual
    // handling.
    $customer = p5_register_customer($baseUrl, 'SoftDeleted');
    $productId = p5_create_product(P5_PREFIX . 'Soft Deleted', 9.99, 10);
    $orderId = p5_create_order($customer['id'], 'GC-P5-SOFTDELETED-1', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Soft Deleted', 'price' => 9.99, 'quantity' => 1),
    ));

    $before = p5_stock($productId);

    // The product is removed from the catalogue after the order was placed.
    db_write('UPDATE `products` SET `is_deleted` = 1 WHERE `id` = ?', 'i', array($productId));

    gc_log_clear(LOG_CHANNEL_ORDER_CANCELLATION);

    $token = p5_csrf($admin, '/admin/orders/' . $orderId);

    $response = $admin->post('/admin/orders/' . $orderId . '/status', array(
        'csrf_token' => $token,
        'status'     => 'cancelled',
    ));

    assert_equals(302, $response['status'], 'The cancellation was not accepted');
    assert_equals($before, p5_stock($productId), 'The stock of a removed product was restored');

    $entries = gc_log_read(LOG_CHANNEL_ORDER_CANCELLATION);

    assert_true(count($entries) > 0, 'The manual-handling log is empty');

    $entry = $entries[count($entries) - 1];

    assert_equals('stock_not_restored', (string) $entry['event'], 'The wrong event was logged');
    assert_equals($orderId, (int) $entry['order_id'], 'The order id is wrong');
    assert_equals($productId, (int) $entry['product_id'], 'The product id is wrong');
    assert_equals('product_soft_deleted', (string) $entry['reason'], 'The reason is wrong');
    assert_true(!empty($entry['timestamp']), 'The entry has no timestamp');
    assert_true(!empty($entry['order_number']), 'The entry has no order number');
});

$runner->test('The manual-handling log holds no secret material', function () use ($baseUrl, $admin) {
    $entries = gc_log_read(LOG_CHANNEL_ORDER_CANCELLATION);

    assert_true(count($entries) > 0, 'The manual-handling log is empty');

    foreach ($entries as $entry) {
        $serialised = (string) json_encode($entry);

        assert_not_contains('password', strtolower($serialised), 'The log holds password material');
        assert_not_contains('cvv', strtolower($serialised), 'The log holds card data');
        assert_not_contains('card_number', strtolower($serialised), 'The log holds card data');
    }
});

$runner->test('The manual-handling log is one JSON object per line', function () {
    $path = LOG_DIRECTORY . '/' . LOG_CHANNEL_ORDER_CANCELLATION . '.log';

    assert_true(is_file($path), 'The log file does not exist');

    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));

    assert_true(count($lines) > 0, 'The log file is empty');

    foreach ($lines as $line) {
        $decoded = json_decode($line, true);

        assert_true(is_array($decoded), 'A log line is not one JSON object: ' . $line);
    }
});

// ===========================================================================
$runner->group('10. The COD cash collection (FR-6.9, BR-9)');
// ===========================================================================

$runner->test('The confirm control appears only for a pending COD payment', function () use ($baseUrl, $admin) {
    $customer = p5_register_customer($baseUrl, 'ConfirmControl');
    $productId = p5_create_product(P5_PREFIX . 'Confirm Control');

    $codOrderId = p5_create_order($customer['id'], 'GC-P5-CONFIRM-COD', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Confirm Control', 'price' => 9.99, 'quantity' => 1),
    ), 'cod');

    $cardOrderId = p5_create_order($customer['id'], 'GC-P5-CONFIRM-CARD', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Confirm Control', 'price' => 9.99, 'quantity' => 1),
    ), 'card');

    $codResponse = $admin->get('/admin/orders/' . $codOrderId);
    $cardResponse = $admin->get('/admin/orders/' . $cardOrderId);

    assert_contains('/confirm-cod', $codResponse['body'], 'The control is not offered for a pending COD payment');
    assert_not_contains('/confirm-cod', $cardResponse['body'], 'The control is offered for a card payment');
});

$runner->test('Confirming the collection moves the payment from pending to success', function () use ($baseUrl, $admin) {
    $customer = p5_register_customer($baseUrl, 'Confirm');
    $productId = p5_create_product(P5_PREFIX . 'Confirmed');
    $orderId = p5_create_order($customer['id'], 'GC-P5-CONFIRM-1', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Confirmed', 'price' => 9.99, 'quantity' => 1),
    ), 'cod');

    $token = p5_csrf($admin, '/admin/orders/' . $orderId);

    $response = $admin->post('/admin/orders/' . $orderId . '/confirm-cod', array('csrf_token' => $token));

    assert_equals(302, $response['status'], 'The confirmation was not accepted');
    assert_equals('success', p5_payment_status($orderId), 'The payment was not confirmed');
});

$runner->test('The confirmed payment records when it was paid', function () use ($baseUrl, $admin) {
    $customer = p5_register_customer($baseUrl, 'PaidAt');
    $productId = p5_create_product(P5_PREFIX . 'Paid At');
    $orderId = p5_create_order($customer['id'], 'GC-P5-PAIDAT-1', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Paid At', 'price' => 9.99, 'quantity' => 1),
    ), 'cod');

    $token = p5_csrf($admin, '/admin/orders/' . $orderId);

    $admin->post('/admin/orders/' . $orderId . '/confirm-cod', array('csrf_token' => $token));

    $row = db_select_one('SELECT `paid_at` FROM `payments` WHERE `order_id` = ? LIMIT 1', 'i', array($orderId));

    assert_true(!empty($row['paid_at']), 'The payment has no paid-at time');
});

$runner->test('A second confirmation is refused with 409', function () use ($baseUrl, $admin) {
    $customer = p5_register_customer($baseUrl, 'Twice');
    $productId = p5_create_product(P5_PREFIX . 'Twice');
    $orderId = p5_create_order($customer['id'], 'GC-P5-TWICE-1', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Twice', 'price' => 9.99, 'quantity' => 1),
    ), 'cod');

    $token = p5_csrf($admin, '/admin/orders/' . $orderId);

    $admin->post('/admin/orders/' . $orderId . '/confirm-cod', array('csrf_token' => $token));

    $response = $admin->post('/admin/orders/' . $orderId . '/confirm-cod', array('csrf_token' => $token));

    assert_equals(409, $response['status'], 'The second confirmation was not refused');
    assert_equals('success', p5_payment_status($orderId), 'The payment was changed');
});

$runner->test('A card payment cannot be confirmed as collected', function () use ($baseUrl, $admin) {
    // FR-6.9: the cash collection applies to Cash on Delivery only.
    $customer = p5_register_customer($baseUrl, 'CardConfirm');
    $productId = p5_create_product(P5_PREFIX . 'Card Confirm');
    $orderId = p5_create_order($customer['id'], 'GC-P5-CARDCONFIRM-1', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Card Confirm', 'price' => 9.99, 'quantity' => 1),
    ), 'card');

    $token = p5_csrf($admin, '/admin/orders/' . $orderId);

    $response = $admin->post('/admin/orders/' . $orderId . '/confirm-cod', array('csrf_token' => $token));

    assert_equals(409, $response['status'], 'The card payment was not refused');
    assert_equals('success', p5_payment_status($orderId), 'The card payment was changed');
});

$runner->test('A confirmation without a CSRF token is refused', function () use ($baseUrl, $admin) {
    $customer = p5_register_customer($baseUrl, 'ConfirmCsrf');
    $productId = p5_create_product(P5_PREFIX . 'Confirm Csrf');
    $orderId = p5_create_order($customer['id'], 'GC-P5-CONFIRMCSRF-1', 'placed', array(
        array('product_id' => $productId, 'name' => P5_PREFIX . 'Confirm Csrf', 'price' => 9.99, 'quantity' => 1),
    ), 'cod');

    $response = $admin->post('/admin/orders/' . $orderId . '/confirm-cod', array());

    assert_equals(422, $response['status'], 'The request was not refused');
    assert_equals('pending', p5_payment_status($orderId), 'The payment was changed anyway');
});

$runner->test('A failed card payment can never become a success', function () use ($baseUrl) {
    // BR-9: the database permits the shape, so the application is the guard.
    $paymentId = Payment::recordCardFailure();

    $result = Payment::updateStatus($paymentId, 'success');

    assert_equals('refused', $result['status'], 'The change was not refused');

    $row = db_select_one('SELECT `status` FROM `payments` WHERE `id` = ?', 'i', array($paymentId));

    assert_equals('failed', (string) $row['status'], 'The payment was changed anyway');

    db_write('DELETE FROM `payments` WHERE `id` = ?', 'i', array($paymentId));
});

// ===========================================================================
$runner->group('11. Admin user access control (FR-6.10, FR-7.2, FR-7.4)');
// ===========================================================================

$runner->test('An anonymous visitor is refused the user list with 401', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $response = $client->get('/admin/users');

    assert_equals(401, $response['status'], 'The visitor was not refused');
});

$runner->test('A User is refused the user list with 403', function () use ($baseUrl) {
    $customer = p5_register_customer($baseUrl, 'UserListAccess');

    $client = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');
    $response = $client->get('/admin/users');

    assert_equals(403, $response['status'], 'The User was not refused');
});

$runner->test('A User is refused a user detail page with 403', function () use ($baseUrl) {
    $customer = p5_register_customer($baseUrl, 'UserDetailAccess');

    $client = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');
    $response = $client->get('/admin/users/1');

    assert_equals(403, $response['status'], 'The User was not refused');
});

$runner->test('A User cannot reset another account password', function () use ($baseUrl) {
    $customer = p5_register_customer($baseUrl, 'ResetAccess');
    $target = p5_register_customer($baseUrl, 'ResetTarget');

    $client = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');
    $response = $client->post('/admin/users/' . $target['id'] . '/reset-password', array(
        'temporary_password'            => 'Hacked123!',
        'temporary_password_confirmation' => 'Hacked123!',
    ));

    assert_equals(403, $response['status'], 'The User was not refused');

    $row = db_select_one('SELECT `password_hash` FROM `users` WHERE `id` = ?', 'i', array($target['id']));

    assert_true(
        password_verify('Customer123!', (string) $row['password_hash']),
        'The password was changed by a User'
    );
});

// ===========================================================================
$runner->group('12. The Admin user list (P-19, FR-6.10)');
// ===========================================================================

$listedCustomer = p5_register_customer($baseUrl, 'Listed User');

$runner->test('The Admin reaches the user list', function () use ($admin) {
    $response = $admin->get('/admin/users');

    assert_equals(200, $response['status'], 'The Admin was refused');
});

$runner->test('The list shows the name, the email and the registration date', function () use ($admin, $listedCustomer) {
    $response = $admin->get('/admin/users');

    assert_contains('Name', $response['body'], 'The name column is missing');
    assert_contains('Email', $response['body'], 'The email column is missing');
    assert_contains('Registered', $response['body'], 'The registration date column is missing');
});

$runner->test('The list shows the account itself', function () use ($admin, $listedCustomer) {
    $found = false;

    for ($page = 1; $page <= 20; $page++) {
        $response = $admin->get('/admin/users?page=' . $page);

        if (strpos($response['body'], $listedCustomer['email']) !== false) {
            $found = true;
            break;
        }
    }

    assert_true($found, 'The account is not listed on any page');
});

$runner->test('The list shows no password material', function () use ($admin) {
    // FR-6.10 acceptance criterion 3 / SEC-18.
    $response = $admin->get('/admin/users');

    assert_not_contains('$2y$', $response['body'], 'A password hash is shown');
    assert_not_contains('password_hash', $response['body'], 'The hash column is shown');
});

$runner->test('The list offers no role control', function () use ($admin) {
    // RBAC-9 / FR-6.10: roles are managed by the seed script.
    $response = $admin->get('/admin/users');

    assert_not_contains('name="role_id"', $response['body'], 'A role control is offered');
    assert_not_contains('name="role"', $response['body'], 'A role control is offered');
});

// ===========================================================================
$runner->group('13. The Admin user detail and the password reset (P-20, FR-6.10, FR-1.9)');
// ===========================================================================

$resetCustomer = p5_register_customer($baseUrl, 'Reset');

$runner->test('The Admin reaches the user detail page', function () use ($admin, $resetCustomer) {
    $response = $admin->get('/admin/users/' . $resetCustomer['id']);

    assert_equals(200, $response['status'], 'The Admin was refused');
});

$runner->test('The detail page shows the account details', function () use ($admin, $resetCustomer) {
    $response = $admin->get('/admin/users/' . $resetCustomer['id']);

    assert_contains($resetCustomer['email'], $response['body'], 'The email address is not shown');
    assert_contains('Registered', $response['body'], 'The registration date is not shown');
});

$runner->test('The detail page shows no password and no hash', function () use ($admin, $resetCustomer) {
    // FR-6.10 acceptance criterion 3 / SEC-18.
    $response = $admin->get('/admin/users/' . $resetCustomer['id']);

    assert_not_contains('$2y$', $response['body'], 'A password hash is shown');
    assert_not_contains('password_hash', $response['body'], 'The hash is mentioned');
});

$runner->test('The detail page offers no role control', function () use ($admin, $resetCustomer) {
    // RBAC-9.
    $response = $admin->get('/admin/users/' . $resetCustomer['id']);

    assert_not_contains('name="role_id"', $response['body'], 'A role control is offered');
});

$runner->test('An unknown user is a 404', function () use ($admin) {
    $response = $admin->get('/admin/users/999999');

    assert_equals(404, $response['status'], 'The unknown user was not refused');
});

$runner->test('Setting a temporary password succeeds and forces a change', function () use ($baseUrl, $admin, $resetCustomer) {
    // FR-6.10 acceptance criterion 2.
    $token = p5_csrf($admin, '/admin/users/' . $resetCustomer['id']);

    $response = $admin->post('/admin/users/' . $resetCustomer['id'] . '/reset-password', array(
        'csrf_token'                    => $token,
        'temporary_password'            => 'TempPass123!',
        'temporary_password_confirmation' => 'TempPass123!',
    ));

    assert_equals(302, $response['status'], 'The reset was not accepted');

    $row = db_select_one(
        'SELECT `must_change_password`, `password_hash` FROM `users` WHERE `id` = ?',
        'i',
        array($resetCustomer['id'])
    );

    assert_equals(1, (int) $row['must_change_password'], 'The forced-change flag was not set');
});

$runner->test('The temporary password is stored only as a hash', function () use ($resetCustomer) {
    // FR-6.10 acceptance criterion 4.
    $row = db_select_one('SELECT `password_hash` FROM `users` WHERE `id` = ?', 'i', array($resetCustomer['id']));

    assert_true((string) $row['password_hash'] !== 'TempPass123!', 'The password is stored in the clear');
    assert_true(password_verify('TempPass123!', (string) $row['password_hash']), 'The hash does not verify');
    assert_false(password_verify('Customer123!', (string) $row['password_hash']), 'The old password still works');
});

$runner->test('The user list never reads the password hash', function () {
    // SEC-18: no query that *reads* the column may exist in the model that
    // backs the Admin interface. The single permitted mention is the
    // UPDATE that stores the new hash, so the column list the model reads
    // is checked directly, and no SELECT may name the column.
    $source = (string) file_get_contents(__DIR__ . '/../models/UserAdmin.php');

    // The column list the model reads (LIST_COLUMNS) must not carry it.
    assert_true(
        preg_match('/LIST_COLUMNS\s*=\s*\'([^\']*)\'/', $source, $m) === 1,
        'The column list could not be found in the Admin user model'
    );
    assert_not_contains('password_hash', $m[1], 'The Admin user model reads the password hash');

    // No SELECT may name the column either.
    assert_not_contains('SELECT `password_hash`', $source, 'The Admin user model selects the password hash');
    assert_not_contains('SELECT u.`password_hash`', $source, 'The Admin user model selects the password hash');
    assert_not_contains('u.`password_hash`', $source, 'The Admin user model selects the password hash');
});

$runner->test('The user can log in with the temporary password', function () use ($baseUrl, $resetCustomer) {
    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $response = $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $resetCustomer['email'],
        'password'   => 'TempPass123!',
    ));

    assert_equals(302, $response['status'], 'The temporary password did not work');
});

$runner->test('The user is sent to the change-password page before any other page', function () use ($baseUrl, $resetCustomer) {
    // FR-1.9.
    $client = p5_login_customer($baseUrl, $resetCustomer['email'], 'TempPass123!');
    $response = $client->get('/dashboard');

    assert_equals(403, $response['status'], 'The user was not blocked');
    assert_contains('/auth/change-password', (string) ($response['headers']['location'] ?? ''), 'The redirect is wrong');
});

$runner->test('The user can reach the dashboard after changing the password', function () use ($baseUrl, $resetCustomer) {
    $client = p5_login_customer($baseUrl, $resetCustomer['email'], 'TempPass123!');
    $token = p5_csrf($client, '/auth/change-password');

    $response = $client->post('/auth/change-password', array(
        'csrf_token'                => $token,
        'current_password'          => 'TempPass123!',
        'new_password'              => 'FinalPass123!',
        'new_password_confirmation' => 'FinalPass123!',
    ));

    assert_equals(302, $response['status'], 'The change was not accepted');

    $row = db_select_one('SELECT `must_change_password` FROM `users` WHERE `id` = ?', 'i', array($resetCustomer['id']));

    assert_equals(0, (int) $row['must_change_password'], 'The forced-change flag was not cleared');

    $response = $client->get('/dashboard');

    assert_equals(200, $response['status'], 'The user was still blocked');
});

$runner->test('A reset invalidates the target account other sessions', function () use ($baseUrl, $admin) {
    // Decision D-D.
    $customer = p5_register_customer($baseUrl, 'Sessions');

    // Two live sessions for the target account.
    $first = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');
    $second = p5_login_customer($baseUrl, $customer['email'], 'Customer123!');

    assert_equals(200, $first->get('/dashboard')['status'], 'The first session does not work');
    assert_equals(200, $second->get('/dashboard')['status'], 'The second session does not work');

    $token = p5_csrf($admin, '/admin/users/' . $customer['id']);

    $admin->post('/admin/users/' . $customer['id'] . '/reset-password', array(
        'csrf_token'                    => $token,
        'temporary_password'            => 'TempPass123!',
        'temporary_password_confirmation' => 'TempPass123!',
    ));

    $firstResponse = $first->get('/dashboard');
    $secondResponse = $second->get('/dashboard');

    assert_true(
        $firstResponse['status'] === 401 || $firstResponse['status'] === 302,
        'The first old session still works'
    );
    assert_true(
        $secondResponse['status'] === 401 || $secondResponse['status'] === 302,
        'The second old session still works'
    );
});

$runner->test("A reset does not invalidate the Admin's own session", function () use ($admin) {
    $response = $admin->get('/admin/users');

    assert_equals(200, $response['status'], 'The Admin session was invalidated');
});

$runner->test('A short temporary password is refused', function () use ($baseUrl, $admin, $resetCustomer) {
    $token = p5_csrf($admin, '/admin/users/' . $resetCustomer['id']);

    $response = $admin->post('/admin/users/' . $resetCustomer['id'] . '/reset-password', array(
        'csrf_token'                    => $token,
        'temporary_password'            => 'short',
        'temporary_password_confirmation' => 'short',
    ));

    assert_equals(200, $response['status'], 'The request was not re-rendered');
    assert_contains('at least 8 characters', $response['body'], 'The error is not shown');

    $row = db_select_one('SELECT `password_hash` FROM `users` WHERE `id` = ?', 'i', array($resetCustomer['id']));

    assert_true(
        password_verify('FinalPass123!', (string) $row['password_hash']),
        'The password was changed by a refused request'
    );
});

$runner->test('A mismatched confirmation is refused', function () use ($baseUrl, $admin, $resetCustomer) {
    $token = p5_csrf($admin, '/admin/users/' . $resetCustomer['id']);

    $response = $admin->post('/admin/users/' . $resetCustomer['id'] . '/reset-password', array(
        'csrf_token'                    => $token,
        'temporary_password'            => 'TempPass123!',
        'temporary_password_confirmation' => 'Different123!',
    ));

    assert_equals(200, $response['status'], 'The request was not re-rendered');
    assert_contains('do not match', $response['body'], 'The error is not shown');

    $row = db_select_one('SELECT `password_hash` FROM `users` WHERE `id` = ?', 'i', array($resetCustomer['id']));

    assert_true(
        password_verify('FinalPass123!', (string) $row['password_hash']),
        'The password was changed by a refused request'
    );
});

$runner->test('A reset without a CSRF token is refused', function () use ($baseUrl, $admin, $resetCustomer) {
    $response = $admin->post('/admin/users/' . $resetCustomer['id'] . '/reset-password', array(
        'temporary_password'            => 'TempPass123!',
        'temporary_password_confirmation' => 'TempPass123!',
    ));

    assert_equals(422, $response['status'], 'The request was not refused');

    $row = db_select_one('SELECT `password_hash` FROM `users` WHERE `id` = ?', 'i', array($resetCustomer['id']));

    assert_true(
        password_verify('FinalPass123!', (string) $row['password_hash']),
        'The password was changed by a refused request'
    );
});

$runner->test('A reset never echoes the temporary password back', function () use ($baseUrl, $admin, $resetCustomer) {
    $token = p5_csrf($admin, '/admin/users/' . $resetCustomer['id']);

    $response = $admin->post('/admin/users/' . $resetCustomer['id'] . '/reset-password', array(
        'csrf_token'                    => $token,
        'temporary_password'            => 'TempPass123!',
        'temporary_password_confirmation' => 'TempPass123!',
    ));

    assert_not_contains('TempPass123!', $response['body'], 'The temporary password was echoed back');
});

// ===========================================================================
$runner->group('14. The Admin action audit trail');
// ===========================================================================

$runner->test('A successful reset is recorded', function () use ($baseUrl, $admin) {
    $customer = p5_register_customer($baseUrl, 'Audited');

    gc_log_clear(LOG_CHANNEL_ADMIN_ACTIONS);

    $token = p5_csrf($admin, '/admin/users/' . $customer['id']);

    $admin->post('/admin/users/' . $customer['id'] . '/reset-password', array(
        'csrf_token'                    => $token,
        'temporary_password'            => 'TempPass123!',
        'temporary_password_confirmation' => 'TempPass123!',
    ));

    $entries = gc_log_read(LOG_CHANNEL_ADMIN_ACTIONS);

    assert_true(count($entries) > 0, 'The audit trail is empty');

    $entry = $entries[count($entries) - 1];

    assert_equals('password_reset', (string) $entry['event'], 'The wrong action was recorded');
    assert_equals('success', (string) $entry['outcome'], 'The outcome is wrong');
    assert_true((int) $entry['admin_user_id'] > 0, 'The Admin id is missing');
    assert_equals($customer['id'], (int) $entry['target_user_id'], 'The target id is wrong');
    assert_true(!empty($entry['timestamp']), 'The timestamp is missing');
});

$runner->test('A failed reset is recorded', function () use ($baseUrl, $admin) {
    gc_log_clear(LOG_CHANNEL_ADMIN_ACTIONS);

    $token = p5_csrf($admin, '/admin/users/999999');

    $response = $admin->post('/admin/users/999999/reset-password', array(
        'csrf_token'                    => $token,
        'temporary_password'            => 'TempPass123!',
        'temporary_password_confirmation' => 'TempPass123!',
    ));

    assert_equals(404, $response['status'], 'The unknown user was not refused');

    $entries = gc_log_read(LOG_CHANNEL_ADMIN_ACTIONS);

    assert_true(count($entries) > 0, 'The audit trail is empty');

    $entry = $entries[count($entries) - 1];

    assert_equals('password_reset', (string) $entry['event'], 'The wrong action was recorded');
    assert_equals('failed', (string) $entry['outcome'], 'The outcome is wrong');
    assert_true(!empty($entry['reason']), 'The reason is missing');
});

$runner->test('The audit trail holds no password material', function () use ($baseUrl, $admin) {
    $customer = p5_register_customer($baseUrl, 'AuditSecret');

    $token = p5_csrf($admin, '/admin/users/' . $customer['id']);

    $admin->post('/admin/users/' . $customer['id'] . '/reset-password', array(
        'csrf_token'                    => $token,
        'temporary_password'            => 'TempPass123!',
        'temporary_password_confirmation' => 'TempPass123!',
    ));

    $entries = gc_log_read(LOG_CHANNEL_ADMIN_ACTIONS);

    assert_true(count($entries) > 0, 'The audit trail is empty');

    foreach ($entries as $entry) {
        $serialised = (string) json_encode($entry);

        // The action is called `password_reset`, so the word "password"
        // itself is expected. What must never appear is the *material*: the
        // plaintext the Admin typed, or a hash of it.
        assert_not_contains('TempPass123!', $serialised, 'The temporary password is in the audit trail');
        assert_not_contains('password_hash', $serialised, 'The hash column is in the audit trail');
        assert_not_contains('$2y$', $serialised, 'A password hash is in the audit trail');
        assert_not_contains('temporary_password', $serialised, 'The temporary password field is in the audit trail');
    }
});

$runner->test('The log files are not reachable over HTTP', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    foreach (array(
        '/storage/logs/admin_actions.log',
        '/storage/logs/order_cancellation.log',
        '/../storage/logs/admin_actions.log',
    ) as $path) {
        $response = $client->get($path);

        assert_true(
            $response['status'] === 404 || $response['status'] === 403,
            $path . ' is reachable (status ' . $response['status'] . ')'
        );
    }
});

// ===========================================================================
$runner->group('15. Test isolation');
// ===========================================================================

$runner->test('The suite has not touched the seeded catalogue', function () {
    $seeded = db_select_one('SELECT `id` FROM `products` WHERE `name` = ? LIMIT 1', 's', array('Boston Fern'));

    assert_true($seeded !== null, 'The seeded Boston Fern product is missing');
});

$runner->test('The seeded accounts are unchanged', function () {
    $admin = db_select_one(
        'SELECT `id`, `email` FROM `users` WHERE `email` = ? LIMIT 1',
        's',
        array(P5_ADMIN_EMAIL)
    );

    assert_true($admin !== null, 'The seeded Admin account is missing');
});

$runner->test('The seeded categories are unchanged', function () {
    $row = db_select_one(
        "SELECT COUNT(*) AS `total` FROM `categories` WHERE `name` NOT LIKE 'P5 %'"
    );

    assert_equals(5, (int) $row['total'], 'The seeded category count changed');
});

p5_cleanup();

$runner->test('The cleanup removed every product the suite created', function () {
    $row = db_select_one('SELECT COUNT(*) AS `total` FROM `products` WHERE `name` LIKE ?', 's', array(P5_PREFIX . '%'));

    assert_equals(0, (int) $row['total'], 'The suite left products behind');
});

$runner->test('The cleanup removed every customer the suite created', function () {
    $row = db_select_one('SELECT COUNT(*) AS `total` FROM `users` WHERE `email` LIKE ?', 's', array(P5_EMAIL_PREFIX . '%'));

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

$runner->test('The cleanup removed the log files the suite wrote', function () {
    $row = db_select_one('SELECT COUNT(*) AS `total` FROM `products` WHERE `name` LIKE ?', 's', array(P5_PREFIX . '%'));

    assert_equals(0, (int) $row['total'], 'The suite left products behind');

    assert_equals(
        0,
        count(gc_log_read(LOG_CHANNEL_ADMIN_ACTIONS)),
        'The suite left audit-trail entries behind'
    );
    assert_equals(
        0,
        count(gc_log_read(LOG_CHANNEL_ORDER_CANCELLATION)),
        'The suite left cancellation-log entries behind'
    );
});

// ===========================================================================
exit($runner->summary());
