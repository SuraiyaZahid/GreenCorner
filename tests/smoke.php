<?php
/**
 * GreenCorner — Phase 1 smoke test.
 *
 * Walks the real user journey over HTTP and prints what happened at each
 * step, so the behaviour can be confirmed by eye as well as by the
 * assertions in phase1_tests.php.
 */

require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../models/User.php';

$base = $argv[1] ?? 'http://127.0.0.1:8000';
$client = new HttpClient($base);
$email = 'smoke.' . bin2hex(random_bytes(4)) . '@example.test';

function step(string $label, string $result): void
{
    echo str_pad($label, 46) . $result . "\n";
}

// 1. Landing page
$r = $client->get('/');
step('GET /', $r['status'] . ' ' . (strpos($client->body, 'GreenCorner') !== false ? '(home page)' : ''));

// 2. Unknown URL
$r = $client->get('/no-such-page');
step('GET /no-such-page', $r['status'] . ' (404 page)');

// 3. Registration
$token = $client->csrfToken('/auth/register');
$r = $client->post('/auth/register', array(
    'csrf_token'            => $token,
    'full_name'             => 'Smoke Tester',
    'email'                 => $email,
    'password'              => 'smokePassword1',
    'password_confirmation' => 'smokePassword1',
));
step('POST /auth/register', $r['status'] . ' -> ' . ($r['headers']['location'] ?? '(none)'));

$user = User::findByEmail($email);
step('  account created', $user === null ? 'NO' : 'YES, role=' . $user['role_name'] . ', active=' . $user['is_active']);

// 4. Registration does not authenticate
$r = $client->get('/dashboard');
step('GET /dashboard (not logged in)', $r['status'] . ' -> ' . ($r['headers']['location'] ?? '(none)'));

// 5. Login
$token = $client->csrfToken('/auth/login');
$r = $client->post('/auth/login', array(
    'csrf_token' => $token,
    'email'      => $email,
    'password'   => 'smokePassword1',
));
step('POST /auth/login', $r['status'] . ' -> ' . ($r['headers']['location'] ?? '(none)'));

// 6. User Dashboard
$r = $client->get('/dashboard');
step('GET /dashboard (logged in)', $r['status']);

// 7. Admin Dashboard is refused for a User
$r = $client->get('/admin/dashboard');
step('GET /admin/dashboard (as User)', $r['status'] . ' (403 expected)');

// 8. Wrong password — decision D-003: the error is shown inline on the
// re-rendered login form, so it is in the POST response itself.
$client2 = new HttpClient($base);
$token = $client2->csrfToken('/auth/login');
$r = $client2->post('/auth/login', array(
    'csrf_token' => $token,
    'email'      => $email,
    'password'   => 'wrongPassword',
));
step('POST /auth/login (wrong password)', $r['status'] . ' (200 expected, inline error)');
step('  generic error shown', strpos($r['body'], 'do not match our records') !== false ? 'YES' : 'MISSING');
step('  email preserved', strpos($r['body'], $email) !== false ? 'YES' : 'MISSING');
step('  password echoed back', strpos($r['body'], 'wrongPassword') !== false ? 'YES (BUG)' : 'NO');

// 9. Missing CSRF token — decision D-004: the form is re-rendered with 422.
$r = $client2->post('/auth/login', array('email' => $email, 'password' => 'smokePassword1'));
step('POST /auth/login without CSRF', $r['status'] . ' (422 expected)');
step('  CSRF error shown', strpos($r['body'], 'session expired') !== false ? 'YES' : 'MISSING');

// 10. Logout
$token = $client->csrfToken('/dashboard');
$r = $client->post('/auth/logout', array('csrf_token' => $token));
step('POST /auth/logout', $r['status'] . ' -> ' . ($r['headers']['location'] ?? '(none)'));

$r = $client->get('/dashboard');
step('GET /dashboard after logout', $r['status'] . ' (401 expected)');

// Cleanup
if ($user !== null) {
    db_write('DELETE FROM `sessions` WHERE `user_id` = ?', 'i', array((int) $user['id']));
    db_write('DELETE FROM `users` WHERE `id` = ?', 'i', array((int) $user['id']));
}
echo "\nSmoke test finished.\n";
