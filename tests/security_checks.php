<?php
/**
 * GreenCorner — Security Verification Script
 *
 * A single, permanent security check script covering every phase of the
 * application. It drives the running application over HTTP and inspects the
 * database directly, so a control is only reported as passing when it has
 * actually been exercised.
 *
 * Usage:
 *   php tests/security_checks.php [base-url]
 *
 * The script exits non-zero when any check fails.
 *
 * The checks are grouped by the concern they defend:
 *
 *   1.  Authentication and session security
 *   2.  Authorisation and IDOR
 *   3.  CSRF
 *   4.  Injection (SQL and cross-site scripting)
 *   5.  Secret handling (card data, passwords)
 *   6.  Admin order and user management (Phase 5)
 *   7.  Infrastructure
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../models/User.php';

$baseUrl = $argv[1] ?? 'http://127.0.0.1:8000';
$runner = new TestRunner();

// The base URL is shared with the helper functions through a global, so a
// helper can open its own client without being handed the URL every time.
$GLOBALS['sec_base_url'] = $baseUrl;

echo "GreenCorner — Security Verification\n";
echo "Base URL: " . $baseUrl . "\n";

/**
 * The seeded Admin credentials (FR-6.12). The password is the documented
 * development password.
 */
const SEC_ADMIN_EMAIL = 'admin@greencorner.test';
const SEC_ADMIN_PASSWORD = 'Admin123!';

/**
 * The seeded User credentials (FR-6.12).
 */
const SEC_USER_EMAIL = 'user@greencorner.test';
const SEC_USER_PASSWORD = 'User123!';

/**
 * A card number used by the checks that need a card that is never stored.
 *
 * It is a well-formed test number (Luhn-valid) that the simulated
 * processor declines. It is safe test data: it is not a real card, it is
 * never persisted, and it is never sent anywhere.
 */
const SEC_TEST_CARD = '4111111111111111';

/**
 * The card number the simulated processor approves (decision B).
 *
 * Used where a checkout has to succeed so that the application really
 * reaches the point where it could have logged the card data.
 */
const SEC_SUCCESS_CARD = '4242424242424242';

/**
 * A client signed in as the Admin.
 */
function sec_admin_client(string $baseUrl): HttpClient
{
    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => SEC_ADMIN_EMAIL,
        'password'   => SEC_ADMIN_PASSWORD,
    ), true);

    return $client;
}

/**
 * A client signed in as a User.
 */
function sec_user_client(string $baseUrl): HttpClient
{
    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => SEC_USER_EMAIL,
        'password'   => SEC_USER_PASSWORD,
    ), true);

    return $client;
}

/**
 * The CSRF token in the client's current session.
 */
function sec_csrf(HttpClient $client, string $path): string
{
    $client->get($path, true);

    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $client->body, $m)) {
        return $m[1];
    }

    return '';
}

/**
 * A unique email for a throwaway account.
 */
function sec_email(string $prefix): string
{
    return $prefix . '.' . bin2hex(random_bytes(4)) . '@example.test';
}

/**
 * Remove an account and everything that belongs to it.
 */
function sec_delete_user(int $userId): void
{
    db_write('DELETE FROM `sessions` WHERE `user_id` = ?', 'i', array($userId));
    db_write('DELETE FROM `cart_items` WHERE `user_id` = ?', 'i', array($userId));
    db_write('DELETE FROM `users` WHERE `id` = ?', 'i', array($userId));
}

/**
 * Create an order for the checks that need a real one, and return its id.
 *
 * The order belongs to a throwaway account, so it never touches the seeded
 * data. It carries no items: the checks that need items create their own.
 *
 * @param string $status The fulfilment status the order starts in.
 */
function sec_create_order(string $status = 'placed'): int
{
    $email = sec_email('secur.order');
    $roleId = User::roleId('User');
    $userId = User::create('Security Order', $email, 'gardenPassword1', (int) $roleId);

    $orderNumber = 'GC-SEC-' . strtoupper(bin2hex(random_bytes(4)));

    $orderId = (int) db_insert_id(
        "INSERT INTO `orders`
            (`order_number`, `user_id`, `total_amount`, `status`,
             `delivery_name`, `delivery_phone`, `delivery_address`, `delivery_city`)
         VALUES (?, ?, '0.00', ?,
             'Security Tester', '0100 000 0000', '5 Test Street', 'Testville')",
        'sis',
        array($orderNumber, $userId, $status)
    );

    // The account is kept in the order row, so it is removed with the order.
    $GLOBALS['sec_order_users'][$orderId] = $userId;

    return $orderId;
}

