<?php
/**
 * GreenCorner — Phase 6: the load measurement matrix
 *
 * Runs the load test across a range of concurrency levels and writes the
 * results to a file. The matrix is the measurement: it shows where the
 * server's capacity is, by watching the throughput stop rising and the
 * latency start climbing.
 *
 * The runs are separate processes, so each one starts from a clean
 * worker set and the numbers are not contaminated by a previous run's
 * leftover processes.
 *
 * Usage:
 *   php tests/phase6_load_matrix.php --url=http://127.0.0.1:8002
 *                                    [--path=/products] [--output=FILE]
 */

require_once dirname(__DIR__) . '/config/bootstrap.php';

/**
 * Read an option from the command line.
 */
function matrix_option(string $name, ?string $default = null): ?string
{
    global $argv;

    $prefix = '--' . $name . '=';

    foreach ($argv as $argument) {
        if (strpos($argument, $prefix) === 0) {
            return substr($argument, strlen($prefix));
        }
    }

    return $default;
}

$url = rtrim((string) matrix_option('url', 'http://127.0.0.1:8002'), '/');
$path = (string) matrix_option('path', '/products');
$output = (string) matrix_option('output', dirname(__DIR__) . '/storage/logs/phase6-load-matrix.json');

// The path may also be given through the environment, which is how it is
// passed on Windows: the MSYS shell rewrites an argument that looks like
// a path, so "--path=/products" would arrive as a Windows directory.
if (getenv('GC_LOAD_PATH') !== false && getenv('GC_LOAD_PATH') !== '') {
    $path = (string) getenv('GC_LOAD_PATH');
}

if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
    fwrite(STDERR, 'The path "' . $path . '" was rewritten by the shell. '
        . "Set GC_LOAD_PATH instead of using --path=.\n");
    exit(2);
}

$loadTest = __DIR__ . '/phase6_load_test.php';

// Each level is given enough requests that the measurement lasts long
// enough to be meaningful. The request count per user falls as the
// concurrency rises, so that every level takes roughly the same time and
// no level dominates the whole run.
$levels = array(
    array('users' => 10, 'requests' => 30),
    array('users' => 25, 'requests' => 20),
    array('users' => 50, 'requests' => 15),
    array('users' => 100, 'requests' => 10),
    array('users' => 200, 'requests' => 5),
    array('users' => 300, 'requests' => 3),
);

echo "=== The load measurement matrix ===\n\n";
echo 'Target: ' . $url . $path . "\n\n";

$results = array();

foreach ($levels as $level) {
    $users = $level['users'];
    $requests = $level['requests'];

    echo '--- ' . $users . ' concurrent users, ' . $requests . " requests each ---\n";

    $command = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg($loadTest) . ' '
        . escapeshellarg('--url=' . $url) . ' '
        . escapeshellarg('--users=' . $users) . ' '
        . escapeshellarg('--requests=' . $requests) . ' '
        . escapeshellarg('--label=matrix-' . $users);

    $environment = $_ENV;

    $environment['MSYS_NO_PATHCONV'] = '1';
    $environment['MSYS2_ARG_CONV_EXCL'] = '*';

    // The path travels through the environment: the MSYS shell rewrites
    // an argument that looks like a path, and the environment is not
    // rewritten.
    $environment['GC_LOAD_PATH'] = $path;

    $descriptors = array(
        0 => array('file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    $process = proc_open($command, $descriptors, $pipes, null, $environment);

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    proc_close($process);

    // The summary is the last JSON object the load test printed.
    $position = strrpos($stdout, '{');

    if ($position === false) {
        echo '  The run produced no result.' . "\n";
        echo '  ' . trim($stderr) . "\n";

        $results[] = array('users' => $users, 'requests' => $requests, 'error' => 'no result');

        continue;
    }

    // The JSON is the tail of the output; it is decoded by finding the
    // matching brace from the last opening one.
    $json = substr($stdout, $position);
    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        // The output holds the report and then the JSON. The report is
        // discarded and the JSON is taken from the final brace.
        $start = strpos($stdout, "{\n    \"label\"");

        if ($start !== false) {
            $decoded = json_decode(substr($stdout, $start), true);
        }
    }

    if (!is_array($decoded)) {
        echo '  The result could not be read.' . "\n";

        $results[] = array('users' => $users, 'requests' => $requests, 'error' => 'unreadable result');

        continue;
    }

    printf(
        "  %d requests, %.2f rps, p50 %.0f ms, p95 %.0f ms, p99 %.0f ms, errors %.2f%%\n",
        $decoded['total_requests'],
        $decoded['throughput_rps'],
        $decoded['latency_ms']['p50'],
        $decoded['latency_ms']['p95'],
        $decoded['latency_ms']['p99'],
        $decoded['error_rate_percent']
    );

    $results[] = array(
        'users'             => $users,
        'requests_per_user' => $requests,
        'total_requests'    => $decoded['total_requests'],
        'elapsed_seconds'   => $decoded['elapsed_seconds'],
        'throughput_rps'    => $decoded['throughput_rps'],
        'error_rate_percent' => $decoded['error_rate_percent'],
        'status_counts'     => $decoded['status_counts'],
        'latency_ms'        => $decoded['latency_ms'],
    );

    // A pause between the levels, so that the previous run's sockets are
    // closed and the server's own state settles before the next one.
    sleep(3);
}

// ---- The summary ---------------------------------------------------------
echo "\n=== The matrix ===\n\n";
printf("%8s %10s %10s %10s %10s %10s\n", 'Users', 'Requests', 'rps', 'p50 ms', 'p95 ms', 'Errors %');

foreach ($results as $result) {
    if (isset($result['error'])) {
        printf("%8d %10s\n", $result['users'], 'failed');

        continue;
    }

    printf(
        "%8d %10d %10.2f %10.0f %10.0f %10.2f\n",
        $result['users'],
        $result['total_requests'],
        $result['throughput_rps'],
        $result['latency_ms']['p50'],
        $result['latency_ms']['p95'],
        $result['error_rate_percent']
    );
}

// The peak throughput is the level at which the server served the most
// requests per second. That is the capacity of this machine.
$peak = null;

foreach ($results as $result) {
    if (isset($result['error'])) {
        continue;
    }

    if ($peak === null || $result['throughput_rps'] > $peak['throughput_rps']) {
        $peak = $result;
    }
}

if ($peak !== null) {
    echo "\n  The peak throughput was " . $peak['throughput_rps'] . " requests/second at "
        . $peak['users'] . " concurrent users.\n";
}

// ---- The file ------------------------------------------------------------
$document = array(
    'measured_at' => date('c'),
    'target'      => $url . $path,
    'model'       => 'closed loop: each worker issues a request, waits for the response, then issues the next',
    'results'     => $results,
);

@mkdir(dirname($output), 0700, true);

file_put_contents($output, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\n  The results were written to " . $output . "\n";

exit(0);
