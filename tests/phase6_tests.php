<?php
/**
 * GreenCorner — Phase 6 Test Suite
 *
 * Covers the Phase 6 work:
 *
 *   1. The 500 error page (P-22, §15.3, ER-19, AC-44) — the ordinary path.
 *   2. The 500 error page during a DATABASE OUTAGE — the path that used to
 *      truncate the response, because the shared layout queried the database
 *      while the error page was being rendered.
 *   3. The 403 page during a database outage.
 *   4. The fault-injection routes are invisible to a visitor and to a User.
 *   5. The nonce-based Content Security Policy.
 *   6. The security headers.
 *
 * IMPORTANT: the database-outage test AUTHENTICATES FIRST. A logged-out
 * request never reaches the database in current_user(), because it returns
 * null before the lookup. A logged-out outage test would therefore pass
 * vacuously and prove nothing. The test signs in as the Admin, so the
 * account lookup really does reach the database and really does fail.
 *
 * Usage:
 *   php tests/phase6_tests.php [baseUrl]
 */

require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/HttpClient.php';

$baseUrl = $argv[1] ?? 'http://127.0.0.1:8000';

$runner = new TestRunner();

/**
 * The strings that must never appear in an error response.
 *
 * These cover every disclosure class named in NFR-R-05: a stack trace, an
 * SQL fragment, a file path, and a PHP diagnostic.
 */
function disclosure_needles(): array
{
    return array(
        'Stack trace',
        'Fatal error',
        'Parse error',
        'Warning:',
        'Notice:',
        'Deprecated:',
        'Exception',
        'RuntimeException',
        'PDOException',
        'mysqli',
        'SQLSTATE',
        'SELECT ',
        'INSERT ',
        'UPDATE ',
        'DELETE ',
        'GreenCorner\\',
        '.php',
        'injected',
    );
}

/**
 * Assert that a response body discloses nothing.
 */
function assert_no_disclosure(string $body, string $message = ''): void
{
    foreach (disclosure_needles() as $needle) {
        if (stripos($body, $needle) !== false) {
            throw new Exception(
                ($message !== '' ? $message . ' — ' : '')
                . 'the response discloses "' . $needle . '"'
            );
        }
    }
}

/**
 * Sign in as the Admin and return the client, which now holds the session.
 */
function sign_in_as_admin(HttpClient $client, string $baseUrl): HttpClient
{
    $token = $client->csrfToken('/auth/login');

    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => 'admin@greencorner.test',
        'password'   => 'Admin123!',
    ));

    $status = $client->status;

    if ($status !== 302 && $status !== 200) {
        throw new Exception('The Admin could not sign in; status was ' . $status);
    }

    return $client;
}

// ---------------------------------------------------------------------------
// 1. The ordinary 500 path
// ---------------------------------------------------------------------------

$runner->group('1. The 500 error page (the ordinary path)');

$runner->test('An unexpected error returns HTTP 500', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/error');

    assert_equals(500, $response['status'], 'The unexpected error must return HTTP 500');
});

$runner->test('The 500 page shows the generic message', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/error');

    assert_contains('Something went wrong', $response['body']);
    assert_contains('error-code', $response['body'], 'The 500 page should render the branded error panel');
});

$runner->test('The 500 page discloses no internal detail (NFR-R-05, ER-19)', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/error');

    assert_no_disclosure($response['body'], 'The 500 page');
});

$runner->test('The 500 page is a complete HTML document', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/error');

    assert_contains('</html>', $response['body'], 'The 500 page must not be truncated');
    assert_contains('<html lang="en">', $response['body']);
});

$runner->test('The 500 page keeps the branded layout', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/error');

    assert_contains('site-header', $response['body'], 'The 500 page should keep the shared layout');
    assert_contains('assets/css/style.css', $response['body']);
});

$runner->test('The 500 page is not a redirect', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/error');

    assert_true(
        !isset($response['headers']['location']),
        'The 500 page must not redirect'
    );
});

$runner->test('The 500 page sets no new session cookie', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/error');

    assert_true(
        !isset($response['headers']['set-cookie']),
        'The 500 page must not set a new session cookie'
    );
});

// ---------------------------------------------------------------------------
// 2. The 500 page during a database outage — the path that used to truncate
// ---------------------------------------------------------------------------