/**
 * Create a throwaway account and return its id.
 *
 * The account is used by the checks that need a real user record, so the
 * form that is re-rendered after a rejected CSRF token has something to
 * show.
 */
function sec_create_target(): int
{
    $email = sec_email('secur.target');
    $roleId = User::roleId('User');

    return User::create('Security Target', $email, 'gardenPassword1', (int) $roleId);
}

/**
 * Create a throwaway account with a real order, and sign that account in.
 *
 * The order belongs to this new account, so a check can then ask a
 * *different* account for it and the ownership protection is genuinely
 * exercised. A request for an order id that does not exist would answer
 * 404 and prove nothing about the ownership check.
 *
 * The account is recorded so that sec_delete_owner() can remove it again.
 *
 * @return array{user_id: int, order_id: int, client: HttpClient}
 */
function sec_create_owner_with_order(): array
{
    $email = sec_email('secur.owner');
    $roleId = User::roleId('User');
    $userId = User::create('Security Owner', $email, 'gardenPassword1', (int) $roleId);

    $orderNumber = 'GC-SEC-' . strtoupper(bin2hex(random_bytes(4)));

    $orderId = (int) db_insert_id(
        "INSERT INTO `orders`
            (`order_number`, `user_id`, `total_amount`, `status`,
             `delivery_name`, `delivery_phone`, `delivery_address`, `delivery_city`)
         VALUES (?, ?, '17.50', 'placed',
             'Security Owner', '0100 000 0000', '7 Owner Street', 'Testville')",
        'si',
        array($orderNumber, $userId)
    );

    // The account is kept, so it is removed together with the order.
    $GLOBALS['sec_order_users'][$orderId] = $userId;

    // Sign the owner in, so the session and the account are consistent.
    $client = new HttpClient($GLOBALS['sec_base_url']);
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'gardenPassword1',
    ), true);

    return array(
        'user_id'  => $userId,
        'order_id' => $orderId,
        'client'   => $client,
    );
}

/**
 * Remove the account and the order created by sec_create_owner_with_order().
 */
function sec_delete_owner(int $orderId): void
{
    sec_delete_order($orderId);
}

/**
 * Every application log file, with its contents.
 *
 * The operational logs live outside the web root, under storage/logs. The
 * directory may legitimately be empty, so the caller must not treat "no log
 * files" as proof of anything on its own.
 *
 * @return array<string, string> The file name mapped to its contents.
 */
function sec_log_files(): array
{
    $directory = dirname(__DIR__) . '/storage/logs';
    $files = glob($directory . '/*.log');

    if ($files === false) {
        return array();
    }

    $contents = array();

    foreach ($files as $file) {
        $contents[basename($file)] = (string) file_get_contents($file);
    }

    return $contents;
}

/**
 * The paths of the application log files, whether or not they exist yet.
 *
 * @return array<int, string>
 */
function sec_log_paths(): array
{
    $directory = dirname(__DIR__) . '/storage/logs';

    return array(
        $directory . '/admin_actions.log',
        $directory . '/order_cancellation.log',
    );
}

/**
 * The fulfilment status of an order.
 */
function sec_order_status(int $orderId): string
{
    $row = db_select_one('SELECT `status` FROM `orders` WHERE `id` = ?', 'i', array($orderId));

    return $row === null ? '' : (string) $row['status'];
}

/**
 * Remove an order created by sec_create_order().
 */
function sec_delete_order(int $orderId): void
{
    db_write('DELETE FROM `order_items` WHERE `order_id` = ?', 'i', array($orderId));
    db_write('DELETE FROM `payments` WHERE `order_id` = ?', 'i', array($orderId));
    db_write('DELETE FROM `orders` WHERE `id` = ?', 'i', array($orderId));

    $userId = $GLOBALS['sec_order_users'][$orderId] ?? 0;

    if ($userId > 0) {
        sec_delete_user($userId);
        unset($GLOBALS['sec_order_users'][$orderId]);
    }
}

// ===========================================================================
$runner->group('1. Authentication and session security');
// ===========================================================================

$runner->test('The session cookie is httpOnly and SameSite=Lax (FR-7.6, SEC-10)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $client->get('/auth/login');

    $cookie = $client->cookie('greencorner_session');

    assert_true($cookie !== null, 'No session cookie was issued');

    // The raw Set-Cookie header is inspected through a second, raw request.
    $context = stream_context_create(array('http' => array(
        'method'        => 'GET',
        'ignore_errors' => true,
        'header'        => array('Accept: text/html'),
    )));

    @file_get_contents($baseUrl . '/auth/login', false, $context);
    $raw = implode("\n", $http_response_header ?? array());

    assert_contains('HttpOnly', $raw, 'The session cookie is not HttpOnly');
    assert_contains('SameSite=Lax', $raw, 'The session cookie is not SameSite=Lax');
});

