<?php
/**
 * GreenCorner — Phase 1 Test Suite
 *
 * Covers the Phase 1 scope: foundation, database, registration, login,
 * logout, role-based authorisation, sessions, CSRF, account status and the
 * forced password change.
 *
 * Usage:
 *   php tests/run.php [base-url]
 *
 * The default base URL is http://127.0.0.1:8000.
 */

require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/HttpClient.php';
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/helpers/db.php';
require_once dirname(__DIR__) . '/models/User.php';

$baseUrl = $argv[1] ?? 'http://127.0.0.1:8000';
$runner = new TestRunner();

echo "GreenCorner — Phase 1 Test Suite\n";
echo "Base URL: " . $baseUrl . "\n";

/**
 * A unique email per run, so repeated runs never collide.
 */
function test_email(string $prefix): string
{
    return $prefix . '.' . bin2hex(random_bytes(4)) . '@example.test';
}

/**
 * Create an account directly through the model, bypassing the HTTP layer.
 * Used to set up preconditions for access-control tests.
 */
function make_user(string $email, string $password, string $roleName = 'User'): int
{
    $roleId = User::roleId($roleName);
    if ($roleId === null) {
        throw new RuntimeException('Role not found: ' . $roleName);
    }

    return User::create('Test Account', $email, $password, $roleId);
}

/**
 * Remove a test account and its dependent rows.
 */
function delete_user(int $userId): void
{
    db_write('DELETE FROM `sessions` WHERE `user_id` = ?', 'i', array($userId));
    db_write('DELETE FROM `cart_items` WHERE `user_id` = ?', 'i', array($userId));
    db_write('DELETE FROM `users` WHERE `id` = ?', 'i', array($userId));
}

// ===========================================================================
$runner->group('1. Database connectivity and configuration');
// ===========================================================================

$runner->test('Database connection succeeds', function () {
    $connection = db();
    assert_true($connection instanceof mysqli, 'db() did not return a mysqli handle');
    assert_true($connection->connect_errno === 0, 'The connection reported an error');
});

$runner->test('Server version is MySQL 8.0.16 or later', function () {
    $row = db_select_one('SELECT VERSION() AS `version`');
    $version = $row['version'] ?? '0';

    // Compare the numeric parts, ignoring any suffix such as "-commercial".
    preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m);
    assert_true(count($m) === 4, 'Could not parse the MySQL version: ' . $version);

    $major = (int) $m[1];
    $minor = (int) $m[2];
    $patch = (int) $m[3];

    $ok = $major > 8 || ($major === 8 && $minor > 0) || ($major === 8 && $minor === 0 && $patch >= 16);
    assert_true($ok, 'MySQL ' . $version . ' is below the mandatory 8.0.16 floor (SC-2)');
});

$runner->test('Session sql_mode includes STRICT_TRANS_TABLES (R-SQL-3)', function () {
    $row = db_select_one('SELECT @@SESSION.sql_mode AS `mode`');
    $mode = $row['mode'] ?? '';

    assert_true(
        stripos($mode, 'STRICT_TRANS_TABLES') !== false,
        'The session sql_mode does not include STRICT_TRANS_TABLES: ' . $mode
    );
});

$runner->test('Server sql_mode includes STRICT_TRANS_TABLES (R-SQL-1)', function () {
    $row = db_select_one('SELECT @@GLOBAL.sql_mode AS `mode`');
    $mode = $row['mode'] ?? '';

    assert_true(
        stripos($mode, 'STRICT_TRANS_TABLES') !== false,
        'The global sql_mode does not include STRICT_TRANS_TABLES: ' . $mode
    );
});

$runner->test('All nine tables exist', function () {
    $rows = db_select(
        "SELECT `TABLE_NAME` AS `name`
           FROM `information_schema`.`TABLES`
          WHERE `TABLE_SCHEMA` = ?",
        's',
        array(DB_NAME)
    );

    $found = array_column($rows, 'name');

    $expected = array(
        'roles', 'users', 'categories', 'products', 'cart_items',
        'orders', 'order_items', 'payments', 'sessions',
    );

    foreach ($expected as $table) {
        assert_in_array($table, $found, 'The table "' . $table . '" is missing');
    }
});

$runner->test('payments table has the generated success_order_id column', function () {
    $rows = db_select(
        "SELECT `EXTRA` AS `extra`
           FROM `information_schema`.`COLUMNS`
          WHERE `TABLE_SCHEMA` = ? AND `TABLE_NAME` = 'payments'
            AND `COLUMN_NAME` = 'success_order_id'",
        's',
        array(DB_NAME)
    );

    assert_true(count($rows) === 1, 'The success_order_id column is missing');
    assert_contains('VIRTUAL GENERATED', $rows[0]['extra'], 'success_order_id is not a generated column');
});

$runner->test('orders table has no payment_status column (FR-4.16)', function () {
    $rows = db_select(
        "SELECT `COLUMN_NAME` AS `name`
           FROM `information_schema`.`COLUMNS`
          WHERE `TABLE_SCHEMA` = ? AND `TABLE_NAME` = 'orders'
            AND `COLUMN_NAME` = 'payment_status'",
        's',
        array(DB_NAME)
    );

    assert_equals(0, count($rows), 'The orders table must not carry a payment_status column');
});

$runner->test('payments table stores no card data column (FR-4.9, SEC-16)', function () {
    $rows = db_select(
        "SELECT `COLUMN_NAME` AS `name`
           FROM `information_schema`.`COLUMNS`
          WHERE `TABLE_SCHEMA` = ? AND `TABLE_NAME` = 'payments'",
        's',
        array(DB_NAME)
    );

    $names = array_column($rows, 'name');

    foreach (['card_number', 'cardnumber', 'cvv', 'expiry', 'card_expiry', 'card_cvv'] as $forbidden) {
        assert_true(
            !in_array($forbidden, $names, true),
            'The payments table must not contain a column capable of storing card data: ' . $forbidden
        );
    }
});

$runner->test('Strict mode rejects an out-of-range insert instead of coercing it (R-SQL-5)', function () {
    // A quantity of 0 violates chk_cart_items_quantity. Under a relaxed mode
    // this would be silently accepted or clamped.
    $email = test_email('strict');
    $userId = make_user($email, 'StrictMode1!');

    // The product needs a category, so a temporary one is created. The name
    // is unique per run so a leftover from an earlier run cannot collide.
    $categoryName = 'Strict Test Category ' . bin2hex(random_bytes(4));
    $categoryId = db_insert_id(
        "INSERT INTO `categories` (`name`, `description`)
         VALUES (?, 'For the strict-mode test.')",
        's',
        array($categoryName)
    );

    $productId = db_insert_id(
        "INSERT INTO `products`
                (`name`, `description`, `price`, `stock`, `image_path`, `thumbnail_path`, `category_id`)
         VALUES ('Strict Test Product', 'For the strict-mode test.', '10.00', 5,
                 'test.jpg', 'test-thumb.jpg', ?)",
        'i',
        array($categoryId)
    );

    $raised = false;
    try {
        db_write(
            "INSERT INTO `cart_items` (`user_id`, `product_id`, `quantity`, `price_at_add`)
             VALUES (?, ?, 0, '10.00')",
            'ii',
            array($userId, $productId)
        );
    } catch (RuntimeException $e) {
        $raised = true;
    }

    db_write('DELETE FROM `cart_items` WHERE `user_id` = ?', 'i', array($userId));
    db_write('DELETE FROM `products` WHERE `id` = ?', 'i', array($productId));
    db_write('DELETE FROM `categories` WHERE `id` = ?', 'i', array($categoryId));
    delete_user($userId);

    assert_true($raised, 'An invalid quantity was accepted; strict mode is not rejecting bad data');
});