$runner->group('2. The 500 page during a DATABASE OUTAGE');

$runner->test('The outage test is authenticated, so the account lookup really fails', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    // The account must be signed in, otherwise current_user() returns null
    // before it reaches the database and the outage would never be exercised.
    // The Admin dashboard is the Admin-reachable page used here.
    $response = $client->get('/admin/dashboard');

    assert_equals(200, $response['status'], 'The Admin session must be established');
    assert_contains('Admin dashboard', $response['body'], 'The Admin must be signed in');
});

$runner->test('The 500 page still returns HTTP 500 during a database outage', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/database-outage');

    assert_equals(500, $response['status'], 'The outage must still be answered with HTTP 500');
});

$runner->test('The 500 page still shows the generic message during a database outage', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/database-outage');

    assert_contains('Something went wrong', $response['body']);
});

$runner->test('The 500 page is COMPLETE during a database outage (not truncated)', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/database-outage');

    // This is the assertion that the pre-Phase-6 code failed: the shared
    // layout queried the database while the error page was being rendered,
    // so the response was cut off part-way through the header.
    assert_contains('</html>', $response['body'], 'The 500 page must not be truncated during an outage');
    assert_contains('<html lang="en">', $response['body']);
    assert_contains('error-code', $response['body'], 'The error panel must be present during an outage');
    assert_contains('Something went wrong', $response['body']);
});

$runner->test('The 500 page discloses no internal detail during a database outage', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/database-outage');

    assert_no_disclosure($response['body'], 'The 500 page during an outage');
});

$runner->test('The 500 page during an outage does not leak the database error', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/database-outage');

    assert_not_contains('Database connection failed', $response['body']);
    assert_not_contains('connect_error', $response['body']);
    assert_not_contains('mysqli', $response['body']);
});

// ---------------------------------------------------------------------------
// 3. Normal pages and the 403 page during a database outage
// ---------------------------------------------------------------------------

$runner->group('3. Other pages during a database outage');

$runner->test('A normal page still renders during a database outage', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    // The fault makes the database unreachable, then the request continues
    // into the controller, whose own lookup fails. The shared layout must
    // still render the page.
    $response = $client->get('/_fault/error');

    assert_equals(500, $response['status']);
    assert_contains('</html>', $response['body']);
});

$runner->test('The 403 page renders completely during a database outage', function () use ($baseUrl) {
    // A User reaching an Admin resource is refused with HTTP 403. The 403
    // page includes the shared layout, so it needs the same protection.
    $client = new HttpClient($baseUrl);

    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => 'user@greencorner.test',
        'password'   => 'User123!',
    ));

    $response = $client->get('/admin/dashboard');

    assert_equals(403, $response['status'], 'A User must be refused with HTTP 403');
    assert_contains('Access denied', $response['body']);
    assert_contains('</html>', $response['body'], 'The 403 page must be complete');
});

$runner->test('The 403 page discloses no internal detail', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => 'user@greencorner.test',
        'password'   => 'User123!',
    ));

    $response = $client->get('/admin/dashboard');

    assert_no_disclosure($response['body'], 'The 403 page');
});

// ---------------------------------------------------------------------------
// 4. The fault-injection routes are not publicly reachable
// ---------------------------------------------------------------------------

$runner->group('4. The fault-injection routes are restricted');

$runner->test('A visitor receives 404 from the fault route', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->get('/_fault/error');

    assert_equals(404, $response['status'], 'The fault route must be invisible to a visitor');
});

$runner->test('A visitor receives 404 from the database-outage route', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->get('/_fault/database-outage');

    assert_equals(404, $response['status']);
});

$runner->test('A visitor receives 404 from the render-failure route', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->get('/_fault/render-failure');

    assert_equals(404, $response['status'], 'The render-failure route must be invisible to a visitor');
});

$runner->test('A User receives 404 from the fault route', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => 'user@greencorner.test',
        'password'   => 'User123!',
    ));

    $response = $client->get('/_fault/error');

    assert_equals(404, $response['status'], 'Only an Admin may use the fault route');
});

$runner->test('The fault route does not disclose that it exists', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->get('/_fault/error');

    assert_not_contains('fault', strtolower($response['body']), 'The 404 page must not describe the fault route');
});

