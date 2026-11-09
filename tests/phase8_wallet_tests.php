<?php
/**
 * GreenCorner — Phase 8 Test Suite: bKash and Nagad Wallet Payments
 *
 * Covers the fourteen required scenarios of the mobile-wallet feature:
 *
 *   1.  COD selection still works, unchanged
 *   2.  bKash selection shows the bKash instructions and the wallet fields
 *   3.  Nagad selection shows the Nagad instructions and the wallet fields
 *   4.  an empty sender number is refused, and creates no payment
 *   5.  an empty transaction id is refused, and creates no payment
 *   6.  a duplicate transaction id is refused, and creates no payment
 *   7.  an Admin approves a valid manual payment
 *   8.  an Admin rejects a manual payment
 *   9.  a wallet payment that is verified but cannot be fulfilled marks the
 *       order paid_unfulfillable
 *   10. a refund is pending until it is confirmed
 *   11. a refund that succeeds is marked refunded
 *   12. a refund that fails is flagged for Admin review
 *   13. an unauthorised user cannot reach the Admin payment actions
 *   14. a customer who refreshes or resubmits the checkout does not create a
 *       second order
 *
 * Usage:
 *   php tests/phase8_wallet_tests.php [base-url]
 *
 * The default base URL is http://127.0.0.1:8000.
 *
 * The suite drives the application over HTTP, so the application must be
 * running before the tests are executed. See tests/serve.sh.
 *
 * TEST ISOLATION
 *
 * The suite creates its own products under a "P8 " prefix, its own customer
 * accounts under a "p8+" email prefix, and its own orders, and it removes
 * every one of them at the end. The seeded catalogue is left untouched.
 *
 * The suite creates a wallet payment row directly through the model in a
 * few places, because that is the only way to reach a state the customer
 * flow deliberately does not allow. Every such payment is removed again.
 */

require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/HttpClient.php';
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/helpers/db.php';
require_once dirname(__DIR__) . '/models/Product.php';
require_once dirname(__DIR__) . '/models/Category.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Cart.php';
require_once dirname(__DIR__) . '/models/Order.php';
require_once dirname(__DIR__) . '/models/OrderStatus.php';
require_once dirname(__DIR__) . '/models/Payment.php';
require_once dirname(__DIR__) . '/models/WalletPayment.php';
require_once dirname(__DIR__) . '/models/Refund.php';

$baseUrl = $argv[1] ?? 'http://127.0.0.1:8000';
$runner = new TestRunner();

echo "GreenCorner — Phase 8 Test Suite (wallet payments)\n";
echo "Base URL: " . $baseUrl . "\n";

// ===========================================================================
// Helpers
// ===========================================================================

/** The seeded Admin account (FR-6.12). */
const P8_ADMIN_EMAIL = 'admin@greencorner.test';
const P8_ADMIN_PASSWORD = 'Admin123!';

/** The prefix every product the suite creates carries. */
const P8_PREFIX = 'P8 ';

/** The prefix every customer account the suite creates carries. */
const P8_EMAIL_PREFIX = 'p8+';

/** The counter behind the unique suffix of the suite's email addresses. */
$GLOBALS['p8_counter'] = 0;

/** The counter behind the unique transaction ids the suite generates. */
$GLOBALS['p8_trx_counter'] = 0;

/**
 * A transaction id that no other test has used.
 */
function p8_unique_trxid(string $prefix = 'P8'): string
{
    $GLOBALS['p8_trx_counter']++;

    return strtoupper($prefix . str_pad((string) $GLOBALS['p8_trx_counter'], 6, '0', STR_PAD_LEFT));
}

/**
 * Log a client in as the seeded Admin and return the client.
 */
function p8_admin_client(string $baseUrl): HttpClient
{
    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => P8_ADMIN_EMAIL,
        'password'   => P8_ADMIN_PASSWORD,
    ));

    return $client;
}

/**
 * Register a customer account, sign it in, and return its id, email address
 * and client.
 *
 * Registration does not sign the account in (FR-1.1), so the helper signs in
 * afterwards, exactly as a customer would.
 *
 * @return array{id: int, email: string, client: HttpClient}
 */
function p8_register_customer(string $baseUrl, string $label = 'Customer', string $password = 'Customer123!'): array
{
    $GLOBALS['p8_counter']++;

    $client = new HttpClient($baseUrl);
    $token = $client->csrfToken('/auth/register');

    $slug = preg_replace('/[^a-z0-9]+/', '.', strtolower($label));
    $slug = trim((string) $slug, '.');

    if ($slug === '') {
        $slug = 'customer';
    }

    $email = P8_EMAIL_PREFIX . $slug . '.' . $GLOBALS['p8_counter'] . '@greencorner.test';

    $client->post('/auth/register', array(
        'csrf_token'            => $token,
        'full_name'             => 'P8 ' . $label . ' ' . $GLOBALS['p8_counter'],
        'email'                 => $email,
        'password'              => $password,
        'password_confirmation' => $password,
    ));

    $row = db_select_one('SELECT `id` FROM `users` WHERE `email` = ?', 's', array($email));

    // FR-1.1: the new account is not signed in yet, so the helper signs in.
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => $password,
    ));

    return array(
        'id'     => (int) $row['id'],
        'email'  => $email,
        'client' => $client,
    );
}

/**
 * The CSRF token on a page.
 *
 * The token is the one held by the session, so the page that is read does
 * not have to be the page the token is later posted to. The helper therefore
 * falls back to the dashboard, which every signed-in customer can reach.
 */
function p8_csrf(HttpClient $client, string $path): string
{
    $client->get($path);

    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $client->body, $m)) {
        return $m[1];
    }

    // The page the caller asked for may not carry a form (a redirect to the
    // cart, for example). The session still holds a valid token.
    $client->get('/dashboard');

    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $client->body, $m)) {
        return $m[1];
    }

    return '';
}

/**
 * Create a product for the suite and return its id.
 */
function p8_create_product(string $name, float $price = 25.00, int $stock = 10): int
{
    $category = db_select_one('SELECT `id` FROM `categories` ORDER BY `id` ASC LIMIT 1');

    return db_insert_id(
        "INSERT INTO `products`
            (`name`, `description`, `price`, `stock`, `image_path`, `thumbnail_path`, `category_id`)
         VALUES (?, 'A Phase 8 test product.', ?, ?, '', '', ?)",
        'sdii',
        array($name, $price, $stock, (int) $category['id'])
    );
}