// ===========================================================================
$runner->group('1b. Configuration security (decision D-001)');
// ===========================================================================

$runner->test('The local configuration file is listed in .gitignore', function () {
    $gitignore = file_get_contents(__DIR__ . '/../.gitignore');

    assert_true($gitignore !== false, 'The .gitignore file could not be read');
    assert_contains('config/config.local.php', $gitignore, 'config/config.local.php is not ignored by Git');
});

$runner->test('The example configuration file contains placeholder values only', function () {
    $example = file_get_contents(__DIR__ . '/../config/config.example.php');

    assert_true($example !== false, 'config/config.example.php could not be read');

    // Every placeholder must be a CHANGE_ME marker, so no working
    // credential is ever committed.
    assert_contains('CHANGE_ME_DB_HOST', $example, 'The example file has no placeholder for the database host');
    assert_contains('CHANGE_ME_DB_NAME', $example, 'The example file has no placeholder for the database name');
    assert_contains('CHANGE_ME_DB_USER', $example, 'The example file has no placeholder for the database user');
    assert_contains('CHANGE_ME_DB_PASSWORD', $example, 'The example file has no placeholder for the database password');

    // The password actually in use must not appear in the example file.
    assert_not_contains(DB_PASS, $example, 'The example file contains the real database password');
});

$runner->test('No configuration file is reachable through the browser (SEC-6)', function () {
    $client = new HttpClient($GLOBALS['baseUrl']);

    $files = array(
        '/config/config.local.php',
        '/config/config.php',
        '/config/config.example.php',
        '/config/database.php',
        '/config/bootstrap.php',
    );

    foreach ($files as $path) {
        $response = $client->get($path);

        assert_true(
            $response['status'] === 404,
            $path . ' is reachable over HTTP (status ' . $response['status'] . ')'
        );
        assert_not_contains(
            'DB_PASS',
            $response['body'],
            $path . ' leaked the configuration to the browser'
        );
    }
});

$runner->test('A configuration file refuses to run when reached directly', function () {
    // The config/ directory is outside the web root, but each file also
    // refuses to run on its own, so a misconfigured document root cannot
    // expose it.
    $files = array(
        '/config/config.php',
        '/config/config.local.php',
        '/config/config.example.php',
        '/config/database.php',
    );

    foreach ($files as $file) {
        $path = __DIR__ . '/..' . $file;
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1');

        assert_contains(
            'Forbidden',
            (string) $output,
            $file . ' did not refuse to run when it was reached directly'
        );
    }
});

$runner->test('The configuration loader reports a missing credential clearly', function () {
    // The requirement is enforced where the credentials are used, at the
    // moment the connection is opened. A copy of the loader, with no
    // database defaults and no credentials, must therefore refuse to
    // connect and must say exactly which setting is missing.
    //
    // The loader itself still defines the settings; what it no longer does
    // is refuse to be READ by a file that never connects. The static check
    // suite depends on that: it runs in CI against a checkout that has no
    // config/config.local.php, because that file holds a real credential and
    // is deliberately not tracked (D-001).
    $temporaryDirectory = sys_get_temp_dir() . '/greencorner_config_test';
    @mkdir($temporaryDirectory, 0777, true);

    $loader = file_get_contents(__DIR__ . '/../config/database.php');
    $emptyDefaults = "<?php\n// No database defaults at all.\n";

    file_put_contents($temporaryDirectory . '/database.php', $loader);
    file_put_contents($temporaryDirectory . '/config.php', $emptyDefaults);
    file_put_contents($temporaryDirectory . '/config.local.php', "<?php\n// No credentials either.\n");

    $script = "<?php\n"
        . "define('GREENCORNER_APP', true);\n"
        . "try {\n"
        . "    require " . var_export($temporaryDirectory . '/database.php', true) . ";\n"
        . "    green_corner_require_database_settings();\n"
        . "    echo 'LOADED';\n"
        . "} catch (Throwable \$e) {\n"
        . "    echo \$e->getMessage();\n"
        . "}\n";

    file_put_contents($temporaryDirectory . '/run.php', $script);

    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($temporaryDirectory . '/run.php') . ' 2>&1');

    @unlink($temporaryDirectory . '/run.php');
    @unlink($temporaryDirectory . '/database.php');
    @unlink($temporaryDirectory . '/config.php');
    @unlink($temporaryDirectory . '/config.local.php');
    @rmdir($temporaryDirectory);

    $output = (string) $output;

    assert_not_contains('LOADED', $output, 'The application accepted a configuration with no credentials');
    assert_contains('configuration error', $output, 'The application did not report a configuration error');
    assert_contains('DB_HOST', $output, 'The error did not name the missing setting');
    assert_contains('config.local.php', $output, 'The error did not say which file to create');
});

$runner->test('A missing local configuration file stops the application (D-001)', function () {
    // The tracked config.php carries no password, so without the local file
    // there is nothing to fall back on. The application must refuse to reach
    // the database and must say which file to create.
    //
    // The requirement is enforced at the connection, not when the
    // configuration is read, so that a file which only READS the
    // configuration - a static check, or a documentation generator - can
    // load it without the credentials of this installation being present.
    // The application itself still never connects without them.
    $temporaryDirectory = sys_get_temp_dir() . '/greencorner_config_test';
    @mkdir($temporaryDirectory, 0777, true);

    $loader = file_get_contents(__DIR__ . '/../config/database.php');
    $trackedConfig = file_get_contents(__DIR__ . '/../config/config.php');

    file_put_contents($temporaryDirectory . '/database.php', $loader);
    file_put_contents($temporaryDirectory . '/config.php', $trackedConfig);

    $script = "<?php\n"
        . "define('GREENCORNER_APP', true);\n"
        . "try {\n"
        . "    require " . var_export($temporaryDirectory . '/database.php', true) . ";\n"
        . "    green_corner_require_database_settings();\n"
        . "    echo 'LOADED';\n"
        . "} catch (Throwable \$e) {\n"
        . "    echo \$e->getMessage();\n"
        . "}\n";

    file_put_contents($temporaryDirectory . '/run.php', $script);

    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($temporaryDirectory . '/run.php') . ' 2>&1');

    @unlink($temporaryDirectory . '/run.php');
    @unlink($temporaryDirectory . '/database.php');
    @unlink($temporaryDirectory . '/config.php');
    @rmdir($temporaryDirectory);

    $output = (string) $output;

    assert_not_contains('LOADED', $output, 'The application reached the database without config/config.local.php');
    assert_contains('configuration error', $output, 'The application did not report a configuration error');
    assert_contains('config/config.local.php', $output, 'The error did not name the missing file');
});

