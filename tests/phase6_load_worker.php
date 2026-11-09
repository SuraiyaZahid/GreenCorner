<?php
/**
 * GreenCorner — Phase 6: load test worker
 *
 * One worker of the closed-loop load generator. Each worker runs a
 * number of requests against the application, in a closed loop: it issues
 * a request, waits for the response, and only then issues the next one.
 *
 * The closed loop is the honest model of a user. An open loop would
 * issue requests at a fixed rate regardless of whether the server kept
 * up, which measures the generator rather than the server.
 *
 * Every worker records the wall-clock duration of each request. The
 * durations are written to a file as JSON, one per line, and the
 * coordinator aggregates them.
 *
 * The worker takes its parameters on the command line, so no session or
 * card data is ever written to a file:
 *
 *   php phase6_load_worker.php <baseUrl> <workerId> <requests> <path>
 *
 * The target may also be given through the environment, which is how the
 * coordinator passes it. A URL in a command-line argument is rewritten by
 * the MSYS shell on Windows (a URL such as "http://127.0.0.1:8002" is
 * read as a Windows path), and the environment is not rewritten. The
 * environment wins when both are present.
 *
 * Usage:
 *   php tests/phase6_load_worker.php http://127.0.0.1:8002 0 200 /
 */

$baseUrl = (string) (getenv('GC_LOAD_URL') !== false ? getenv('GC_LOAD_URL') : ($argv[1] ?? ''));
$workerId = (int) ($argv[2] ?? 0);
$requests = (int) ($argv[3] ?? 0);
$path = (string) (getenv('GC_LOAD_PATH') !== false ? getenv('GC_LOAD_PATH') : ($argv[4] ?? '/'));

$baseUrl = rtrim($baseUrl, '/');

if ($baseUrl === '' || $requests <= 0) {
    fwrite(STDERR, "Usage: php phase6_load_worker.php <baseUrl> <workerId> <requests> <path>\n");
    exit(2);
}

// The cookie jar is per-worker, so the workers behave like different
// browsers and do not share a session. The application creates a
// session for each worker on its first request, which is realistic.
$jar = sys_get_temp_dir() . '/gc-load-cookie-' . $workerId . '-' . getmypid() . '.txt';

@unlink($jar);

$context = stream_context_create(array(
    'http' => array(
        'method'        => 'GET',
        'timeout'       => 60,
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 0,
        'header'        => array(
            'Accept: text/html,application/xhtml+xml',
            'Connection: close',
        ),
        // The cookie jar is the last option in the list.
        'cookie_file'   => $jar,
        'cookie_jar'    => $jar,
    ),
));

$url = $baseUrl . $path;
$results = array();

// The failure reason is captured, because a silent failure would make the
// whole measurement meaningless.
$failure = '';

// A warm-up request is not measured: the first request in a process pays
// for the connection setup and for the opcode cache's cold start, and
// counting that would report the cost of starting rather than the cost
// of serving.
@file_get_contents($url, false, $context);

$start = microtime(true);

for ($i = 0; $i < $requests; $i++) {
    $requestStart = microtime(true);

    $body = @file_get_contents($url, false, $context);

    $duration = microtime(true) - $requestStart;

    $status = 0;

    foreach (($http_response_header ?? array()) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) {
            $status = (int) $matches[1];
        }
    }

    if ($status === 0) {
        // The request failed outright (a connection error, or a timeout).
        $status = 599;

        if ($failure === '') {
            $last = error_get_last();
            $failure = isset($last['message']) ? (string) $last['message'] : 'unknown';
        }
    }

    $results[] = array(
        'worker'   => $workerId,
        'status'   => $status,
        'duration' => $duration,
        'bytes'    => $body === false ? 0 : strlen($body),
    );
}

$elapsed = microtime(true) - $start;

// The result is written to the file named by the fifth argument, or to
// stdout when there is none.
$output = (string) ($argv[5] ?? '');

$payload = json_encode(array(
    'worker'   => $workerId,
    'requests' => $requests,
    'elapsed'  => $elapsed,
    'url'      => $url,
    'failure'  => $failure,
    'results'  => $results,
));

if ($output === '') {
    echo $payload . "\n";
    exit(0);
}

file_put_contents($output, $payload);

@unlink($jar);

exit(0);