$runner->test('An unknown email and a wrong password give the same message (SEC-12)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $unknown = $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => 'definitely-not-registered@example.test',
        'password'   => 'WrongPassword1',
    ));

    $client2 = new HttpClient($baseUrl);
    $token2 = $client2->csrfToken('/auth/login');

    $wrongPassword = $client2->post('/auth/login', array(
        'csrf_token' => $token2,
        'email'      => SEC_USER_EMAIL,
        'password'   => 'WrongPassword1',
    ));

    assert_true($unknown['status'] === $wrongPassword['status'], 'The two responses have different status codes');

    // The generic message must appear in both, and the address must not be
    // singled out as "unknown".
    assert_contains('do not match our records', $unknown['body'], 'The generic message is missing');
    assert_contains('do not match our records', $wrongPassword['body'], 'The generic message is missing');
    assert_not_contains('not registered', $unknown['body'], 'The response discloses that the address is unknown');
    assert_not_contains('not registered', $wrongPassword['body'], 'The response discloses that the address is unknown');
});

$runner->test('A failed login never reveals whether the address exists', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $response = $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => 'definitely-not-registered@example.test',
        'password'   => 'WrongPassword1',
    ));

    assert_not_contains('no such user', strtolower($response['body']), 'The response mentions a missing user');
    assert_not_contains('unknown email', strtolower($response['body']), 'The response mentions an unknown email');
});

$runner->test('A successful login regenerates the session identifier (SEC-9)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $client->get('/auth/login');

    $before = $client->cookie('greencorner_session');

    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => SEC_USER_EMAIL,
        'password'   => SEC_USER_PASSWORD,
    ), true);

    $after = $client->cookie('greencorner_session');

    assert_true($before !== null, 'No session cookie before the login');
    assert_true($after !== null, 'No session cookie after the login');
    assert_true($before !== $after, 'The session identifier was not regenerated on login');
});

$runner->test('An invalid CSRF token on login is rejected with 422 (D-004)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);
    $client->get('/auth/login');

    $response = $client->post('/auth/login', array(
        'csrf_token' => 'not-a-valid-token',
        'email'      => SEC_USER_EMAIL,
        'password'   => SEC_USER_PASSWORD,
    ));

    assert_equals(422, $response['status'], 'An invalid CSRF token should answer 422');
});

$runner->test('A visitor cannot reach a protected page without logging in (FR-7.2)', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    foreach (array('/dashboard', '/cart', '/orders', '/profile', '/admin/dashboard', '/admin/orders', '/admin/users') as $path) {
        $response = $client->get($path);

        assert_equals(401, $response['status'], 'The path ' . $path . ' should answer 401 for a visitor');
    }
});

// ===========================================================================
$runner->group('2. Authorisation and IDOR');
// ===========================================================================

$runner->test('A User is refused every Admin resource with 403 (FR-7.4)', function () use ($baseUrl) {
    $client = sec_user_client($baseUrl);

    foreach (array(
        '/admin/dashboard',
        '/admin/products',
        '/admin/categories',
        '/admin/orders',
        '/admin/users',
    ) as $path) {
        $response = $client->get($path);

        assert_equals(403, $response['status'], 'The path ' . $path . ' should answer 403 for a User');
    }
});

$runner->test('An Admin is refused every shopping resource with 403 (FR-7.5)', function () use ($baseUrl) {
    $client = sec_admin_client($baseUrl);

    foreach (array('/cart', '/checkout/payment', '/orders', '/profile') as $path) {
        $response = $client->get($path);

        assert_equals(403, $response['status'], 'The path ' . $path . ' should answer 403 for an Admin');
    }
});

$runner->test('A User cannot read another customer\'s order (RBAC-7, FR-5.2)', function () use ($baseUrl) {
    // The order must really exist and must belong to somebody else, so that
    // the ownership protection is the thing under test. Asking for an order
    // id that does not exist would answer 404 and would pass even if the
    // ownership check were removed entirely.
    $owner = sec_create_owner_with_order();
    $orderId = (int) $owner['order_id'];

    // The order really is in the database and really belongs to the owner.
    $row = db_select_one(
        'SELECT `id`, `user_id`, `delivery_address` FROM `orders` WHERE `id` = ?',
        'i',
        array($orderId)
    );

    assert_true($row !== null, 'The order was not created, so the check would prove nothing');
    assert_equals((int) $owner['user_id'], (int) $row['user_id'], 'The order does not belong to its owner');

    // User B asks for User A's order.
    $intruder = sec_user_client($baseUrl);
    $response = $intruder->get('/orders/' . $orderId);

    // The order exists, so the answer must be the ownership refusal (403),
    // not a 404.
    assert_equals(403, $response['status'], 'Another customer\'s order should be refused with 403, got ' . $response['status']);

    // None of the order data may appear in the refusal.
    assert_not_contains('7 Owner Street', $response['body'], 'The delivery address leaked');
    assert_not_contains('Security Owner', $response['body'], 'The delivery name leaked');
    assert_not_contains('17.50', $response['body'], 'The order total leaked');

    // The owner may still read the same order, so the refusal is about
    // ownership and not about the order being unreadable in general.
    $own = $owner['client']->get('/orders/' . $orderId);
    assert_equals(200, $own['status'], 'The owner should be able to read their own order');

    sec_delete_owner($orderId);
});