$runner->test('The configuration loader can be read without credentials (D-001)', function () {
    // Reading the configuration must not require the credential file, so
    // that the static check suite can run against a checkout that has no
    // secret in it. The requirement is enforced at the connection instead,
    // and the two checks above prove that it still holds.
    $temporaryDirectory = sys_get_temp_dir() . '/greencorner_config_test';
    @mkdir($temporaryDirectory, 0777, true);

    $loader = file_get_contents(__DIR__ . '/../config/database.php');
    $trackedConfig = file_get_contents(__DIR__ . '/../config/config.php');

    file_put_contents($temporaryDirectory . '/database.php', $loader);
    file_put_contents($temporaryDirectory . '/config.php', $trackedConfig);

    $script = "<?php\n"
        . "define('GREENCORNER_APP', true);\n"
        . "try {\n"
        . "    require " . var_export($temporaryDirectory . '/database.php', true) . ";\n"
        . "    echo 'LOADED';\n"
        . "} catch (Throwable \$e) {\n"
        . "    echo 'ERROR: ' . \$e->getMessage();\n"
        . "}\n";

    file_put_contents($temporaryDirectory . '/run.php', $script);

    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($temporaryDirectory . '/run.php') . ' 2>&1');

    @unlink($temporaryDirectory . '/run.php');
    @unlink($temporaryDirectory . '/database.php');
    @unlink($temporaryDirectory . '/config.php');
    @rmdir($temporaryDirectory);

    $output = (string) $output;

    assert_contains('LOADED', $output, 'The configuration could not be read without a credential file');
    assert_not_contains('ERROR:', $output, 'Reading the configuration raised an error');
});

$runner->test('The tracked configuration carries no database password (D-001)', function () {
    $tracked = file_get_contents(__DIR__ . '/../config/config.php');

    assert_true($tracked !== false, 'config/config.php could not be read');

    // The tracked file must not define DB_PASS at all.
    assert_not_contains(
        "define('DB_PASS'",
        $tracked,
        'The tracked configuration still defines a database password default'
    );

    // And it must not contain the password actually in use.
    assert_not_contains(DB_PASS, $tracked, 'The tracked configuration contains the real database password');
});

$runner->test('The loaded configuration resolves to usable credentials', function () {
    assert_true(defined('DB_HOST') && DB_HOST !== '', 'DB_HOST did not resolve to a value');
    assert_true(defined('DB_NAME') && DB_NAME !== '', 'DB_NAME did not resolve to a value');
    assert_true(defined('DB_USER') && DB_USER !== '', 'DB_USER did not resolve to a value');
    assert_true(defined('DB_PASS') && DB_PASS !== '', 'DB_PASS did not resolve to a value');
});

// ===========================================================================
$runner->group('2. Registration (FR-1.1 … FR-1.4)');
// ===========================================================================

$runner->test('A valid registration creates an active User-role account', function () {
    $email = test_email('register');
    $client = new HttpClient($GLOBALS['baseUrl']);

    $token = $client->csrfToken('/auth/register');
    assert_true($token !== '', 'Could not read a CSRF token from the register page');

    $response = $client->post('/auth/register', array(
        'csrf_token'            => $token,
        'full_name'             => 'Rina Shopper',
        'email'                 => $email,
        'password'              => 'gardenPassword1',
        'password_confirmation' => 'gardenPassword1',
    ));

    assert_true(
        in_array($response['status'], array(302, 303), true),
        'Expected a redirect after registration, got ' . $response['status']
    );

    $user = User::findByEmail($email);
    assert_true($user !== null, 'The user row was not created');
    assert_equals('User', $user['role_name'], 'The new account did not receive the User role');
    assert_equals(1, (int) $user['is_active'], 'The new account is not active');
    assert_equals(0, (int) $user['must_change_password'], 'must_change_password should default to 0');
});

$runner->test('The password is stored only as a salted hash (FR-1.3)', function () {
    $email = test_email('hash');
    $userId = make_user($email, 'plaintextPassword');

    $row = db_select_one('SELECT `password_hash` FROM `users` WHERE `id` = ?', 'i', array($userId));
    $hash = $row['password_hash'] ?? '';

    assert_true($hash !== '', 'No password hash was stored');
    assert_not_contains('plaintextPassword', $hash, 'The plaintext password appears in the stored hash');
    assert_true(
        password_verify('plaintextPassword', $hash),
        'The stored hash does not verify against the original password'
    );

    delete_user($userId);
});

$runner->test('Two users with the same password have different hashes (per-hash salt)', function () {
    $emailA = test_email('saltA');
    $emailB = test_email('saltB');
    $idA = make_user($emailA, 'identicalPassword');
    $idB = make_user($emailB, 'identicalPassword');

    $hashA = db_select_one('SELECT `password_hash` FROM `users` WHERE `id` = ?', 'i', array($idA))['password_hash'];
    $hashB = db_select_one('SELECT `password_hash` FROM `users` WHERE `id` = ?', 'i', array($idB))['password_hash'];

    assert_true($hashA !== $hashB, 'Two identical passwords produced the same hash; the salt is not per-hash');

    delete_user($idA);
    delete_user($idB);
});

$runner->test('A duplicate email is rejected with a clear message (FR-1.2)', function () {
    $email = test_email('duplicate');
    $userId = make_user($email, 'firstPassword');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/register');

    $client->post('/auth/register', array(
        'csrf_token'            => $token,
        'full_name'             => 'Duplicate Shopper',
        'email'                 => $email,
        'password'              => 'anotherPassword',
        'password_confirmation' => 'anotherPassword',
    ));

    // Follow the redirect to see the rendered error.
    $client->get('/auth/register');
    assert_contains('already registered', $client->body, 'The duplicate-email message was not shown');

    // Only one row may exist for that email.
    $count = db_select_one('SELECT COUNT(*) AS `total` FROM `users` WHERE `email` = ?', 's', array($email));
    assert_equals(1, (int) $count['total'], 'A second row was created for the same email');

    delete_user($userId);
});