// ---------------------------------------------------------------------------
// 4a. The static fallback — when even the branded 500 page cannot be rendered
// ---------------------------------------------------------------------------

$runner->group('4a. The static fallback, when the branded 500 page also fails');

$runner->test('The static fallback still returns HTTP 500', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    // The fault makes the shared layout fail, so the branded 500 page cannot
    // be completed either. The front controller's own fallback is what must
    // reach the browser.
    $response = $client->get('/_fault/render-failure');

    assert_equals(500, $response['status'], 'The fallback must still answer with HTTP 500');
});

$runner->test('The static fallback shows the generic message', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/render-failure');

    assert_contains('Something went wrong', $response['body']);
});

$runner->test('The static fallback is a COMPLETE HTML document', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/render-failure');

    assert_contains('<!DOCTYPE html>', $response['body']);
    assert_contains('<html lang="en">', $response['body']);
    assert_contains('</html>', $response['body'], 'The fallback must not be truncated');
});

$runner->test('The static fallback is not preceded by partial branded output', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/render-failure');

    // The branded layout must not have written a fragment of itself before
    // the fallback was emitted: the client must receive one whole document,
    // not a fragment followed by the fallback.
    assert_equals(1, substr_count($response['body'], '<body'), 'The fallback must contain exactly one body');
    assert_equals(1, substr_count($response['body'], '</html>'), 'The fallback must contain exactly one closing html tag');
    assert_equals(1, substr_count($response['body'], '<!DOCTYPE html>'), 'The fallback must contain exactly one doctype');
    assert_not_contains('site-header', $response['body'], 'The branded layout must not have written a fragment');
    assert_not_contains('error-code', $response['body'], 'The branded error panel must not have been written');
});

$runner->test('The static fallback discloses no internal detail', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/render-failure');

    assert_no_disclosure($response['body'], 'The static fallback');
});

$runner->test('The static fallback does not leak the render fault', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/render-failure');

    assert_not_contains('Injected layout render fault', $response['body']);
    assert_not_contains('Injected render failure', $response['body']);
    assert_not_contains('RuntimeException', $response['body']);
});

$runner->test('The static fallback offers a way back to the home page', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/render-failure');

    assert_contains('Go to the home page', $response['body']);
});

$runner->test('The static fallback carries no script and no style', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/render-failure');

    // The fallback is self-contained on purpose: no include, no database, no
    // session, and therefore nothing that can fail in turn.
    assert_not_contains('<script', $response['body'], 'The fallback must carry no script');
    assert_not_contains('<style', $response['body'], 'The fallback must carry no inline style');
    assert_not_contains('assets/css/style.css', $response['body'], 'The fallback must not depend on an asset');
});

$runner->test('The static fallback sets nosniff', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/render-failure');

    assert_equals('nosniff', $response['headers']['x-content-type-options'] ?? '');
});

// ---------------------------------------------------------------------------
// 5. The Content Security Policy
// ---------------------------------------------------------------------------

$runner->group('5. The Content Security Policy');

$runner->test('Every response carries a Content-Security-Policy header', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->get('/');

    assert_true(
        isset($response['headers']['content-security-policy']),
        'The home page must carry a Content-Security-Policy header'
    );
});

$runner->test('The CSP has no unsafe-inline and no unsafe-eval', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->get('/');
    $csp = $response['headers']['content-security-policy'] ?? '';

    assert_not_contains("'unsafe-inline'", $csp, 'The CSP must not allow unsafe-inline');
    assert_not_contains('"unsafe-inline"', $csp);
    assert_not_contains("'unsafe-eval'", $csp, 'The CSP must not allow unsafe-eval');
    assert_not_contains('"unsafe-eval"', $csp);
});

$runner->test('The CSP restricts script-src to self and a nonce', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->get('/');
    $csp = $response['headers']['content-security-policy'] ?? '';

    assert_contains('script-src', $csp);
    assert_contains("'self'", $csp);
    assert_contains("'nonce-", $csp, 'The CSP must carry a nonce for the one inline script');
});

$runner->test('The CSP sets object-src none and frame-ancestors none', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->get('/');
    $csp = $response['headers']['content-security-policy'] ?? '';

    assert_contains("object-src 'none'", $csp);
    assert_contains("frame-ancestors 'none'", $csp);
    assert_contains("base-uri 'self'", $csp);
});