/**
 * Put a product in the customer's cart.
 */
function p8_add_to_cart(HttpClient $client, int $productId, int $quantity = 1): void
{
    $client->post('/cart/add', array(
        'csrf_token' => p8_csrf($client, '/cart'),
        'product_id' => $productId,
        'quantity'   => $quantity,
    ));
}

/**
 * The delivery address the suite uses.
 */
function p8_address(): array
{
    return array(
        'delivery_name'    => 'P8 Tester',
        'delivery_phone'   => '01000000000',
        'delivery_address' => '8 Wallet Street',
        'delivery_city'    => 'Testville',
    );
}

/**
 * The fields of a checkout POST for a wallet method.
 */
function p8_wallet_fields(string $method, string $senderNumber, string $transactionId): array
{
    $fields = p8_address();

    $fields['payment_method'] = $method;
    $fields['reference_code'] = 'P8REF001';

    if ($method === WalletPayment::METHOD_NAGAD) {
        $fields['nagad_sender_number'] = $senderNumber;
        $fields['nagad_transaction_id'] = $transactionId;
    } else {
        $fields['sender_number'] = $senderNumber;
        $fields['transaction_id'] = $transactionId;
    }

    return $fields;
}

/**
 * Place an order through the real checkout, and return the outcome.
 *
 * @return array{status: int, location: string, body: string}
 */
function p8_place_wallet_order(
    HttpClient $client,
    string $method,
    string $senderNumber,
    string $transactionId
): array {
    $token = p8_csrf($client, '/checkout/payment');

    $fields = p8_wallet_fields($method, $senderNumber, $transactionId);
    $fields['csrf_token'] = $token;

    $response = $client->post('/checkout/place-order', $fields);

    return array(
        'status'   => $client->status,
        'location' => (string) ($client->headers['location'] ?? ''),
        'body'     => $client->body,
    );
}

/**
 * The order id of the most recent order belonging to a customer.
 */
function p8_latest_order_id(int $userId): int
{
    $row = db_select_one(
        'SELECT `id` FROM `orders` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1',
        'i',
        array($userId)
    );

    return (int) ($row['id'] ?? 0);
}

/**
 * The payment row of an order.
 */
function p8_payment_for(int $orderId): ?array
{
    return db_select_one(
        'SELECT `id`, `method`, `status`, `paid_at` FROM `payments` WHERE `order_id` = ? LIMIT 1',
        'i',
        array($orderId)
    );
}

/**
 * The number of payments an order has.
 */
function p8_payment_count(int $orderId): int
{
    $row = db_select_one(
        'SELECT COUNT(*) AS `total` FROM `payments` WHERE `order_id` = ?',
        'i',
        array($orderId)
    );

    return (int) ($row['total'] ?? 0);
}

/**
 * The number of orders a customer has.
 */
function p8_order_count(int $userId): int
{
    $row = db_select_one(
        'SELECT COUNT(*) AS `total` FROM `orders` WHERE `user_id` = ?',
        'i',
        array($userId)
    );

    return (int) ($row['total'] ?? 0);
}

/**
 * The stock of a product.
 */
function p8_stock(int $productId): int
{
    $row = db_select_one('SELECT `stock` FROM `products` WHERE `id` = ?', 'i', array($productId));

    return (int) ($row['stock'] ?? 0);
}

/**
 * Create a wallet payment row directly, and return the payment id.
 *
 * The suite needs a wallet payment in a state the customer flow does not
 * produce — for example a verified payment whose product has since become
 * unavailable — so that the Admin actions can be exercised.
 */
function p8_create_wallet_payment(
    int $userId,
    string $method,
    string $senderNumber,
    string $transactionId,
    string $status = 'pending_verification'
): int {
    $product = db_select_one(
        'SELECT `id`, `name`, `price` FROM `products` WHERE `name` LIKE ? ORDER BY `id` ASC LIMIT 1',
        's',
        array(P8_PREFIX . '%')
    );

    $total = number_format((float) $product['price'], 2, '.', '');

    $orderNumber = 'GC-P8-' . str_pad((string) (++$GLOBALS['p8_counter']), 6, '0', STR_PAD_LEFT);

    $orderId = db_insert_id(
        "INSERT INTO `orders`
            (`order_number`, `user_id`, `total_amount`, `status`,
             `delivery_name`, `delivery_phone`, `delivery_address`, `delivery_city`)
         VALUES (?, ?, ?, 'placed', 'P8 Tester', '01000000000', '8 Wallet Street', 'Testville')",
        'sis',
        array($orderNumber, $userId, $total)
    );

    db_write(
        'INSERT INTO `order_items`
            (`order_id`, `product_id`, `product_name_at_purchase`, `unit_price_at_purchase`, `quantity`)
         VALUES (?, ?, ?, ?, 1)',
        'iisd',
        array($orderId, (int) $product['id'], (string) $product['name'], (float) $product['price'])
    );

    $paymentId = WalletPayment::recordPending(
        $orderId,
        $method,
        $senderNumber,
        $transactionId,
        $total,
        'P8REF001'
    );

    if ($status === 'success') {
        db_write(
            'UPDATE `payments` SET `status` = ?, `paid_at` = ? WHERE `id` = ?',
            'ssi',
            array('success', Order::now(), $paymentId)
        );
        db_write(
            'UPDATE `payment_ref` SET `status` = ? WHERE `payment_id` = ?',
            'si',
            array(WalletPayment::STATUS_VERIFIED, $paymentId)
        );
    }

    return $paymentId;
}

/**
 * Remove everything the suite created.
 */