$runner->test('The database rejects a direct duplicate email insert with 1062 (FR-1.2)', function () {
    $email = test_email('dbduplicate');
    $userId = make_user($email, 'firstPassword');

    $raised = false;
    $code = 0;
    try {
        $roleId = User::roleId('User');
        db_write(
            "INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role_id`)
             VALUES ('Duplicate', ?, 'hash', ?)",
            'si',
            array($email, $roleId)
        );
    } catch (RuntimeException $e) {
        $raised = true;
        $code = $e->getCode();
    }

    delete_user($userId);

    assert_true($raised, 'The database accepted a duplicate email');
    assert_equals(1062, $code, 'Expected error 1062 (ER_DUP_ENTRY), got ' . $code);
});

$runner->test('A 7-character password is rejected and an 8-character one is accepted (FR-1.3)', function () {
    $client = new HttpClient($GLOBALS['baseUrl']);

    // 7 characters: rejected.
    $shortEmail = test_email('shortpw');
    $token = $client->csrfToken('/auth/register');
    $client->post('/auth/register', array(
        'csrf_token'            => $token,
        'full_name'             => 'Short Password',
        'email'                 => $shortEmail,
        'password'              => 'seven77',
        'password_confirmation' => 'seven77',
    ));
    $client->get('/auth/register');

    assert_contains('at least 8 characters', $client->body, 'The weak-password message was not shown');

    $shortUser = User::findByEmail($shortEmail);
    assert_null($shortUser, 'An account was created despite the weak password');

    // 8 characters: accepted.
    $okEmail = test_email('okpw');
    $token = $client->csrfToken('/auth/register');
    $client->post('/auth/register', array(
        'csrf_token'            => $token,
        'full_name'             => 'Eight Characters',
        'email'                 => $okEmail,
        'password'              => 'eight888',
        'password_confirmation' => 'eight888',
    ));

    $okUser = User::findByEmail($okEmail);
    assert_true($okUser !== null, 'An 8-character password was rejected');

    delete_user((int) $okUser['id']);
});

$runner->test('Registration never grants the Admin role, even when requested (FR-1.4)', function () {
    $email = test_email('roleattempt');
    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/register');

    // The request body deliberately tries to become an Admin.
    $client->post('/auth/register', array(
        'csrf_token'            => $token,
        'full_name'             => 'Role Attempt',
        'email'                 => $email,
        'password'              => 'roleAttemptPw',
        'password_confirmation' => 'roleAttemptPw',
        'role_id'               => '2',
        'role'                  => 'Admin',
        'is_admin'              => '1',
    ));

    $user = User::findByEmail($email);
    assert_true($user !== null, 'The account was not created');
    assert_equals('User', $user['role_name'], 'A registration request managed to grant a privileged role');

    delete_user((int) $user['id']);
});

// ===========================================================================
$runner->group('3. Login, logout and post-login landing (FR-1.5 … FR-1.7)');
// ===========================================================================

$runner->test('Valid credentials establish a session and land on the User Dashboard', function () {
    $email = test_email('login');
    $userId = make_user($email, 'loginPassword1');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');

    $response = $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'loginPassword1',
    ));

    assert_true(
        in_array($response['status'], array(302, 303), true),
        'Expected a redirect after login, got ' . $response['status']
    );

    $location = $response['headers']['location'] ?? '';
    assert_contains('/dashboard', $location, 'A User was not sent to the User Dashboard');

    // The session cookie must be present.
    assert_true($client->cookie(SESSION_COOKIE_NAME) !== null, 'No session cookie was set');

    // The dashboard must now be reachable.
    $dashboard = $client->get('/dashboard');
    assert_equals(200, $dashboard['status'], 'The User Dashboard was not reachable after login');
    assert_contains('Welcome', $dashboard['body'], 'The dashboard did not render the greeting');

    delete_user($userId);
});

$runner->test('An Admin lands on the Admin Dashboard, never the User Dashboard', function () {
    $email = test_email('adminlogin');
    $userId = make_user($email, 'adminPassword1', 'Admin');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');

    $response = $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'adminPassword1',
    ));

    $location = $response['headers']['location'] ?? '';
    assert_contains('/admin/dashboard', $location, 'An Admin was not sent to the Admin Dashboard');

    // The Admin Dashboard path also ends in "/dashboard", so the check is
    // that the path is not the bare User Dashboard path.
    $path = parse_url($location, PHP_URL_PATH);
    assert_true($path !== '/dashboard', 'An Admin was sent to the User Dashboard');

    $dashboard = $client->get('/admin/dashboard');
    assert_equals(200, $dashboard['status'], 'The Admin Dashboard was not reachable after login');

    delete_user($userId);
});

$runner->test('A wrong password and an unknown email produce the SAME message (FR-1.5, SEC-12)', function () {
    $email = test_email('generror');
    $userId = make_user($email, 'correctPassword');

    $client = new HttpClient($GLOBALS['baseUrl']);

    // Case 1: the email exists but the password is wrong.
    // Decision D-003: the failure is shown inline on the login form, so the
    // message is in the response body of the POST itself.
    $token = $client->csrfToken('/auth/login');
    $response = $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'wrongPassword',
    ));
    $wrongPasswordMessage = $response['body'];

    // Case 2: the email does not exist at all.
    $client->resetCookies();
    $token = $client->csrfToken('/auth/login');
    $response = $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => test_email('nosuchuser'),
        'password'   => 'wrongPassword',
    ));
    $unknownEmailMessage = $response['body'];

    // Both must show the same generic message.
    assert_contains('do not match our records', $wrongPasswordMessage, 'The generic error was not shown');
    assert_contains('do not match our records', $unknownEmailMessage, 'The generic error was not shown');

    // The response must not disclose that the email exists.
    assert_not_contains('already registered', $wrongPasswordMessage, 'The error disclosed account existence');

    delete_user($userId);
});

$runner->test('A failed login shows the error inline and preserves the email but not the password (D-003)', function () {
    $email = test_email('inlineerror');
    $userId = make_user($email, 'inlinePassword1');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');

    $response = $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'theWrongPassword',
    ));

    // The form is re-rendered, not redirected away.
    assert_equals(200, $response['status'], 'The login form was not re-rendered after a failed sign-in');
    assert_contains('do not match our records', $response['body'], 'The error was not shown inline');

    // The email the visitor typed is preserved.
    assert_contains($email, $response['body'], 'The email address was not preserved');

    // The password is never echoed back.
    assert_not_contains('theWrongPassword', $response['body'], 'The password was echoed back in the response');

    delete_user($userId);
});

$runner->test('The session identifier changes on successful login (SEC-9)', function () {
    $email = test_email('sessionid');
    $userId = make_user($email, 'sessionIdPw');

    $client = new HttpClient($GLOBALS['baseUrl']);

    $token = $client->csrfToken('/auth/login');
    $before = $client->cookie(SESSION_COOKIE_NAME);

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'sessionIdPw',
    ));

    $after = $client->cookie(SESSION_COOKIE_NAME);

    assert_true($before !== null, 'No session cookie existed before login');
    assert_true($after !== null, 'No session cookie exists after login');
    assert_true($before !== $after, 'The session identifier did not change on login');

    delete_user($userId);
});