$runner->test('The inline script carries the nonce from the CSP header', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->get('/');
    $csp = $response['headers']['content-security-policy'] ?? '';

    // Extract the nonce from the header.
    if (preg_match("/'nonce-([^']+)'/", $csp, $m) !== 1) {
        throw new Exception('The CSP header carries no nonce');
    }

    $nonce = $m[1];

    assert_contains(
        'nonce="' . $nonce . '"',
        $response['body'],
        'The inline script must carry exactly the nonce from the header'
    );
});

$runner->test('The nonce differs between requests', function () use ($baseUrl) {
    $first = new HttpClient($baseUrl);
    $second = new HttpClient($baseUrl);

    $a = $first->get('/');
    $b = $second->get('/');

    $nonceA = '';
    $nonceB = '';

    preg_match("/'nonce-([^']+)'/", $a['headers']['content-security-policy'] ?? '', $mA);
    preg_match("/'nonce-([^']+)'/", $b['headers']['content-security-policy'] ?? '', $mB);

    $nonceA = $mA[1] ?? '';
    $nonceB = $mB[1] ?? '';

    assert_true($nonceA !== '', 'The first response must carry a nonce');
    assert_true($nonceB !== '', 'The second response must carry a nonce');
    assert_true($nonceA !== $nonceB, 'The nonce must not be reused between requests');
});

$runner->test('The CSP is present on the 404 page', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->get('/no-such-page-phase6');

    assert_equals(404, $response['status']);
    assert_true(
        isset($response['headers']['content-security-policy']),
        'The 404 page must carry a Content-Security-Policy header'
    );
});

$runner->test('The CSP is present on the 403 page', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => 'user@greencorner.test',
        'password'   => 'User123!',
    ));

    $response = $client->get('/admin/dashboard');

    assert_equals(403, $response['status']);
    assert_true(
        isset($response['headers']['content-security-policy']),
        'The 403 page must carry a Content-Security-Policy header'
    );
});

$runner->test('The CSP is present on the 500 page', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/error');

    assert_equals(500, $response['status']);
    assert_true(
        isset($response['headers']['content-security-policy']),
        'The 500 page must carry a Content-Security-Policy header'
    );
});

$runner->test('The JSON endpoints also carry the CSP', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $token = $client->csrfToken('/auth/login');
    $client->post('/auth/login', array(
        'csrf_token' => $token,
        'email'      => 'user@greencorner.test',
        'password'   => 'User123!',
    ));

    $token = $client->csrfToken('/cart');
    $response = $client->post('/cart/summary', array('csrf_token' => $token));

    assert_true(
        isset($response['headers']['content-security-policy']),
        'The JSON endpoint must carry a Content-Security-Policy header'
    );
});

// ---------------------------------------------------------------------------
// 6. The security headers
// ---------------------------------------------------------------------------

$runner->group('6. The security headers');

$runner->test('Every response carries X-Content-Type-Options: nosniff', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->get('/');

    assert_equals('nosniff', $response['headers']['x-content-type-options'] ?? '');
});

$runner->test('Every response carries X-Frame-Options: DENY', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->get('/');

    assert_equals('DENY', $response['headers']['x-frame-options'] ?? '');
});

$runner->test('Every response carries a Referrer-Policy', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->get('/');

    assert_true(
        isset($response['headers']['referrer-policy']),
        'The response must carry a Referrer-Policy header'
    );
});

$runner->test('The security headers are present on the 404 page', function () use ($baseUrl) {
    $client = new HttpClient($baseUrl);

    $response = $client->get('/no-such-page-phase6-headers');

    assert_equals('nosniff', $response['headers']['x-content-type-options'] ?? '');
    assert_equals('DENY', $response['headers']['x-frame-options'] ?? '');
});

$runner->test('The security headers are present on the 500 page', function () use ($baseUrl) {
    $client = sign_in_as_admin(new HttpClient($baseUrl), $baseUrl);

    $response = $client->get('/_fault/error');

    assert_equals('nosniff', $response['headers']['x-content-type-options'] ?? '');
    assert_equals('DENY', $response['headers']['x-frame-options'] ?? '');
});

exit($runner->summary());