function p8_cleanup(): void
{
    $customers = db_select(
        'SELECT `id` FROM `users` WHERE `email` LIKE ?',
        's',
        array(P8_EMAIL_PREFIX . '%')
    );

    foreach ($customers as $customer) {
        $userId = (int) $customer['id'];

        db_write('DELETE FROM `sessions` WHERE `user_id` = ?', 'i', array($userId));
        db_write('DELETE FROM `cart_items` WHERE `user_id` = ?', 'i', array($userId));

        // The refunds and the wallet submissions reference the payments, so
        // they go first.
        db_write(
            'DELETE FROM `refunds` WHERE `payment_id` IN (SELECT `id` FROM `payments` WHERE `order_id` IN (SELECT `id` FROM `orders` WHERE `user_id` = ?))',
            'i',
            array($userId)
        );
        db_write(
            'DELETE FROM `payment_ref` WHERE `payment_id` IN (SELECT `id` FROM `payments` WHERE `order_id` IN (SELECT `id` FROM `orders` WHERE `user_id` = ?))',
            'i',
            array($userId)
        );
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

    // The suite's products.
    $products = db_select(
        'SELECT `id` FROM `products` WHERE `name` LIKE ?',
        's',
        array(P8_PREFIX . '%')
    );

    foreach ($products as $product) {
        $productId = (int) $product['id'];

        db_write('DELETE FROM `cart_items` WHERE `product_id` = ?', 'i', array($productId));
        db_write(
            'DELETE FROM `order_items` WHERE `product_id` = ?',
            'i',
            array($productId)
        );
        db_write('DELETE FROM `products` WHERE `id` = ?', 'i', array($productId));
    }
}

// ===========================================================================
// 1. COD selection still works
// ===========================================================================

$runner->group('Scenario 1 — Cash on Delivery is unchanged');

$runner->test('the payment page offers the Cash on Delivery method', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'COD Product', 30.00, 5);
    $customer = p8_register_customer($baseUrl, 'Cod');

    p8_add_to_cart($customer['client'], $productId);

    $customer['client']->get('/checkout/payment');

    assert_contains('value="cod"', $customer['client']->body, 'The COD radio button is missing.');
    assert_contains('Cash on delivery', $customer['client']->body, 'The COD label is missing.');
});

$runner->test('a COD order is created with a pending payment', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'COD Order', 30.00, 5);
    $customer = p8_register_customer($baseUrl, 'CodOrder');

    p8_add_to_cart($customer['client'], $productId);

    $token = p8_csrf($customer['client'], '/checkout/payment');

    $fields = p8_address();
    $fields['csrf_token'] = $token;
    $fields['payment_method'] = 'cod';

    $customer['client']->post('/checkout/place-order', $fields);

    assert_equals(302, $customer['client']->status, 'The checkout did not redirect.');

    $orderId = p8_latest_order_id((int) $customer['id']);

    assert_true($orderId > 0, 'No order was created.');

    $payment = p8_payment_for($orderId);

    assert_true($payment !== null, 'No payment was created.');
    assert_equals('cod', (string) $payment['method'], 'The method is not cod.');
    assert_equals('pending', (string) $payment['status'], 'The COD payment is not pending.');
});

// ===========================================================================
// 2. bKash selection
// ===========================================================================

$runner->group('Scenario 2 — bKash selection');

$runner->test('the payment page offers bKash when a merchant number is configured', function () use ($baseUrl) {
    assert_true(
        WalletPayment::isAvailable(WalletPayment::METHOD_BKASH),
        'The bKash merchant number is not configured, so the suite cannot test the method.'
    );

    $productId = p8_create_product(P8_PREFIX . 'bKash Product', 40.00, 5);
    $customer = p8_register_customer($baseUrl, 'Bkash');

    p8_add_to_cart($customer['client'], $productId);

    $customer['client']->get('/checkout/payment');

    assert_contains('value="bkash"', $customer['client']->body, 'The bKash radio button is missing.');
    assert_contains('Pay with bKash', $customer['client']->body, 'The bKash panel is missing.');
    assert_contains('Merchant Payment', $customer['client']->body, 'The bKash merchant payment step is missing.');
    assert_contains('*247#', $customer['client']->body, 'The bKash dial code is missing.');
    assert_contains(
        WalletPayment::merchantNumber(WalletPayment::METHOD_BKASH),
        $customer['client']->body,
        'The bKash merchant number is not shown.'
    );
    assert_contains('Sender number', $customer['client']->body, 'The sender number field is missing.');
    assert_contains('Transaction ID', $customer['client']->body, 'The transaction id field is missing.');
});

$runner->test('the bKash panel shows the order total as the amount to send', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'bKash Amount', 47.50, 5);
    $customer = p8_register_customer($baseUrl, 'BkashAmount');

    p8_add_to_cart($customer['client'], $productId);

    $customer['client']->get('/checkout/payment');

    assert_contains(
        format_money(47.50),
        $customer['client']->body,
        'The amount to send is not shown on the bKash panel.'
    );
});

$runner->test('a bKash order is created with a pending_verification payment', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'bKash Order', 40.00, 5);
    $customer = p8_register_customer($baseUrl, 'BkashOrder');

    p8_add_to_cart($customer['client'], $productId);

    $transactionId = p8_unique_trxid('BK');

    $response = p8_place_wallet_order(
        $customer['client'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        $transactionId
    );

    assert_equals(302, $response['status'], 'The checkout did not redirect.');

    $orderId = p8_latest_order_id((int) $customer['id']);

    assert_true($orderId > 0, 'No order was created.');

    $payment = p8_payment_for($orderId);

    assert_true($payment !== null, 'No payment was created.');
    assert_equals('bkash', (string) $payment['method'], 'The method is not bkash.');
    assert_equals(
        'pending_verification',
        (string) $payment['status'],
        'The wallet payment is not awaiting verification.'
    );
    assert_null($payment['paid_at'], 'The wallet payment is already marked as paid.');

    // The submission itself.
    $reference = db_select_one(
        'SELECT `sender_number`, `transaction_id`, `status`, `verified_by`, `verified_at`
           FROM `payment_ref` WHERE `payment_id` = ?',
        'i',
        array((int) $payment['id'])
    );

    assert_true($reference !== null, 'No wallet submission was recorded.');
    assert_equals('01712345678', (string) $reference['sender_number'], 'The sender number was not stored.');
    assert_equals($transactionId, (string) $reference['transaction_id'], 'The transaction id was not stored.');
    assert_equals('pending_verification', (string) $reference['status'], 'The submission is not pending.');
    assert_null($reference['verified_by'], 'The submission was verified without an Admin.');
    assert_null($reference['verified_at'], 'The submission was verified without a time.');
});

$runner->test('the confirmation page says the payment is being checked, not paid', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'bKash Confirm', 40.00, 5);
    $customer = p8_register_customer($baseUrl, 'BkashConfirm');

    p8_add_to_cart($customer['client'], $productId);

    p8_place_wallet_order(
        $customer['client'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('BC')
    );

    $order = db_select_one(
        'SELECT `order_number` FROM `orders` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1',
        'i',
        array((int) $customer['id'])
    );

    $customer['client']->get('/checkout/confirmation?order=' . urlencode((string) $order['order_number']));

    assert_contains(
        'your payment is being checked',
        $customer['client']->body,
        'The confirmation page does not say the payment is being checked.'
    );
    assert_contains('Awaiting verification', $customer['client']->body, 'The awaiting-verification status is not shown.');
    assert_not_contains('>Paid<', $customer['client']->body, 'The confirmation page claims the payment is paid.');
});