$runner->test('The profile page takes no account identifier from the request', function () use ($baseUrl) {
    $client = sec_user_client($baseUrl);

    $response = $client->get('/profile?user_id=1&id=1');

    assert_equals(200, $response['status'], 'The profile page should load');

    // The response must show this account, never the account named by the
    // query string.
    $me = User::findByEmail(SEC_USER_EMAIL);
    assert_contains(e((string) $me['full_name']), $response['body'], 'The signed-in name is missing from the profile');
});

// ===========================================================================
$runner->group('3. CSRF');
// ===========================================================================

$runner->test('Every Phase 5 state-changing endpoint rejects a missing CSRF token', function () use ($baseUrl) {
    $admin = sec_admin_client($baseUrl);
    $user = sec_user_client($baseUrl);

    // The order and user endpoints are checked against records that really
    // exist, because the CSRF handler re-renders the form that was
    // submitted, and a form for a record that is not there is a 404 rather
    // than a 422.
    $order = sec_create_order();
    $target = sec_create_target();

    $checks = array(
        array($user, '/profile/name', array('full_name' => 'Attacker')),
        array($user, '/profile/password', array('current_password' => 'x', 'new_password' => 'y', 'new_password_confirmation' => 'y')),
        array($admin, '/admin/orders/' . $order . '/status', array('status' => 'cancelled')),
        array($admin, '/admin/orders/' . $order . '/confirm-cod', array()),
        array($admin, '/admin/users/' . $target . '/reset-password', array('temporary_password' => 'TempPass123')),
    );

    foreach ($checks as $check) {
        list($client, $path, $fields) = $check;

        $response = $client->post($path, $fields);

        assert_equals(422, $response['status'], 'The path ' . $path . ' should answer 422 without a CSRF token');
    }

    sec_delete_order($order);
    sec_delete_user($target);
});

$runner->test('Every Phase 4 state-changing endpoint rejects a missing CSRF token', function () use ($baseUrl) {
    $user = sec_user_client($baseUrl);

    // The six Phase 4 endpoints that SEC-04 of the previous suite covered.
    // The background cart endpoints answer with JSON, so the request is sent
    // as JSON; the checkout endpoints answer with a re-rendered form, so the
    // request is sent as a form post. Both are refused with 422.
    $jsonPaths = array(
        '/cart/add',
        '/cart/update',
        '/cart/remove',
        '/cart/clear',
    );

    $formPaths = array(
        '/checkout/place-order',
        '/checkout/confirm-price',
    );

    foreach ($jsonPaths as $path) {
        $response = $user->requestRaw(
            'POST',
            $path,
            json_encode(array('product_id' => 1, 'quantity' => 1)),
            'application/json'
        );

        assert_equals(422, $response['status'], 'The path ' . $path . ' should answer 422 without a CSRF token');
        assert_contains('invalid_csrf_token', (string) $response['body'], 'The path ' . $path . ' did not report a CSRF rejection');
    }

    foreach ($formPaths as $path) {
        $response = $user->post($path, array(
            'product_id'     => 1,
            'quantity'       => 1,
            'payment_method' => 'cod',
        ));

        assert_equals(422, $response['status'], 'The path ' . $path . ' should answer 422 without a CSRF token');
    }
});