$runner->test('Logout destroys the session and clears the cookie (FR-1.6, SEC-11)', function () {
    $email = test_email('logout');
    $userId = make_user($email, 'logoutPassword');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'logoutPassword',
    ));

    $sessionId = $client->cookie(SESSION_COOKIE_NAME);
    assert_true($sessionId !== null, 'No session cookie after login');

    // The server-side session row must exist.
    $row = db_select_one(
        'SELECT `session_id` FROM `sessions` WHERE `session_id` = ?',
        's',
        array($sessionId)
    );
    assert_true($row !== null, 'The session was not stored in the database');

    // Log out.
    $token = $client->csrfToken('/dashboard');
    $client->post('/auth/logout', array('csrf_token' => $token));

    // The server-side row must be gone.
    $row = db_select_one(
        'SELECT `session_id` FROM `sessions` WHERE `session_id` = ?',
        's',
        array($sessionId)
    );
    assert_null($row, 'The server-side session record was not destroyed');

    // The old identifier must no longer authenticate.
    $client->resetCookies();
    $client->setCookie(SESSION_COOKIE_NAME, $sessionId);
    $response = $client->get('/dashboard');
    assert_equals(401, $response['status'], 'The old session identifier still authenticated the account');

    delete_user($userId);
});

// ===========================================================================
$runner->group('4. Role-based authorisation (FR-7.1 … FR-7.5)');
// ===========================================================================

$runner->test('An unauthenticated request to a protected page returns 401 and redirects to login', function () {
    $client = new HttpClient($GLOBALS['baseUrl']);

    $response = $client->get('/dashboard');

    assert_equals(401, $response['status'], 'Expected 401 for an unauthenticated request');
    assert_contains('/auth/login', $response['headers']['location'] ?? '', 'The request was not redirected to login');
});

$runner->test('A User reaching an Admin page receives 403 (FR-7.4)', function () {
    $email = test_email('useradmin');
    $userId = make_user($email, 'userToAdmin');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'userToAdmin',
    ));

    $response = $client->get('/admin/dashboard');

    assert_equals(403, $response['status'], 'A User was not refused the Admin Dashboard');
    assert_contains('Access denied', $response['body'], 'The 403 page was not rendered');

    delete_user($userId);
});

$runner->test('An Admin reaching the User Dashboard receives 403 (FR-7.5)', function () {
    $email = test_email('adminshop');
    $userId = make_user($email, 'adminToShop', 'Admin');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'adminToShop',
    ));

    $response = $client->get('/dashboard');

    assert_equals(403, $response['status'], 'An Admin was not refused the User Dashboard');
    assert_contains('Access denied', $response['body'], 'The 403 page was not rendered');

    delete_user($userId);
});

$runner->test('An Admin is not offered a cart badge in the navigation (FR-7.5)', function () {
    $email = test_email('nobadge');
    $userId = make_user($email, 'noBadgePw', 'Admin');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'noBadgePw',
    ));

    $client->get('/admin/dashboard');

    assert_not_contains('id="cart-count"', $client->body, 'The cart badge was rendered for an Admin');

    delete_user($userId);
});

$runner->test('The capability matrix is defined in exactly one place (FR-7.1, NFR-M-03)', function () {
    // The matrix must live in helpers/authorization.php only.
    $projectRoot = dirname(__DIR__);
    $offenders = array();

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        if (strpos($path, DIRECTORY_SEPARATOR . '.tools' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }
        if (strpos($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }

        $contents = file_get_contents($path);
        if (strpos($contents, 'function capability_matrix') !== false) {
            $offenders[] = $path;
        }
    }

    assert_equals(1, count($offenders), 'The capability matrix is defined in ' . count($offenders) . ' places');
    assert_contains('helpers', $offenders[0], 'The capability matrix is not in the central helper');
});

$runner->test('No protected route bypasses the central authorisation helper', function () {
    // The front controller must not contain role checks of its own; every
    // decision goes through require_capability() / require_role().
    $frontController = file_get_contents(dirname(__DIR__) . '/public/index.php');

    assert_not_contains(
        "current_role() === 'Admin'",
        $frontController,
        'The front controller contains its own role check'
    );
    assert_not_contains(
        "current_role() === 'User'",
        $frontController,
        'The front controller contains its own role check'
    );
});

// ===========================================================================
$runner->group('5. CSRF protection (FR-7.7)');
// ===========================================================================

$runner->test('A registration POST without a CSRF token is rejected', function () {
    $email = test_email('nocsrf');
    $client = new HttpClient($GLOBALS['baseUrl']);

    $response = $client->post('/auth/register', array(
        'full_name'             => 'No CSRF',
        'email'                 => $email,
        'password'              => 'noCsrfPassword',
        'password_confirmation' => 'noCsrfPassword',
    ));

    // Decision D-004: the form is re-rendered with 422, not redirected.
    assert_equals(422, $response['status'], 'Expected 422 for a CSRF-less registration POST');

    $user = User::findByEmail($email);
    assert_null($user, 'The registration succeeded without a CSRF token');
});

$runner->test('A login POST without a CSRF token is rejected', function () {
    $email = test_email('nocsrflogin');
    $userId = make_user($email, 'noCsrfLoginPw');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $client->post('/auth/login', array(
        'email'    => $email,
        'password' => 'noCsrfLoginPw',
    ));

    // The account must not be authenticated.
    $response = $client->get('/dashboard');
    assert_equals(401, $response['status'], 'The login succeeded without a CSRF token');

    delete_user($userId);
});

$runner->test('A rejected registration POST re-renders the form with 422 and preserves safe inputs (D-004)', function () {
    $email = test_email('csrf422register');
    $client = new HttpClient($GLOBALS['baseUrl']);

    $response = $client->post('/auth/register', array(
        'full_name'             => 'Safe Name',
        'email'                 => $email,
        'password'              => 'aSecretPassword',
        'password_confirmation' => 'aSecretPassword',
    ));

    // The status must be 422: a redirect would replace it with a 302 and
    // the visitor would lose the error (decision D-004).
    assert_equals(422, $response['status'], 'The rejected form was not answered with 422');
    assert_true(
        !isset($response['headers']['location']),
        'The rejected form was answered with a redirect, which loses the 422'
    );

    // The error is shown on the form itself.
    assert_contains('session expired', $response['body'], 'The CSRF error was not shown on the form');

    // The safe inputs are preserved.
    assert_contains('Safe Name', $response['body'], 'The full name was not preserved');
    assert_contains($email, $response['body'], 'The email address was not preserved');

    // The password is never echoed back.
    assert_not_contains('aSecretPassword', $response['body'], 'The password was echoed back in the response');
});

$runner->test('A rejected login POST re-renders the form with 422 and never echoes the password (D-004)', function () {
    $email = test_email('csrf422login');
    $client = new HttpClient($GLOBALS['baseUrl']);

    $response = $client->post('/auth/login', array(
        'email'    => $email,
        'password' => 'anotherSecretPassword',
    ));

    assert_equals(422, $response['status'], 'The rejected form was not answered with 422');
    assert_contains('session expired', $response['body'], 'The CSRF error was not shown on the form');
    assert_contains($email, $response['body'], 'The email address was not preserved');
    assert_not_contains('anotherSecretPassword', $response['body'], 'The password was echoed back in the response');
});

$runner->test('A CSRF token from another session is rejected (FR-7.7)', function () {
    $email = test_email('crosssession');
    $userId = make_user($email, 'crossSessionPw');

    // Session A: fetch a valid token.
    $clientA = new HttpClient($GLOBALS['baseUrl']);
    $tokenA = $clientA->csrfToken('/auth/login');

    // Session B: a completely separate browser.
    $clientB = new HttpClient($GLOBALS['baseUrl']);
    $clientB->csrfToken('/auth/login');

    // Use session A's token with session B's cookie.
    $clientB->post('/auth/register', array(
        'csrf_token'            => $tokenA,
        'full_name'             => 'Cross Session',
        'email'                 => test_email('cross'),
        'password'              => 'crossSessionPw',
        'password_confirmation' => 'crossSessionPw',
    ));

    $count = db_select_one(
        "SELECT COUNT(*) AS `total` FROM `users` WHERE `email` LIKE 'cross.%'"
    );
    assert_equals(0, (int) $count['total'], 'A cross-session CSRF token was accepted');

    delete_user($userId);
});

$runner->test('A logout POST without a CSRF token does not destroy the session', function () {
    $email = test_email('nocsrflogout');
    $userId = make_user($email, 'noCsrfLogout');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'noCsrfLogout',
    ));

    $sessionId = $client->cookie(SESSION_COOKIE_NAME);

    // Log out without a token.
    $client->post('/auth/logout', array());

    $row = db_select_one(
        'SELECT `session_id` FROM `sessions` WHERE `session_id` = ?',
        's',
        array($sessionId)
    );
    assert_true($row !== null, 'The session was destroyed by a CSRF-less logout request');

    delete_user($userId);
});