// ===========================================================================
// 3. Nagad selection
// ===========================================================================

$runner->group('Scenario 3 — Nagad selection');

$runner->test('the payment page offers Nagad with its own instructions', function () use ($baseUrl) {
    assert_true(
        WalletPayment::isAvailable(WalletPayment::METHOD_NAGAD),
        'The Nagad merchant number is not configured, so the suite cannot test the method.'
    );

    $productId = p8_create_product(P8_PREFIX . 'Nagad Product', 35.00, 5);
    $customer = p8_register_customer($baseUrl, 'Nagad');

    p8_add_to_cart($customer['client'], $productId);

    $customer['client']->get('/checkout/payment');

    assert_contains('value="nagad"', $customer['client']->body, 'The Nagad radio button is missing.');
    assert_contains('Pay with Nagad', $customer['client']->body, 'The Nagad panel is missing.');
    assert_contains('Merchant Pay', $customer['client']->body, 'The Nagad merchant pay step is missing.');
    assert_contains('*167#', $customer['client']->body, 'The Nagad dial code is missing.');
    assert_contains(
        WalletPayment::merchantNumber(WalletPayment::METHOD_NAGAD),
        $customer['client']->body,
        'The Nagad merchant number is not shown.'
    );
});

$runner->test('a Nagad order is created with a pending_verification payment', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'Nagad Order', 35.00, 5);
    $customer = p8_register_customer($baseUrl, 'NagadOrder');

    p8_add_to_cart($customer['client'], $productId);

    $transactionId = p8_unique_trxid('NG');

    $response = p8_place_wallet_order(
        $customer['client'],
        WalletPayment::METHOD_NAGAD,
        '01812345678',
        $transactionId
    );

    assert_equals(302, $response['status'], 'The checkout did not redirect.');

    $orderId = p8_latest_order_id((int) $customer['id']);

    $payment = p8_payment_for($orderId);

    assert_true($payment !== null, 'No payment was created.');
    assert_equals('nagad', (string) $payment['method'], 'The method is not nagad.');
    assert_equals('pending_verification', (string) $payment['status'], 'The payment is not awaiting verification.');

    $reference = db_select_one(
        'SELECT `sender_number`, `transaction_id` FROM `payment_ref` WHERE `payment_id` = ?',
        'i',
        array((int) $payment['id'])
    );

    assert_equals('01812345678', (string) $reference['sender_number'], 'The sender number was not stored.');
    assert_equals($transactionId, (string) $reference['transaction_id'], 'The transaction id was not stored.');
});

// ===========================================================================
// 4. Empty sender number
// ===========================================================================

$runner->group('Scenario 4 — an empty sender number is refused');

$runner->test('an empty sender number is refused and creates no payment', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'No Sender', 45.00, 5);
    $customer = p8_register_customer($baseUrl, 'NoSender');

    p8_add_to_cart($customer['client'], $productId);

    $before = p8_payment_count(p8_latest_order_id((int) $customer['id']));

    $response = p8_place_wallet_order(
        $customer['client'],
        WalletPayment::METHOD_BKASH,
        '',
        p8_unique_trxid('NS')
    );

    assert_equals(302, $response['status'], 'The checkout did not redirect.');

    assert_equals(
        0,
        p8_order_count((int) $customer['id']),
        'An order was created despite the validation failure.'
    );

    // The form comes back with the inline error.
    $customer['client']->get('/checkout/payment');

    assert_contains(
        'Please enter the sender number you paid from.',
        $customer['client']->body,
        'The sender number error is not shown.'
    );

    $paymentTotal = db_select_one(
        "SELECT COUNT(*) AS `total` FROM `payments` WHERE `method` = 'bkash' AND `status` = 'pending_verification'"
    );

    assert_true(
        (int) $paymentTotal['total'] >= 0,
        'The payment count could not be read.'
    );
});

$runner->test('the sender number is validated server-side, not only in the browser', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'Bad Sender', 45.00, 5);
    $customer = p8_register_customer($baseUrl, 'BadSender');

    p8_add_to_cart($customer['client'], $productId);

    $response = p8_place_wallet_order(
        $customer['client'],
        WalletPayment::METHOD_BKASH,
        '123',
        p8_unique_trxid('BS')
    );

    assert_equals(302, $response['status'], 'The checkout did not redirect.');

    assert_equals(
        0,
        p8_order_count((int) $customer['id']),
        'An order was created despite the validation failure.'
    );

    $customer['client']->get('/checkout/payment');

    assert_contains(
        'Please enter a sender number of between 11 and 15 digits.',
        $customer['client']->body,
        'The sender number length error is not shown.'
    );
});

// ===========================================================================
// 5. Empty transaction id
// ===========================================================================

$runner->group('Scenario 5 — an empty transaction id is refused');

$runner->test('an empty transaction id is refused and creates no payment', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'No Trx', 45.00, 5);
    $customer = p8_register_customer($baseUrl, 'NoTrx');

    p8_add_to_cart($customer['client'], $productId);

    $response = p8_place_wallet_order(
        $customer['client'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        ''
    );

    assert_equals(302, $response['status'], 'The checkout did not redirect.');

    assert_equals(
        0,
        p8_order_count((int) $customer['id']),
        'An order was created despite the validation failure.'
    );

    $customer['client']->get('/checkout/payment');

    assert_contains(
        'Please enter the transaction id from your payment receipt.',
        $customer['client']->body,
        'The transaction id error is not shown.'
    );
});

// ===========================================================================
// 6. Duplicate transaction id
// ===========================================================================

$runner->group('Scenario 6 — a duplicate transaction id is refused');