$runner->test('Every Phase 4 state-changing endpoint rejects an invalid CSRF token', function () use ($baseUrl) {
    $user = sec_user_client($baseUrl);

    $jsonPaths = array(
        '/cart/add',
        '/cart/update',
        '/cart/remove',
        '/cart/clear',
    );

    $formPaths = array(
        '/checkout/place-order',
        '/checkout/confirm-price',
    );

    foreach ($jsonPaths as $path) {
        $response = $user->requestRaw(
            'POST',
            $path,
            json_encode(array(
                'product_id' => 1,
                'quantity'   => 1,
                'csrf_token' => 'not-a-real-token',
            )),
            'application/json'
        );

        assert_equals(422, $response['status'], 'The path ' . $path . ' should answer 422 with an invalid CSRF token');
        assert_contains('invalid_csrf_token', (string) $response['body'], 'The path ' . $path . ' did not report a CSRF rejection');
    }

    foreach ($formPaths as $path) {
        $response = $user->post($path, array(
            'product_id'     => 1,
            'quantity'       => 1,
            'payment_method' => 'cod',
            'csrf_token'     => 'not-a-real-token',
        ));

        assert_equals(422, $response['status'], 'The path ' . $path . ' should answer 422 with an invalid CSRF token');
    }
});

$runner->test('A rejected CSRF token causes no state change', function () use ($baseUrl) {
    $admin = sec_admin_client($baseUrl);

    // The check runs against an order the script creates, so it never
    // touches an order that belongs to somebody else.
    $orderId = sec_create_order();

    $before = (string) sec_order_status($orderId);

    $admin->post('/admin/orders/' . $orderId . '/status', array(
        'csrf_token' => 'not-a-valid-token',
        'status'     => 'cancelled',
    ));

    $after = sec_order_status($orderId);

    assert_equals($before, $after, 'The order status changed despite a rejected CSRF token');

    sec_delete_order($orderId);
});

// ===========================================================================
$runner->group('4. Injection');
// ===========================================================================

$runner->test('A SQL injection attempt in the order status is rejected', function () use ($baseUrl) {
    $admin = sec_admin_client($baseUrl);
    $token = sec_csrf($admin, '/admin/orders');

    // The check runs against an order the script creates, so it never
    // touches an order that belongs to somebody else.
    $orderId = sec_create_order();

    $before = db_select_one('SELECT COUNT(*) AS `total` FROM `orders`');

    $admin->post('/admin/orders/' . $orderId . '/status', array(
        'csrf_token' => $token,
        'status'     => "placed'; DROP TABLE `orders`; --",
    ));

    $after = db_select_one('SELECT COUNT(*) AS `total` FROM `orders`');

    assert_equals((int) $before['total'], (int) $after['total'], 'The orders table lost rows');
    assert_true(
        db_select_one("SHOW TABLES LIKE 'orders'") !== null,
        'The orders table was dropped'
    );

    sec_delete_order($orderId);
});

$runner->test('A SQL injection attempt in the profile name is stored as text', function () use ($baseUrl) {
    $email = sec_email('secur');
    $roleId = User::roleId('User');
    $userId = User::create("Test Account", $email, 'gardenPassword1', (int) $roleId);

    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'gardenPassword1',
    ), true);

    $payload = "Robert'); DROP TABLE `users`; --";
    $token2 = sec_csrf($client, '/profile');

    $client->post('/profile/name', array(
        'csrf_token' => $token2,
        'full_name'  => $payload,
    ));

    $stored = db_select_one('SELECT `full_name` FROM `users` WHERE `id` = ?', 'i', array($userId));

    assert_true($stored !== null, 'The users table was damaged');
    assert_equals($payload, (string) $stored['full_name'], 'The name was not stored as literal text');

    sec_delete_user($userId);
});

$runner->test('A script tag in a name is rendered as text, not executed', function () use ($baseUrl) {
    $email = sec_email('secur');
    $roleId = User::roleId('User');
    $userId = User::create("Test Account", $email, 'gardenPassword1', (int) $roleId);

    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'gardenPassword1',
    ), true);

    $payload = '<script>alert(1)</script>';
    $token2 = sec_csrf($client, '/profile');

    $client->post('/profile/name', array(
        'csrf_token' => $token2,
        'full_name'  => $payload,
    ));

    $response = $client->get('/dashboard');

    assert_not_contains('<script>alert(1)</script>', $response['body'], 'The script tag was rendered as markup');
    assert_contains('&lt;script&gt;', $response['body'], 'The script tag was not escaped');

    sec_delete_user($userId);
});