// ===========================================================================
$runner->group('6. Account status (FR-7.3, BR-14)');
// ===========================================================================

$runner->test('A deactivated account cannot log in (FR-6.11)', function () {
    $email = test_email('deactivated');
    $userId = make_user($email, 'deactivatedPw');

    db_write('UPDATE `users` SET `is_active` = 0 WHERE `id` = ?', 'i', array($userId));

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'deactivatedPw',
    ));

    $response = $client->get('/dashboard');
    assert_equals(401, $response['status'], 'A deactivated account was able to log in');

    delete_user($userId);
});

$runner->test('An existing session becomes invalid on the next request after deactivation (FR-7.3)', function () {
    $email = test_email('sessionkill');
    $userId = make_user($email, 'sessionKillPw');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'sessionKillPw',
    ));

    // The session works while the account is active.
    $response = $client->get('/dashboard');
    assert_equals(200, $response['status'], 'The session did not work while the account was active');

    // Deactivate the account.
    db_write('UPDATE `users` SET `is_active` = 0 WHERE `id` = ?', 'i', array($userId));

    // The next request must be refused.
    $response = $client->get('/dashboard');
    assert_equals(401, $response['status'], 'The session survived the account being deactivated');

    delete_user($userId);
});

$runner->test('A role change takes effect on the next request without re-login (FR-7.3, RBAC-6)', function () {
    $email = test_email('rolechange');
    $userId = make_user($email, 'roleChangePw', 'User');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'roleChangePw',
    ));

    // As a User, the Admin Dashboard is refused.
    $response = $client->get('/admin/dashboard');
    assert_equals(403, $response['status'], 'The User was not refused the Admin Dashboard');

    // Promote the account directly in the database.
    $adminRoleId = User::roleId('Admin');
    db_write('UPDATE `users` SET `role_id` = ? WHERE `id` = ?', 'ii', array($adminRoleId, $userId));

    // The next request must use the new role, with no re-login.
    $response = $client->get('/admin/dashboard');
    assert_equals(200, $response['status'], 'The role change did not take effect on the next request');

    delete_user($userId);
});

// ===========================================================================
$runner->group('7. Forced password change (FR-1.9)');
// ===========================================================================

$runner->test('An account with the forced-change flag is routed to the change-password page', function () {
    $email = test_email('forced');
    $userId = make_user($email, 'forcedPassword');

    db_write('UPDATE `users` SET `must_change_password` = 1 WHERE `id` = ?', 'i', array($userId));

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $response = $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'forcedPassword',
    ));

    $location = $response['headers']['location'] ?? '';
    assert_contains('/auth/change-password', $location, 'The user was not routed to the forced change password page');

    delete_user($userId);
});

$runner->test('The forced-change flag blocks navigation to every other protected page (FR-1.9)', function () {
    $email = test_email('forcedblock');
    $userId = make_user($email, 'forcedBlockPw');

    db_write('UPDATE `users` SET `must_change_password` = 1 WHERE `id` = ?', 'i', array($userId));

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'forcedBlockPw',
    ));

    // The dashboard must be blocked.
    $response = $client->get('/dashboard');
    assert_equals(403, $response['status'], 'The forced change did not block the User Dashboard');
    assert_contains('/auth/change-password', $response['headers']['location'] ?? '', 'The request was not redirected to the change-password page');

    delete_user($userId);
});

$runner->test('The forced-change flag still allows the public storefront to be browsed (D-006)', function () {
    $email = test_email('forcedstore');
    $userId = make_user($email, 'forcedStorePw');

    db_write('UPDATE `users` SET `must_change_password` = 1 WHERE `id` = ?', 'i', array($userId));

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'forcedStorePw',
    ));

    // The landing page must remain reachable.
    $response = $client->get('/');
    assert_equals(200, $response['status'], 'The landing page was blocked by the forced-change flag');
    assert_true(
        !isset($response['headers']['location']),
        'The landing page redirected away while a forced change was pending'
    );

    // The account area must still be blocked.
    $response = $client->get('/dashboard');
    assert_equals(403, $response['status'], 'The User Dashboard was not blocked by the forced-change flag');

    delete_user($userId);
});

$runner->test('The forced-change restriction applies to an Admin as well (D-006)', function () {
    $email = test_email('forcedadmin');
    $userId = make_user($email, 'forcedAdminPw', 'Admin');

    db_write('UPDATE `users` SET `must_change_password` = 1 WHERE `id` = ?', 'i', array($userId));

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'forcedAdminPw',
    ));

    // The Admin Dashboard must be blocked until the password is changed.
    $response = $client->get('/admin/dashboard');
    assert_equals(403, $response['status'], 'The Admin Dashboard was not blocked by the forced-change flag');
    assert_contains('/auth/change-password', $response['headers']['location'] ?? '', 'The Admin was not redirected to the change-password page');

    // The storefront stays browsable.
    $response = $client->get('/');
    assert_equals(200, $response['status'], 'The landing page was blocked for a forced-change Admin');

    delete_user($userId);
});