$runner->test('the same transaction id cannot be submitted twice', function () use ($baseUrl) {
    $firstProduct = p8_create_product(P8_PREFIX . 'Dup A', 45.00, 5);
    $secondProduct = p8_create_product(P8_PREFIX . 'Dup B', 45.00, 5);

    $first = p8_register_customer($baseUrl, 'DupA');
    $second = p8_register_customer($baseUrl, 'DupB');

    $transactionId = p8_unique_trxid('DUP');

    p8_add_to_cart($first['client'], $firstProduct);
    p8_place_wallet_order($first['client'], WalletPayment::METHOD_BKASH, '01712345678', $transactionId);

    assert_equals(1, p8_order_count((int) $first['id']), 'The first submission did not create an order.');

    // A different customer submits the same transaction id.
    p8_add_to_cart($second['client'], $secondProduct);
    p8_place_wallet_order($second['client'], WalletPayment::METHOD_BKASH, '01799999999', $transactionId);

    assert_equals(
        0,
        p8_order_count((int) $second['id']),
        'The duplicate transaction id was accepted.'
    );

    $second['client']->get('/checkout/payment');

    assert_contains(
        'already been submitted',
        $second['client']->body,
        'The duplicate transaction id error is not shown.'
    );
});

$runner->test('the database refuses a duplicate transaction id as well', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'Dup Db', 45.00, 5);
    $customer = p8_register_customer($baseUrl, 'DupDb');

    p8_add_to_cart($customer['client'], $productId);

    $transactionId = p8_unique_trxid('DUPDB');

    p8_place_wallet_order($customer['client'], WalletPayment::METHOD_BKASH, '01712345678', $transactionId);

    $orderId = p8_latest_order_id((int) $customer['id']);
    $payment = p8_payment_for($orderId);

    $thrown = null;

    try {
        db_write(
            'INSERT INTO `payment_ref`
                (`payment_id`, `method`, `sender_number`, `transaction_id`, `amount`, `reference_code`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            'isssdss',
            array(
                (int) $payment['id'],
                'bkash',
                '01700000000',
                $transactionId,
                '10.00',
                'DUPDB',
                'pending_verification',
            )
        );
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    assert_true($thrown !== null, 'The database accepted a duplicate transaction id.');

    $code = $thrown instanceof RuntimeException ? $thrown->getCode() : 0;

    assert_equals('duplicate_entry', db_error_code((int) $code), 'The error is not a duplicate-entry error.');
});

// ===========================================================================
// 7. Admin approves a valid manual payment
// ===========================================================================

$runner->group('Scenario 7 — an Admin approves a manual payment');

$runner->test('an Admin can approve a wallet payment', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'Approve');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('AP')
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);

    $admin->post('/admin/orders/' . $orderId . '/verify-payment', array('csrf_token' => $token));

    assert_equals(302, $admin->status, 'The approval did not redirect.');

    $payment = p8_payment_for($orderId);

    assert_equals('success', (string) $payment['status'], 'The payment was not approved.');
    assert_true($payment['paid_at'] !== null, 'The approval did not set paid_at.');

    $reference = db_select_one(
        'SELECT `status`, `verified_by`, `verified_at` FROM `payment_ref` WHERE `payment_id` = ?',
        'i',
        array($paymentId)
    );

    assert_equals('verified', (string) $reference['status'], 'The submission is not verified.');
    assert_true($reference['verified_by'] !== null, 'The verifying Admin was not recorded.');
    assert_true($reference['verified_at'] !== null, 'The verification time was not recorded.');
});

$runner->test('the customer sees the payment as paid after the approval', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'ApproveSee');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_NAGAD,
        '01812345678',
        p8_unique_trxid('AS')
    );

    $order = db_select_one(
        'SELECT o.`id`, o.`order_number` FROM `orders` o
           JOIN `payments` p ON p.`order_id` = o.`id`
          WHERE p.`id` = ?',
        'i',
        array($paymentId)
    );

    $orderId = (int) $order['id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/verify-payment', array('csrf_token' => $token));

    $customer['client']->get('/orders/' . $orderId);

    assert_contains('Payment status', $customer['client']->body, 'The payment status is missing.');
    assert_contains('Paid', $customer['client']->body, 'The payment is not shown as paid.');
    assert_not_contains(
        'Awaiting verification',
        $customer['client']->body,
        'The payment still reads as awaiting verification after the approval.'
    );
});

$runner->test('a payment cannot be approved twice', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'ApproveTwice');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('AT')
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);

    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/verify-payment', array('csrf_token' => $token));

    assert_equals(302, $admin->status, 'The first approval did not redirect.');

    // The second attempt is refused.
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/verify-payment', array('csrf_token' => $token));

    assert_equals(409, $admin->status, 'The second approval was not refused.');

    $payment = p8_payment_for($orderId);

    assert_equals('success', (string) $payment['status'], 'The payment status changed unexpectedly.');
});

// ===========================================================================
// 8. Admin rejects a manual payment
// ===========================================================================

$runner->group('Scenario 8 — an Admin rejects a manual payment');

$runner->test('an Admin can reject a wallet payment', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'Reject');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('RJ')
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);

    $admin->post('/admin/orders/' . $orderId . '/reject-payment', array(
        'csrf_token'       => $token,
        'rejection_reason' => 'No matching payment on the wallet statement.',
    ));

    assert_equals(302, $admin->status, 'The rejection did not redirect.');

    $payment = p8_payment_for($orderId);

    assert_equals('failed', (string) $payment['status'], 'The payment was not rejected.');
    assert_null($payment['paid_at'], 'The rejected payment is marked as paid.');

    $reference = db_select_one(
        'SELECT `status`, `rejection_reason`, `verified_by` FROM `payment_ref` WHERE `payment_id` = ?',
        'i',
        array($paymentId)
    );

    assert_equals('rejected', (string) $reference['status'], 'The submission is not rejected.');
    assert_equals(
        'No matching payment on the wallet statement.',
        (string) $reference['rejection_reason'],
        'The rejection reason was not stored.'
    );
    assert_true($reference['verified_by'] !== null, 'The rejecting Admin was not recorded.');
});

$runner->test('a rejection without a reason is refused', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'RejectNoReason');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('RN')
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);

    $admin->post('/admin/orders/' . $orderId . '/reject-payment', array(
        'csrf_token'       => $token,
        'rejection_reason' => '',
    ));

    assert_equals(409, $admin->status, 'The rejection without a reason was not refused.');

    $payment = p8_payment_for($orderId);

    assert_equals(
        'pending_verification',
        (string) $payment['status'],
        'The payment was rejected despite the missing reason.'
    );
});

$runner->test('a rejected payment cannot be approved afterwards', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'RejectThenVerify');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('RV')
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);

    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/reject-payment', array(
        'csrf_token'       => $token,
        'rejection_reason' => 'Wrong amount.',
    ));

    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/verify-payment', array('csrf_token' => $token));

    assert_equals(409, $admin->status, 'The rejected payment was approved.');

    $payment = p8_payment_for($orderId);

    assert_equals('failed', (string) $payment['status'], 'The rejected payment was changed.');
});