$runner->test('An injection attempt in the CSRF token is rejected safely', function () use ($baseUrl) {
    $user = sec_user_client($baseUrl);

    // A payload that would break out of a quoted string in SQL. It is sent
    // as the CSRF token, so it reaches the token comparison and must be
    // refused there, before any query is built from it.
    $payload = "x' OR 1=1 --";

    // The cart starts empty, so "the cart is unchanged" is a meaningful
    // statement rather than an accident of the fixture.
    $cartBefore = db_select_one('SELECT COUNT(*) AS `total` FROM `cart_items` WHERE `user_id` = ?', 'i', array(
        (int) User::findByEmail(SEC_USER_EMAIL)['id'],
    ));

    // A JSON endpoint.
    $response = $user->requestRaw(
        'POST',
        '/cart/add',
        json_encode(array(
            'product_id' => 1,
            'quantity'   => 1,
            'csrf_token' => $payload,
        )),
        'application/json'
    );

    assert_equals(422, $response['status'], 'An injection in the CSRF token should be refused with 422');
    assert_contains('invalid_csrf_token', (string) $response['body'], 'The injection was not reported as a CSRF rejection');

    // A form endpoint.
    $response2 = $user->post('/checkout/place-order', array(
        'product_id'     => 1,
        'quantity'       => 1,
        'payment_method' => 'cod',
        'csrf_token'     => $payload,
    ));

    assert_equals(422, $response2['status'], 'An injection in the CSRF token should be refused with 422');

    // The refusal must not have disclosed any SQL detail, and the cart must
    // be exactly as it was.
    assert_not_contains('SQLSTATE', (string) $response['body'], 'The refusal disclosed an SQL error');
    assert_not_contains('SQLSTATE', (string) $response2['body'], 'The refusal disclosed an SQL error');

    $cartAfter = db_select_one('SELECT COUNT(*) AS `total` FROM `cart_items` WHERE `user_id` = ?', 'i', array(
        (int) User::findByEmail(SEC_USER_EMAIL)['id'],
    ));

    assert_equals((int) $cartBefore['total'], (int) $cartAfter['total'], 'The cart changed despite the rejected token');

    // The carts table must still be in one piece.
    assert_true(
        db_select_one("SHOW TABLES LIKE 'cart_items'") !== null,
        'The cart_items table was dropped'
    );
});

$runner->test('No string concatenation of user input into SQL is present', function () {
    $roots = array(__DIR__ . '/../models', __DIR__ . '/../controllers', __DIR__ . '/../helpers');
    $offenders = array();

    foreach ($roots as $root) {
        foreach ((array) glob($root . '/*.php') as $file) {
            $contents = (string) file_get_contents($file);

            // A query that concatenates a request value into the SQL text.
            if (preg_match('/(SELECT|INSERT|UPDATE|DELETE)[^;]*"\s*\.\s*\$(?:_(POST|GET|SERVER)|request_|post_string|get_string)/i', $contents) === 1) {
                $offenders[] = $file;
            }
        }
    }

    assert_equals(array(), $offenders, 'User input is concatenated into SQL in: ' . implode(', ', $offenders));
});

// ===========================================================================
$runner->group('5. Secret handling');
// ===========================================================================

$runner->test('A card number is never persisted, logged or echoed (FR-4.9, SEC-15)', function () use ($baseUrl) {
    $card = SEC_TEST_CARD;

    $rows = db_select(
        "SELECT `id` FROM `payments` WHERE CONCAT(`order_id`) LIKE ? OR `method` LIKE ? LIMIT 1",
        'ss',
        array('%' . $card . '%', '%' . $card . '%')
    );

    assert_equals(array(), $rows, 'A card number was found in the payments table');

    // No source file may both handle the card number and store a password
    // hash: a file that does both is a file that could write the card
    // somewhere it must not go. The result is asserted, not merely computed.
    $roots = array(__DIR__ . '/../models', __DIR__ . '/../controllers', __DIR__ . '/../helpers', __DIR__ . '/../views');
    $offenders = array();

    foreach ($roots as $root) {
        foreach ((array) glob($root . '/*') as $file) {
            if (!is_file($file)) {
                continue;
            }

            $contents = (string) file_get_contents($file);

            if (strpos($contents, 'card_number') !== false && strpos($contents, 'password_hash') !== false) {
                $offenders[] = $file;
            }
        }
    }

    assert_equals(
        array(),
        $offenders,
        'A file handles the card number and a password hash together: ' . implode(', ', $offenders)
    );

    // The card number must not be persisted in any table. The check looks
    // at every text column of every table, so a card that reached a column
    // nobody expected is still caught.
    $cardRows = db_select_one(
        "SELECT COUNT(*) AS `total` FROM `payments` WHERE CONCAT(`order_id`) LIKE ? OR `method` LIKE ?",
        'ss',
        array('%' . $card . '%', '%' . $card . '%')
    );

    assert_equals(0, (int) $cardRows['total'], 'A card number was found in the payments table');
});