$runner->test('After a valid change the flag is cleared and the old password stops working (FR-1.9)', function () {
    $email = test_email('forcedchange');
    $userId = make_user($email, 'oldForcedPw');

    db_write('UPDATE `users` SET `must_change_password` = 1 WHERE `id` = ?', 'i', array($userId));

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'oldForcedPw',
    ));

    $token = $client->csrfToken('/auth/change-password');
    $response = $client->post('/auth/change-password', array(
        'csrf_token'            => $token,
        'current_password'      => 'oldForcedPw',
        'new_password'          => 'brandNewPassword',
        'new_password_confirmation' => 'brandNewPassword',
    ));

    assert_true(
        in_array($response['status'], array(302, 303), true),
        'Expected a redirect after the password change, got ' . $response['status']
    );

    // The flag must be cleared.
    $user = User::findByEmail($email);
    assert_equals(0, (int) $user['must_change_password'], 'The forced-change flag was not cleared');

    // The old password must no longer work; the new one must.
    assert_false(
        User::verifyPassword('oldForcedPw', (string) $user['password_hash']),
        'The old password still verifies'
    );
    assert_true(
        User::verifyPassword('brandNewPassword', (string) $user['password_hash']),
        'The new password does not verify'
    );

    delete_user($userId);
});

$runner->test('A new password shorter than 8 characters is rejected (FR-1.9, FR-1.3)', function () {
    $email = test_email('forcedshort');
    $userId = make_user($email, 'forcedShortPw');

    db_write('UPDATE `users` SET `must_change_password` = 1 WHERE `id` = ?', 'i', array($userId));

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'forcedShortPw',
    ));

    $token = $client->csrfToken('/auth/change-password');
    $client->post('/auth/change-password', array(
        'csrf_token'            => $token,
        'current_password'      => 'forcedShortPw',
        'new_password'          => 'short12',
        'new_password_confirmation' => 'short12',
    ));

    $client->get('/auth/change-password');
    assert_contains('at least 8 characters', $client->body, 'The weak new-password message was not shown');

    // The flag must still be set.
    $user = User::findByEmail($email);
    assert_equals(1, (int) $user['must_change_password'], 'The flag was cleared despite the invalid password');

    delete_user($userId);
});

$runner->test('A wrong current password is rejected and the password is unchanged', function () {
    $email = test_email('wrongcurrent');
    $userId = make_user($email, 'originalPw');

    db_write('UPDATE `users` SET `must_change_password` = 1 WHERE `id` = ?', 'i', array($userId));

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'originalPw',
    ));

    $token = $client->csrfToken('/auth/change-password');
    $client->post('/auth/change-password', array(
        'csrf_token'            => $token,
        'current_password'      => 'notThePassword',
        'new_password'          => 'newPasswordValue',
        'new_password_confirmation' => 'newPasswordValue',
    ));

    $client->get('/auth/change-password');
    assert_contains('current password is not correct', $client->body, 'The wrong-current-password message was not shown');

    $user = User::findByEmail($email);
    assert_true(
        User::verifyPassword('originalPw', (string) $user['password_hash']),
        'The password was changed despite the wrong current password'
    );

    delete_user($userId);
});

// ===========================================================================
$runner->group('8. Session lifetime and storage (FR-1.10, FR-7.6)');
// ===========================================================================

$runner->test('Session data is stored in the database (FR-7.6)', function () {
    $email = test_email('dbsession');
    $userId = make_user($email, 'dbSessionPw');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'dbSessionPw',
    ));

    $sessionId = $client->cookie(SESSION_COOKIE_NAME);
    assert_true($sessionId !== null, 'No session cookie was set');

    $row = db_select_one(
        'SELECT `session_id`, `user_id` FROM `sessions` WHERE `session_id` = ?',
        's',
        array($sessionId)
    );

    assert_true($row !== null, 'The session was not persisted in the database');
    assert_equals($userId, (int) $row['user_id'], 'The session row was not linked to the account');

    delete_user($userId);
});

$runner->test('The session cookie is httpOnly and SameSite=Lax (FR-7.6, SEC-10)', function () {
    $client = new HttpClient($GLOBALS['baseUrl']);

    // The very first request establishes the session, so this is the
    // response that carries the Set-Cookie header. A later request re-uses
    // the existing session and sends no Set-Cookie header.
    $response = $client->get('/auth/login');
    $setCookie = $response['headers']['set-cookie'] ?? '';

    assert_true($setCookie !== '', 'No Set-Cookie header was sent');

    $cookieHeader = $setCookie;
    assert_contains('HttpOnly', $cookieHeader, 'The session cookie is not httpOnly');
    assert_contains('SameSite=Lax', $cookieHeader, 'The session cookie does not declare SameSite=Lax');
});

$runner->test('The Secure cookie flag is absent for local HTTP development (FR-7.6)', function () {
    $context = stream_context_create(array('http' => array(
        'method'        => 'GET',
        'timeout'       => 20,
        'ignore_errors' => true,
    )));
    @file_get_contents($GLOBALS['baseUrl'] . '/auth/login', false, $context);
    $headers = isset($http_response_header) ? $http_response_header : array();

    $cookieHeader = '';
    foreach ($headers as $line) {
        if (stripos($line, 'set-cookie:') === 0) {
            $cookieHeader = $line;
            break;
        }
    }

    assert_not_contains('Secure', $cookieHeader, 'The Secure flag must be disabled for local HTTP development');
});

$runner->test('An idle session beyond 8 hours is rejected server-side (FR-1.10)', function () {
    $email = test_email('idle');
    $userId = make_user($email, 'idleSessionPw');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'idleSessionPw',
    ));

    $sessionId = $client->cookie(SESSION_COOKIE_NAME);

    // Simulate 9 hours of inactivity by back-dating last_activity.
    $nineHoursAgo = time() - (9 * 3600);
    db_write(
        'UPDATE `sessions` SET `last_activity` = ? WHERE `session_id` = ?',
        'is',
        array($nineHoursAgo, $sessionId)
    );

    $response = $client->get('/dashboard');
    assert_equals(401, $response['status'], 'A session idle for 9 hours was still accepted');

    delete_user($userId);
});

$runner->test('A session older than 7 days is rejected even when active (FR-1.10)', function () {
    $email = test_email('absolute');
    $userId = make_user($email, 'absoluteSessionPw');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'absoluteSessionPw',
    ));

    $sessionId = $client->cookie(SESSION_COOKIE_NAME);

    // Back-date the creation time to 8 days ago, keeping it active.
    $eightDaysAgo = time() - (8 * 86400);
    db_write(
        'UPDATE `sessions` SET `created_at` = ?, `last_activity` = ? WHERE `session_id` = ?',
        'iis',
        array($eightDaysAgo, time(), $sessionId)
    );

    $response = $client->get('/dashboard');
    assert_equals(401, $response['status'], 'A session older than 7 days was still accepted');

    delete_user($userId);
});