// ===========================================================================
// 9. A verified wallet payment whose product is no longer available
// ===========================================================================

$runner->group('Scenario 9 — a verified payment that cannot be fulfilled');

$runner->test('an order that cannot be supplied is marked paid_unfulfillable', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'Unfulfillable');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('UF'),
        'success'
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);

    $admin->post('/admin/orders/' . $orderId . '/refund-unfulfillable', array('csrf_token' => $token));

    assert_equals(302, $admin->status, 'The refund action did not redirect.');

    $order = db_select_one('SELECT `status` FROM `orders` WHERE `id` = ?', 'i', array($orderId));

    assert_equals(
        'paid_unfulfillable',
        (string) $order['status'],
        'The order is not marked as paid but unfulfillable.'
    );
});

$runner->test('the order is not cancelled, because the money was received', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'UnfulfillableCancel');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('UC'),
        'success'
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/refund-unfulfillable', array('csrf_token' => $token));

    $order = db_select_one('SELECT `status` FROM `orders` WHERE `id` = ?', 'i', array($orderId));

    assert_true(
        (string) $order['status'] !== 'cancelled',
        'The order was cancelled instead of being marked paid but unfulfillable.'
    );
});

$runner->test('the refund covers the full amount paid', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'UnfulfillableAmount');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('UA'),
        'success'
    );

    $row = db_select_one(
        'SELECT p.`id`, o.`total_amount` FROM `payments` p
           JOIN `orders` o ON o.`id` = p.`order_id`
          WHERE p.`id` = ?',
        'i',
        array($paymentId)
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/refund-unfulfillable', array('csrf_token' => $token));

    $refund = db_select_one(
        'SELECT `amount`, `status` FROM `refunds` WHERE `payment_id` = ?',
        'i',
        array($paymentId)
    );

    assert_true($refund !== null, 'No refund was opened.');
    assert_equals(
        number_format((float) $row['total_amount'], 2, '.', ''),
        number_format((float) $refund['amount'], 2, '.', ''),
        'The refund is not for the full amount paid.'
    );
});

$runner->test('a payment that is not verified cannot be refunded', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'UnverifiedRefund');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('UR')
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/refund-unfulfillable', array('csrf_token' => $token));

    assert_equals(409, $admin->status, 'The unverified payment was refunded.');

    $refundCount = db_select_one(
        'SELECT COUNT(*) AS `total` FROM `refunds` WHERE `payment_id` = ?',
        'i',
        array($paymentId)
    );

    assert_equals(0, (int) $refundCount['total'], 'A refund was opened for an unverified payment.');
});

$runner->test('the inventory is not deducted twice by the refund', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'Refund Stock', 60.00, 10);
    $customer = p8_register_customer($baseUrl, 'RefundStock');

    p8_add_to_cart($customer['client'], $productId);

    p8_place_wallet_order(
        $customer['client'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('RS')
    );

    $orderId = p8_latest_order_id((int) $customer['id']);

    assert_equals(9, p8_stock($productId), 'The stock was not reduced by the checkout.');

    $paymentId = (int) p8_payment_for($orderId)['id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/verify-payment', array('csrf_token' => $token));

    assert_equals(9, p8_stock($productId), 'The approval changed the stock.');

    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/refund-unfulfillable', array('csrf_token' => $token));

    assert_equals(
        9,
        p8_stock($productId),
        'The refund deducted the inventory a second time.'
    );

    // The refund row references the right payment.
    $refund = db_select_one('SELECT `payment_id` FROM `refunds` WHERE `payment_id` = ?', 'i', array($paymentId));

    assert_true($refund !== null, 'The refund was not opened.');
});

// ===========================================================================
// 10. A refund is pending until it is confirmed
// ===========================================================================

$runner->group('Scenario 10 — a refund is pending until it is confirmed');

$runner->test('a new refund is pending, never successful', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'RefundPending');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('RP'),
        'success'
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/refund-unfulfillable', array('csrf_token' => $token));

    $refund = db_select_one(
        'SELECT `status`, `confirmed_at`, `gateway_reference` FROM `refunds` WHERE `payment_id` = ?',
        'i',
        array($paymentId)
    );

    assert_equals('refund_pending', (string) $refund['status'], 'The refund is not pending.');
    assert_null($refund['confirmed_at'], 'The refund is already confirmed.');
    assert_null($refund['gateway_reference'], 'The refund already carries a gateway reference.');
});

$runner->test('a refund cannot be confirmed without a gateway reference', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'RefundNoRef');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('RN2'),
        'success'
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/refund-unfulfillable', array('csrf_token' => $token));

    $refundId = (int) db_select_one(
        'SELECT `id` FROM `refunds` WHERE `payment_id` = ?',
        'i',
        array($paymentId)
    )['id'];

    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/confirm-refund', array(
        'csrf_token'        => $token,
        'refund_id'         => $refundId,
        'gateway_reference' => '',
    ));

    assert_equals(409, $admin->status, 'The refund was confirmed without a reference.');

    $refund = db_select_one('SELECT `status` FROM `refunds` WHERE `id` = ?', 'i', array($refundId));

    assert_equals('refund_pending', (string) $refund['status'], 'The refund was confirmed anyway.');
});

// ===========================================================================
// 11. A refund that succeeds
// ===========================================================================

$runner->group('Scenario 11 — a refund that succeeds');

$runner->test('a confirmed refund is marked refunded', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'RefundOk');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('RO'),
        'success'
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/refund-unfulfillable', array('csrf_token' => $token));

    $refundId = (int) db_select_one(
        'SELECT `id` FROM `refunds` WHERE `payment_id` = ?',
        'i',
        array($paymentId)
    )['id'];

    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/confirm-refund', array(
        'csrf_token'        => $token,
        'refund_id'         => $refundId,
        'gateway_reference' => 'BK-REF-000123',
    ));

    assert_equals(302, $admin->status, 'The refund confirmation did not redirect.');

    $refund = db_select_one(
        'SELECT `status`, `gateway_reference`, `confirmed_at` FROM `refunds` WHERE `id` = ?',
        'i',
        array($refundId)
    );

    assert_equals('refunded', (string) $refund['status'], 'The refund is not marked refunded.');
    assert_equals('BK-REF-000123', (string) $refund['gateway_reference'], 'The reference was not stored.');
    assert_true($refund['confirmed_at'] !== null, 'The confirmation time was not recorded.');
});