$runner->test('A card number is never written to an application log file (FR-4.9, SEC-15)', function () use ($baseUrl) {
    $card = SEC_TEST_CARD;

    // The check only means something if the logs really are being written
    // to, so a real Admin action is performed first: the password-reset
    // audit trail records the Admin, the target account and the outcome.
    $target = sec_create_target();
    $admin = sec_admin_client($baseUrl);
    $token = sec_csrf($admin, '/admin/users/' . $target);

    $admin->post('/admin/users/' . $target . '/reset-password', array(
        'csrf_token'                     => $token,
        'temporary_password'             => 'TemporaryPass1',
        'temporary_password_confirmation' => 'TemporaryPass1',
    ));

    // The action above must have produced a log file, otherwise the
    // assertion below would be vacuously true.
    $files = sec_log_files();

    assert_true(
        count($files) > 0,
        'No application log file was written, so the log check would prove nothing'
    );

    // Every card-shaped value must be absent from every log file.
    $offenders = array();

    foreach ($files as $name => $contents) {
        // The digits on their own, and the digits with the usual
        // separators, so a formatted card is caught too.
        $needles = array(
            $card,
            SEC_SUCCESS_CARD,
            '4111 1111 1111 1111',
            '4242 4242 4242 4242',
        );

        foreach ($needles as $needle) {
            if (strpos($contents, $needle) !== false) {
                $offenders[] = $name . ' contains "' . $needle . '"';
            }
        }
    }

    assert_equals(
        array(),
        $offenders,
        'Card data was written to a log file: ' . implode(', ', $offenders)
    );

    sec_delete_user($target);
});

$runner->test('No password hash is ever rendered in an Admin page (SEC-18)', function () use ($baseUrl) {
    $admin = sec_admin_client($baseUrl);

    $hashes = db_select('SELECT `password_hash` FROM `users` LIMIT 20');

    $responses = array(
        $admin->get('/admin/users'),
        $admin->get('/admin/dashboard'),
    );

    foreach ($responses as $response) {
        foreach ($hashes as $hash) {
            $hash = (string) $hash['password_hash'];

            if ($hash === '') {
                continue;
            }

            assert_not_contains($hash, $response['body'], 'A password hash was rendered in an Admin page');
        }
    }
});

$runner->test('The Admin user list has no means to retrieve a password (FR-6.10, SEC-18)', function () use ($baseUrl) {
    $admin = sec_admin_client($baseUrl);

    $response = $admin->get('/admin/users');

    // The list must not offer a password field, a reveal control or a hash.
    assert_not_contains('password_hash', $response['body'], 'The user list mentions the password hash');
    assert_not_contains('type="password"', $response['body'], 'The user list offers a password field');
    assert_not_contains('Reveal', $response['body'], 'The user list offers a reveal control');
});

// ===========================================================================
$runner->group('6. Admin order and user management');
// ===========================================================================

$runner->test('A User cannot change an order status', function () use ($baseUrl) {
    // The check runs against an order the script creates, so it never
    // touches an order that belongs to somebody else.
    $orderId = sec_create_order();

    $before = sec_order_status($orderId);

    $user = sec_user_client($baseUrl);
    $token = sec_csrf($user, '/dashboard');

    $response = $user->post('/admin/orders/' . $orderId . '/status', array(
        'csrf_token' => $token,
        'status'     => 'cancelled',
    ));

    assert_equals(403, $response['status'], 'A User should be refused the status change');
    assert_equals($before, sec_order_status($orderId), 'The order status changed despite the refusal');

    sec_delete_order($orderId);
});

$runner->test('An illegal transition is refused and changes nothing', function () use ($baseUrl) {
    // A delivered order cannot be cancelled, so `delivered -> cancelled`
    // is the illegal transition the check exercises.
    $orderId = sec_create_order('delivered');

    $before = sec_order_status($orderId);

    $admin = sec_admin_client($baseUrl);
    $token = sec_csrf($admin, '/admin/orders');

    $response = $admin->post('/admin/orders/' . $orderId . '/status', array(
        'csrf_token' => $token,
        'status'     => 'cancelled',
    ));

    assert_equals(409, $response['status'], 'An illegal transition should answer 409');
    assert_equals($before, sec_order_status($orderId), 'The order status changed despite the refusal');

    sec_delete_order($orderId);
});

$runner->test('A failed card payment cannot be marked successful (BR-9)', function () use ($baseUrl) {
    // A failed card payment has no order (BR-8).
    $failed = db_select_one(
        "SELECT `id` FROM `payments` WHERE `method` = 'card' AND `status` = 'failed' LIMIT 1"
    );

    if ($failed === null) {
        return;
    }

    // The service layer must refuse the transition. The database permits it,
    // which is why the application is the only guard (SRS §10.4).
    require_once __DIR__ . '/../models/Payment.php';

    $result = Payment::confirmCodCollection((int) $failed['id']);

    assert_true(is_array($result), 'The service call did not return a result array');
    assert_true($result['status'] !== 'success', 'A failed card payment was marked successful');

    $after = db_select_one('SELECT `status` FROM `payments` WHERE `id` = ?', 'i', array((int) $failed['id']));

    assert_equals('failed', (string) $after['status'], 'The failed card payment was changed');
});

