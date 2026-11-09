<?php
/**
 * GreenCorner — Phase 4 concurrent checkout worker
 *
 * A helper process for the "two simultaneous checkouts receive different
 * order numbers" test. The test suite launches two of these at the same
 * time, so both checkouts genuinely overlap in time.
 *
 * The worker takes its parameters on the command line, so no card or session
 * data is ever written to a file:
 *
 *   php phase4_concurrent_worker.php <baseUrl> <sessionId> <csrfToken>
 *
 * It performs one Cash on Delivery checkout and prints the resulting
 * Location header on stdout, so the suite can see which order number the
 * checkout produced.
 */

$baseUrl = (string) ($argv[1] ?? '');
$sessionId = (string) ($argv[2] ?? '');
$csrfToken = (string) ($argv[3] ?? '');

if ($baseUrl === '' || $sessionId === '' || $csrfToken === '') {
    fwrite(STDERR, "Usage: php phase4_concurrent_worker.php <baseUrl> <sessionId> <csrfToken>\n");
    exit(2);
}

$payload = http_build_query(array(
    'csrf_token'       => $csrfToken,
    'payment_method'   => 'cod',
    'delivery_name'    => 'P4 Concurrent',
    'delivery_phone'   => '01700000000',
    'delivery_address' => '12 Test Garden Road',
    'delivery_city'    => 'Dhaka',
));

$context = stream_context_create(array(
    'http' => array(
        'method'          => 'POST',
        'content'         => $payload,
        'timeout'         => 30,
        'ignore_errors'   => true,
        'follow_location' => 0,
        'max_redirects'   => 0,
        'header'          => array(
            'Content-Type: application/x-www-form-urlencoded',
            // The application's session cookie is named greencorner_session.
            'Cookie: greencorner_session=' . $sessionId,
        ),
    ),
));

$body = @file_get_contents($baseUrl . '/checkout/place-order', false, $context);

$status = 0;
$location = '';

foreach (($http_response_header ?? array()) as $line) {
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
        $status = (int) $m[1];
        continue;
    }

    $parts = explode(':', $line, 2);
    if (count($parts) === 2 && strtolower(trim($parts[0])) === 'location') {
        $location = trim($parts[1]);
    }
}

// The result is printed as a single line: STATUS<TAB>LOCATION
echo $status . "\t" . $location . "\n";