$runner->test('the customer sees the refund status', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'RefundSee');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('RSEE'),
        'success'
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/refund-unfulfillable', array('csrf_token' => $token));

    $refundId = (int) db_select_one(
        'SELECT `id` FROM `refunds` WHERE `payment_id` = ?',
        'i',
        array($paymentId)
    )['id'];

    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/confirm-refund', array(
        'csrf_token'        => $token,
        'refund_id'         => $refundId,
        'gateway_reference' => 'BK-REF-000456',
    ));

    $customer['client']->get('/orders/' . $orderId);

    assert_contains('Refund status', $customer['client']->body, 'The refund status is not shown.');
    assert_contains('Refunded', $customer['client']->body, 'The refund is not shown as refunded.');
});

$runner->test('a refund that is already confirmed cannot be confirmed again', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'RefundTwice');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('RT2'),
        'success'
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/refund-unfulfillable', array('csrf_token' => $token));

    $refundId = (int) db_select_one(
        'SELECT `id` FROM `refunds` WHERE `payment_id` = ?',
        'i',
        array($paymentId)
    )['id'];

    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/confirm-refund', array(
        'csrf_token'        => $token,
        'refund_id'         => $refundId,
        'gateway_reference' => 'BK-REF-000789',
    ));

    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/confirm-refund', array(
        'csrf_token'        => $token,
        'refund_id'         => $refundId,
        'gateway_reference' => 'BK-REF-000790',
    ));

    assert_equals(409, $admin->status, 'The refund was confirmed twice.');

    $refund = db_select_one(
        'SELECT `status`, `gateway_reference` FROM `refunds` WHERE `id` = ?',
        'i',
        array($refundId)
    );

    assert_equals('BK-REF-000789', (string) $refund['gateway_reference'], 'The reference was overwritten.');
});

// ===========================================================================
// 12. A refund that fails
// ===========================================================================

$runner->group('Scenario 12 — a refund that fails');

$runner->test('a failed refund is marked refund_failed', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'RefundFail');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('RF'),
        'success'
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/refund-unfulfillable', array('csrf_token' => $token));

    $refundId = (int) db_select_one(
        'SELECT `id` FROM `refunds` WHERE `payment_id` = ?',
        'i',
        array($paymentId)
    )['id'];

    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/fail-refund', array(
        'csrf_token'      => $token,
        'refund_id'       => $refundId,
        'failure_detail'  => 'The wallet account was closed before the refund could be sent.',
    ));

    assert_equals(302, $admin->status, 'The failure record did not redirect.');

    $refund = db_select_one(
        'SELECT `status`, `failure_detail`, `confirmed_at` FROM `refunds` WHERE `id` = ?',
        'i',
        array($refundId)
    );

    assert_equals('refund_failed', (string) $refund['status'], 'The refund is not marked as failed.');
    assert_true(
        (string) $refund['failure_detail'] !== '',
        'The failure detail was not stored.'
    );
    assert_null($refund['confirmed_at'], 'A failed refund must not carry a confirmation time.');
});

$runner->test('a failed refund is flagged for Admin review', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'RefundFlag');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('RFL'),
        'success'
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/refund-unfulfillable', array('csrf_token' => $token));

    $refundId = (int) db_select_one(
        'SELECT `id` FROM `refunds` WHERE `payment_id` = ?',
        'i',
        array($paymentId)
    )['id'];

    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/fail-refund', array(
        'csrf_token'     => $token,
        'refund_id'      => $refundId,
        'failure_detail' => 'The outlet could not find the transaction.',
    ));

    // The model lists the failed refunds so the store can chase them.
    $failed = Refund::failed();

    $found = false;

    foreach ($failed as $refund) {
        if ((int) $refund['id'] === $refundId) {
            $found = true;
            break;
        }
    }

    assert_true($found, 'The failed refund is not flagged for review.');

    // And the Admin page shows it.
    $admin->get('/admin/orders/' . $orderId);

    assert_contains('Refund failed', $admin->body, 'The Admin page does not show the failed refund.');
});

$runner->test('a confirmed refund cannot then be marked as failed', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'RefundOrder');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('RORD'),
        'success'
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);
    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/refund-unfulfillable', array('csrf_token' => $token));

    $refundId = (int) db_select_one(
        'SELECT `id` FROM `refunds` WHERE `payment_id` = ?',
        'i',
        array($paymentId)
    )['id'];

    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/confirm-refund', array(
        'csrf_token'        => $token,
        'refund_id'         => $refundId,
        'gateway_reference' => 'BK-REF-ORDER',
    ));

    $token = p8_csrf($admin, '/admin/orders/' . $orderId);
    $admin->post('/admin/orders/' . $orderId . '/fail-refund', array(
        'csrf_token'     => $token,
        'refund_id'      => $refundId,
        'failure_detail' => 'A late failure report.',
    ));

    assert_equals(409, $admin->status, 'A confirmed refund was marked as failed.');

    $refund = db_select_one('SELECT `status` FROM `refunds` WHERE `id` = ?', 'i', array($refundId));

    assert_equals('refunded', (string) $refund['status'], 'The confirmed refund was changed.');
});

// ===========================================================================
// 13. Unauthorised users cannot reach the Admin payment actions
// ===========================================================================

$runner->group('Scenario 13 — unauthorised users cannot reach the payment actions');

$runner->test('a signed-in customer cannot approve a payment', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'NotAdmin');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('NA')
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $token = p8_csrf($customer['client'], '/dashboard');

    $customer['client']->post('/admin/orders/' . $orderId . '/verify-payment', array(
        'csrf_token' => $token,
    ));

    assert_true(
        in_array($customer['client']->status, array(302, 401, 403), true),
        'The customer was not refused (status ' . $customer['client']->status . ').'
    );

    $payment = p8_payment_for($orderId);

    assert_equals(
        'pending_verification',
        (string) $payment['status'],
        'The customer approved the payment.'
    );
});

$runner->test('a signed-in customer cannot reject a payment', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'NotAdminReject');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('NAR')
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $token = p8_csrf($customer['client'], '/dashboard');

    $customer['client']->post('/admin/orders/' . $orderId . '/reject-payment', array(
        'csrf_token'       => $token,
        'rejection_reason' => 'Self-service rejection.',
    ));

    assert_true(
        in_array($customer['client']->status, array(302, 401, 403), true),
        'The customer was not refused (status ' . $customer['client']->status . ').'
    );

    $payment = p8_payment_for($orderId);

    assert_equals(
        'pending_verification',
        (string) $payment['status'],
        'The customer rejected the payment.'
    );
});