$runner->test('A session active for less than 8 hours is accepted', function () {
    $email = test_email('active');
    $userId = make_user($email, 'activeSessionPw');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'activeSessionPw',
    ));

    $sessionId = $client->cookie(SESSION_COOKIE_NAME);

    // Back-date the activity to 7 hours ago: still inside the idle window.
    $sevenHoursAgo = time() - (7 * 3600);
    db_write(
        'UPDATE `sessions` SET `last_activity` = ? WHERE `session_id` = ?',
        'is',
        array($sevenHoursAgo, $sessionId)
    );

    $response = $client->get('/dashboard');
    assert_equals(200, $response['status'], 'A session active within the idle window was rejected');

    delete_user($userId);
});

// ===========================================================================
$runner->group('9. Error pages and routing');
// ===========================================================================

$runner->test('An unknown URL returns 404 (FR-2.13)', function () {
    $client = new HttpClient($GLOBALS['baseUrl']);
    $response = $client->get('/this-route-does-not-exist');

    assert_equals(404, $response['status'], 'An unknown URL did not return 404');
    assert_contains('404', $response['body'], 'The 404 page was not rendered');
});

$runner->test('The 404 page discloses no internal detail (SRS §15.1)', function () {
    $client = new HttpClient($GLOBALS['baseUrl']);
    $response = $client->get('/no-such-page-here');

    $body = $response['body'];

    assert_not_contains('Stack trace', $body, 'A stack trace was disclosed');
    assert_not_contains('E:\\GreenCorner', $body, 'A file path was disclosed');
    assert_not_contains('SELECT ', $body, 'An SQL fragment was disclosed');
    assert_not_contains('Fatal error', $body, 'A PHP error was disclosed');
});

$runner->test('The 403 page discloses no data from the attempted resource', function () {
    $email = test_email('nodetail');
    $userId = make_user($email, 'noDetailPw');

    $client = new HttpClient($GLOBALS['baseUrl']);
    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => $email,
        'password'   => 'noDetailPw',
    ));

    $response = $client->get('/admin/dashboard');

    assert_equals(403, $response['status'], 'Expected 403');
    assert_not_contains('Stack trace', $response['body'], 'A stack trace was disclosed');
    assert_not_contains('SELECT ', $response['body'], 'An SQL fragment was disclosed');

    delete_user($userId);
});

// ===========================================================================
$runner->group('10. Input handling and output encoding');
// ===========================================================================

$runner->test('A successful registration is not auto-authenticated (FR-1.1)', function () {
    $email = test_email('noauto');
    $client = new HttpClient($GLOBALS['baseUrl']);

    $token = $client->csrfToken('/auth/register');
    $client->post('/auth/register', array(
        'csrf_token'            => $token,
        'full_name'             => 'No Auto Login',
        'email'                 => $email,
        'password'              => 'noAutoLoginPw',
        'password_confirmation' => 'noAutoLoginPw',
    ));

    // The session must not authenticate the new account.
    $response = $client->get('/dashboard');
    assert_equals(401, $response['status'], 'Registration authenticated the account automatically');

    delete_user((int) User::findByEmail($email)['id']);
});

$runner->test('A script tag in the name field is rendered as text, not executed (NFR-S-03)', function () {
    $client = new HttpClient($GLOBALS['baseUrl']);

    // The email is deliberately invalid so that the registration is
    // rejected and the submitted name is echoed back on the re-rendered
    // form, which is where the output encoding is exercised.
    $token = $client->csrfToken('/auth/register');
    $client->post('/auth/register', array(
        'csrf_token'            => $token,
        'full_name'             => '<script>alert("xss")</script>',
        'email'                 => 'not-a-valid-email',
        'password'              => 'xssTestPassword',
        'password_confirmation' => 'xssTestPassword',
    ));

    // The name is echoed back on the re-rendered form.
    $client->get('/auth/register');
    $body = $client->body;

    assert_not_contains('<script>alert("xss")</script>', $body, 'The script tag was rendered as live markup');
    assert_contains('&lt;script&gt;', $body, 'The script tag was not output-encoded');
});

$runner->test('A malformed email is rejected (ER-1)', function () {
    $client = new HttpClient($GLOBALS['baseUrl']);

    $token = $client->csrfToken('/auth/register');
    $client->post('/auth/register', array(
        'csrf_token'            => $token,
        'full_name'             => 'Bad Email',
        'email'                 => 'not-a-valid-email',
        'password'              => 'badEmailPassword',
        'password_confirmation' => 'badEmailPassword',
    ));

    $client->get('/auth/register');
    assert_contains('valid email address', $client->body, 'The invalid-email message was not shown');

    $count = db_select_one("SELECT COUNT(*) AS `total` FROM `users` WHERE `email` = 'not-a-valid-email'");
    assert_equals(0, (int) $count['total'], 'A row was created for a malformed email');
});

$runner->test('No INSERT IGNORE or UPDATE IGNORE appears in the code base (R-SQL-7, NFR-M-04)', function () {
    $projectRoot = dirname(__DIR__);
    $offenders = array();

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        if (strpos($path, DIRECTORY_SEPARATOR . '.tools' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }
        if (strpos($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }

        $contents = file_get_contents($path);
        if (stripos($contents, 'INSERT IGNORE') !== false || stripos($contents, 'UPDATE IGNORE') !== false) {
            $offenders[] = $path;
        }
    }

    assert_equals(0, count($offenders), 'IGNORE variants found in: ' . implode(', ', $offenders));
});

$runner->test('No string concatenation of user input into SQL is present', function () {
    // Every query in the application must go through a prepared statement.
    // This test checks that the helper layer has no concatenated SQL calls.
    $dbHelper = file_get_contents(dirname(__DIR__) . '/helpers/db.php');

    assert_contains(
        'db_prepare',
        $dbHelper,
        'The database helper does not use prepared statements'
    );

    // The application code must not build SQL by concatenating request data.
    $offenders = array();

    foreach (glob(dirname(__DIR__) . '/controllers/*.php') as $file) {
        $contents = file_get_contents($file);
        // Look for a query string built with $_POST or $_GET directly.
        if (preg_match('/(?:SELECT|INSERT|UPDATE|DELETE)[^;]*\$_(?:POST|GET)/i', $contents)) {
            $offenders[] = $file;
        }
    }

    assert_equals(0, count($offenders), 'Request data concatenated into SQL in: ' . implode(', ', $offenders));
});

// ===========================================================================
// Run
// ===========================================================================

exit($runner->summary());