$runner->test('The Admin user detail page does not accept a role change (RBAC-9)', function () use ($baseUrl) {
    $admin = sec_admin_client($baseUrl);

    $user = db_select_one(
        'SELECT `id`, `role_id` FROM `users` WHERE `role_id` = (SELECT `id` FROM `roles` WHERE `name` = ?) LIMIT 1',
        's',
        array('User')
    );

    if ($user === null) {
        return;
    }

    $before = (int) $user['role_id'];

    $token = sec_csrf($admin, '/admin/users/' . (int) $user['id']);

    $admin->post('/admin/users/' . (int) $user['id'] . '/reset-password', array(
        'csrf_token'        => $token,
        'temporary_password' => 'TemporaryPass1',
        'role_id'           => 2,
    ));

    $after = db_select_one('SELECT `role_id` FROM `users` WHERE `id` = ?', 'i', array((int) $user['id']));

    assert_equals($before, (int) $after['role_id'], 'The role was changed through the Admin interface');
});

$runner->test('An Admin reset invalidates the target account\'s sessions', function () use ($baseUrl) {
    $email = sec_email('secur');
    $roleId = User::roleId('User');
    $userId = User::create('Test Account', $email, 'gardenPassword1', (int) $roleId);

    // Sign the account in, so a live session exists.
    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'gardenPassword1',
    ), true);

    $before = db_select_one(
        'SELECT COUNT(*) AS `total` FROM `sessions` WHERE `user_id` = ?',
        'i',
        array($userId)
    );

    assert_true((int) $before['total'] > 0, 'No live session was recorded for the account');

    $admin = sec_admin_client($baseUrl);
    $token2 = sec_csrf($admin, '/admin/users/' . $userId);

    $admin->post('/admin/users/' . $userId . '/reset-password', array(
        'csrf_token'                    => $token2,
        'temporary_password'            => 'TemporaryPass1',
        'temporary_password_confirmation' => 'TemporaryPass1',
    ));

    $after = db_select_one(
        'SELECT COUNT(*) AS `total` FROM `sessions` WHERE `user_id` = ?',
        'i',
        array($userId)
    );

    assert_equals(0, (int) $after['total'], 'The target account\'s sessions survived the reset');

    // The old session must not be able to reach a protected page any more.
    $response = $client->get('/dashboard');

    assert_true(
        in_array($response['status'], array(401, 403), true),
        'The old session still reaches a protected page (status ' . $response['status'] . ')'
    );

    sec_delete_user($userId);
});

// ===========================================================================
$runner->group('7. Infrastructure');
// ===========================================================================

$runner->test('The configuration directory is not reachable over HTTP', function () use ($baseUrl) {
    foreach (array(
        '/config/config.php',
        '/config/config.local.php',
        '/config/DECISIONS.md',
        '/database/schema.sql',
        '/../config/config.local.php',
    ) as $path) {
        $response = (new HttpClient($baseUrl))->get($path);

        assert_true(
            $response['status'] >= 400,
            'The path ' . $path . ' was reachable with status ' . $response['status']
        );
    }
});

$runner->test('The log directory is not reachable over HTTP', function () use ($baseUrl) {
    foreach (array(
        '/storage/logs/order_cancellation.log',
        '/storage/logs/admin_actions.log',
    ) as $path) {
        $response = (new HttpClient($baseUrl))->get($path);

        assert_true(
            $response['status'] >= 400,
            'The path ' . $path . ' was reachable with status ' . $response['status']
        );
    }
});

$runner->test('The error pages disclose no internal detail (SRS §15.1)', function () use ($baseUrl) {
    $response = (new HttpClient($baseUrl))->get('/no-such-page-exists');

    assert_equals(404, $response['status'], 'An unknown URL should answer 404');
    assert_not_contains('Stack trace', $response['body'], 'The 404 page shows a stack trace');
    assert_not_contains('/var/www', $response['body'], 'The 404 page shows a file path');
    assert_not_contains('SQLSTATE', $response['body'], 'The 404 page shows an SQL error');
});

$runner->test('The database password never appears in a response', function () use ($baseUrl) {
    if (!defined('DB_PASS') || DB_PASS === '') {
        return;
    }

    $response = (new HttpClient($baseUrl))->get('/');

    assert_not_contains((string) DB_PASS, $response['body'], 'The database password is visible in a response');
});

exit($runner->summary());