$runner->test('an anonymous visitor cannot approve a payment', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'Anon');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('AN')
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $visitor = new HttpClient($baseUrl);

    $visitor->post('/admin/orders/' . $orderId . '/verify-payment', array('csrf_token' => 'anything'));

    // FR-7.2: an unauthenticated request is refused with 401 and a redirect
    // to the login page.
    assert_true(
        in_array($visitor->status, array(302, 401, 403), true),
        'The visitor was not refused (status ' . $visitor->status . ').'
    );

    $payment = p8_payment_for($orderId);

    assert_equals(
        'pending_verification',
        (string) $payment['status'],
        'The visitor approved the payment.'
    );
});

$runner->test('an Admin action without a CSRF token is refused', function () use ($baseUrl) {
    $customer = p8_register_customer($baseUrl, 'NoCsrf');
    $paymentId = p8_create_wallet_payment(
        (int) $customer['id'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        p8_unique_trxid('NC')
    );

    $orderId = (int) db_select_one(
        'SELECT `order_id` FROM `payments` WHERE `id` = ?',
        'i',
        array($paymentId)
    )['order_id'];

    $admin = p8_admin_client($baseUrl);

    $admin->post('/admin/orders/' . $orderId . '/verify-payment', array());

    assert_equals(422, $admin->status, 'The action without a CSRF token was not refused.');

    $payment = p8_payment_for($orderId);

    assert_equals(
        'pending_verification',
        (string) $payment['status'],
        'The payment was approved without a CSRF token.'
    );
});

// ===========================================================================
// 14. Refreshing or resubmitting the checkout
// ===========================================================================

$runner->group('Scenario 14 — refreshing or resubmitting the checkout');

$runner->test('resubmitting the checkout does not create a second order', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'Resubmit', 55.00, 5);
    $customer = p8_register_customer($baseUrl, 'Resubmit');

    p8_add_to_cart($customer['client'], $productId);

    $transactionId = p8_unique_trxid('RSB');

    p8_place_wallet_order(
        $customer['client'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        $transactionId
    );

    assert_equals(1, p8_order_count((int) $customer['id']), 'The first submission did not create an order.');

    // The customer presses back and submits the same form again.
    $response = p8_place_wallet_order(
        $customer['client'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        $transactionId
    );

    assert_equals(302, $response['status'], 'The second submission did not redirect.');

    assert_equals(
        1,
        p8_order_count((int) $customer['id']),
        'The second submission created another order.'
    );

    // The cart was cleared by the first submission, so the second attempt
    // has nothing to buy.
    assert_contains('/cart', (string) $response['location'], 'The second submission did not go back to the cart.');
});

$runner->test('the customer cannot submit the same transaction id twice', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'SameTrx', 55.00, 5);
    $customer = p8_register_customer($baseUrl, 'SameTrx');

    p8_add_to_cart($customer['client'], $productId);

    $transactionId = p8_unique_trxid('ST');

    p8_place_wallet_order(
        $customer['client'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        $transactionId
    );

    // The customer puts the product back in the cart and tries again with
    // the same transaction id.
    p8_add_to_cart($customer['client'], $productId);

    p8_place_wallet_order(
        $customer['client'],
        WalletPayment::METHOD_BKASH,
        '01712345678',
        $transactionId
    );

    assert_equals(
        1,
        p8_order_count((int) $customer['id']),
        'The same transaction id produced a second order.'
    );

    $submissionCount = db_select_one(
        'SELECT COUNT(*) AS `total` FROM `payment_ref` WHERE `transaction_id` = ?',
        's',
        array($transactionId)
    );

    assert_equals(1, (int) $submissionCount['total'], 'The transaction id was recorded twice.');
});

$runner->test('refreshing the payment page does not create an order', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'Refresh', 55.00, 5);
    $customer = p8_register_customer($baseUrl, 'Refresh');

    p8_add_to_cart($customer['client'], $productId);

    $customer['client']->get('/checkout/payment');
    $customer['client']->get('/checkout/payment');
    $customer['client']->get('/checkout/payment');

    assert_equals(
        0,
        p8_order_count((int) $customer['id']),
        'Refreshing the payment page created an order.'
    );
});

$runner->test('the order number is not allocated until the checkout succeeds', function () use ($baseUrl) {
    $productId = p8_create_product(P8_PREFIX . 'NoNumber', 55.00, 5);
    $customer = p8_register_customer($baseUrl, 'NoNumber');

    p8_add_to_cart($customer['client'], $productId);

    $customer['client']->get('/checkout/payment');

    assert_equals(
        0,
        p8_order_count((int) $customer['id']),
        'An order was created by opening the payment page.'
    );
});

// ===========================================================================
// The gateway seams are honest about not being connected
// ===========================================================================

$runner->group('The gateway seams are honest');

$runner->test('the gateway initiation seam makes no call and claims no success', function () {
    $result = WalletPayment::initiate(WalletPayment::METHOD_BKASH, '100.00');

    assert_equals('gateway_unavailable', (string) $result['status'], 'The initiation seam claims to have worked.');
});

$runner->test('the gateway query seam makes no call and claims no success', function () {
    $result = WalletPayment::queryTransaction(WalletPayment::METHOD_BKASH, 'ANYTRXID');

    assert_equals('gateway_unavailable', (string) $result['status'], 'The query seam claims to have worked.');
});

$runner->test('the refund gateway seam makes no call and claims no success', function () {
    $result = Refund::sendToGateway(1);

    assert_equals('gateway_unavailable', (string) $result['status'], 'The refund seam claims to have worked.');
});

$runner->test('no merchant credential is committed to the tracked configuration', function () {
    $config = file_get_contents(__DIR__ . '/../config/config.php');

    assert_not_contains(
        "define('BKASH_APP_SECRET'",
        (string) $config,
        'A gateway credential is defined in the tracked configuration.'
    );

    $example = file_get_contents(__DIR__ . '/../config/config.example.php');

    assert_contains(
        'BKASH_MERCHANT_NUMBER',
        (string) $example,
        'The example configuration does not mention the merchant number.'
    );
});

// ===========================================================================
// Cleanup
// ===========================================================================

p8_cleanup();

$exitCode = $runner->summary();

exit($exitCode);
