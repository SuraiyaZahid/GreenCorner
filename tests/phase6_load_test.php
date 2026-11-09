<?php
/**
 * GreenCorner — Phase 6: closed-loop load test
 *
 * Measures the application's behaviour under concurrent load, and reports
 * the result honestly.
 *
 * WHAT IT MEASURES
 *
 *   - The number of requests completed (throughput).
 *   - The wall-clock time of each request, and the 50th, 90th, 95th and
 *     99th percentiles of it.
 *   - The error rate: any response outside 2xx and 3xx is an error.
 *   - The concurrent users: the number of workers that ran at the same
 *     time.
 *
 * THE MODEL
 *
 * Closed loop. Each worker issues a request, waits for the response, and
 * only then issues the next one. This models a user, who cannot click
 * again until the page has arrived. The concurrency is therefore the
 * number of workers, and the throughput falls as the response time
 * rises — which is exactly how a real site behaves when it is
 * overloaded.
 *
 * WHAT IT DOES NOT DO
 *
 * It does not decide whether the requirement is met. It reports the
 * measurement, and the report states plainly whether the target was
 * reached.
 *
 * Usage:
 *   php tests/phase6_load_test.php --url=http://127.0.0.1:8002
 *                                  [--users=50] [--requests=100] [--path=/]
 *                                  [--label=browse]
 */

require_once __DIR__ . '/../config/bootstrap.php';

/**
 * Read an option from the command line.
 */
function load_option(string $name, ?string $default = null): ?string
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

/**
 * The number of logical processors, if it can be established.
 */
function load_logical_processors(): string
{
    if (is_readable('/proc/cpuinfo')) {
        $count = preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo'));

        if ($count > 0) {
            return (string) $count;
        }
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        $output = (string) shell_exec(
            'wmic cpu get NumberOfLogicalProcessors /value 2>nul'
        );

        if (preg_match('/NumberOfLogicalProcessors=(\d+)/', $output, $matches)) {
            return $matches[1];
        }
    }

    return 'unknown';
}

/**
 * The percentile of a sorted list of values.
 *
 * The nearest-rank method: the value at the ceiling of the requested
 * fraction of the list. It never invents a value that was not measured.
 */
function load_percentile(array $sorted, float $fraction): float
{
    $count = count($sorted);

    if ($count === 0) {
        return 0.0;
    }

    $rank = (int) ceil($fraction * $count);
    $rank = max(1, min($count, $rank));

    return (float) $sorted[$rank - 1];
}

$url = load_option('url', 'http://127.0.0.1:8002');
$users = (int) (load_option('users', '50'));
$requests = (int) (load_option('requests', '100'));
$path = load_option('path', '/');
$label = load_option('label', 'browse');

// The path may also come through the environment, which is how the
// matrix passes it: the MSYS shell on Windows rewrites an argument that
// looks like a path, so "--path=/products" would arrive as a Windows
// directory name.
if (getenv('GC_LOAD_PATH') !== false && getenv('GC_LOAD_PATH') !== '') {
    $path = (string) getenv('GC_LOAD_PATH');
}

// The MSYS shell on Windows rewrites an argument that looks like a path,
// so "--path=/" arrives as "--path=C:/Program Files/Git/". A path that
// names a Windows directory is not a URL path, and it is rejected here
// rather than silently turning into a request for the wrong resource.
if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
    fwrite(STDERR, 'The path "' . $path . '" was rewritten by the shell. '
        . 'Pass the path with MSYS_NO_PATHCONV=1 set, or use a path that '
        . "does not begin with a drive letter.\n");
    exit(2);
}

if ($path === '' || $path[0] !== '/') {
    $path = '/' . ltrim($path, '/');
}

if ($users < 1 || $requests < 1) {
    fwrite(STDERR, "The user count and the request count must both be at least one.\n");
    exit(2);
}

$workerScript = __DIR__ . '/phase6_load_worker.php';
$phpBinary = PHP_BINARY;

echo "=== The closed-loop load test ===\n\n";
echo 'Target:        ' . $url . $path . "\n";
echo 'Raw argv:      ' . implode(' | ', array_slice($argv, 1)) . "\n";
echo 'Scenario:      ' . $label . "\n";
echo 'Concurrent users: ' . $users . "\n";
echo 'Requests each:    ' . $requests . "\n";
echo 'Total requests:   ' . ($users * $requests) . "\n\n";

// ---- The server must be up ------------------------------------------------
$probe = @file_get_contents($url . '/', false, stream_context_create(array(
    'http' => array('timeout' => 10, 'ignore_errors' => true),
)));

if ($probe === false) {
    echo "The server at " . $url . " did not answer. The test cannot run.\n";
    exit(1);
}

// ---- The workers ---------------------------------------------------------
$outputDirectory = sys_get_temp_dir() . '/gc-load-' . getmypid();

@mkdir($outputDirectory, 0700, true);

$processes = array();
$outputFiles = array();

echo "Starting " . $users . " workers...\n";

for ($workerId = 0; $workerId < $users; $workerId++) {
    $outputFile = $outputDirectory . '/worker-' . $workerId . '.json';
    $outputFiles[$workerId] = $outputFile;

    $command = escapeshellarg($phpBinary) . ' '
        . escapeshellarg($workerScript) . ' '
        . escapeshellarg($url) . ' '
        . $workerId . ' '
        . $requests . ' '
        . escapeshellarg($path) . ' '
        . escapeshellarg($outputFile);

    $descriptors = array(
        0 => array('file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'r'),
        1 => array('file', $outputDirectory . '/stdout-' . $workerId . '.log', 'a'),
        2 => array('file', $outputDirectory . '/stderr-' . $workerId . '.log', 'a'),
    );

    // The environment is given to the child explicitly. The target URL
    // travels through the environment rather than the command line: the
    // MSYS shell on Windows rewrites a URL that appears in an argument
    // (it reads "http://127.0.0.1:8002" as a Windows path), and the
    // environment is not rewritten.
    $environment = $_ENV;

    $environment['MSYS_NO_PATHCONV'] = '1';
    $environment['MSYS2_ARG_CONV_EXCL'] = '*';
    $environment['GC_LOAD_URL'] = $url;
    $environment['GC_LOAD_PATH'] = $path;

    $process = proc_open($command, $descriptors, $pipes, null, $environment);

    if (is_resource($process)) {
        $processes[$workerId] = $process;
    }
}

// The wall-clock time of the whole run, which is the time in which all
// the workers were working at the same time.
$runStart = microtime(true);

// ---- The wait ------------------------------------------------------------
echo "Waiting for the workers to finish...\n";

// The workers are waited for one at a time, with proc_close() blocking
// until each one has finished. Polling proc_get_status() instead would
// race: the status is read once and the process handle is closed
// separately, so a worker that finished between the two calls would be
// lost.
foreach ($processes as $workerId => $process) {
    proc_close($process);
}

$processes = array();

$runElapsed = microtime(true) - $runStart;

// ---- The aggregation -----------------------------------------------------
$durations = array();
$statuses = array();
$totalBytes = 0;
$workerCount = 0;
$failedWorkers = array();

foreach ($outputFiles as $workerId => $outputFile) {
    if (!is_file($outputFile)) {
        $failedWorkers[] = $workerId;
        continue;
    }

    $decoded = json_decode((string) file_get_contents($outputFile), true);

    if (!is_array($decoded) || !isset($decoded['results'])) {
        $failedWorkers[] = $workerId;
        continue;
    }

    $workerCount++;

    // The worker confirms the target it actually used. If a worker was
    // pointed somewhere else, its numbers would be about a different
    // server, and the whole measurement would be meaningless.
    if (isset($decoded['url']) && $decoded['url'] !== $url . $path) {
        $failedWorkers[] = $workerId . ' (wrong target: ' . $decoded['url'] . ')';
        continue;
    }

    if (isset($decoded['failure']) && $decoded['failure'] !== '') {
        $failedWorkers[] = $workerId . ' (' . $decoded['failure'] . ')';
    }

    foreach ($decoded['results'] as $result) {
        $durations[] = (float) $result['duration'];
        $statuses[] = (int) $result['status'];
        $totalBytes += (int) $result['bytes'];
    }
}

foreach (glob($outputDirectory . '/*') as $leftover) {
    @unlink($leftover);
}

@rmdir($outputDirectory);

sort($durations, SORT_NUMERIC);

$totalRequests = count($durations);
$errors = 0;
$statusCounts = array();

foreach ($statuses as $status) {
    $key = (string) $status;

    if (!isset($statusCounts[$key])) {
        $statusCounts[$key] = 0;
    }

    $statusCounts[$key]++;

    if ($status < 200 || $status >= 400) {
        $errors++;
    }
}

$errorRate = $totalRequests > 0 ? ($errors / $totalRequests) * 100 : 0.0;
$throughput = $runElapsed > 0 ? $totalRequests / $runElapsed : 0.0;

echo "\n=== The result ===\n\n";
printf("  Workers that completed:      %d of %d\n", $workerCount, $users);
printf("  Requests completed:           %d\n", $totalRequests);
printf("  Wall-clock time of the run:   %.3f s\n", $runElapsed);
printf("  Throughput:                   %.2f requests/second\n", $throughput);
printf("  Bytes transferred:            %d\n", $totalBytes);
printf("  Errors (outside 2xx/3xx):     %d (%.2f%%)\n", $errors, $errorRate);

echo "\n  Response times (milliseconds):\n";

if ($totalRequests > 0) {
    printf("    min:    %7.2f\n", $durations[0] * 1000);
    printf("    p50:    %7.2f\n", load_percentile($durations, 0.50) * 1000);
    printf("    p90:    %7.2f\n", load_percentile($durations, 0.90) * 1000);
    printf("    p95:    %7.2f\n", load_percentile($durations, 0.95) * 1000);
    printf("    p99:    %7.2f\n", load_percentile($durations, 0.99) * 1000);
    printf("    max:    %7.2f\n", $durations[$totalRequests - 1] * 1000);
    printf("    mean:   %7.2f\n", (array_sum($durations) / $totalRequests) * 1000);
} else {
    echo "    no requests were measured\n";
}

echo "\n  The response codes:\n";

ksort($statusCounts);

foreach ($statusCounts as $status => $count) {
    printf("    %s: %d\n", (string) $status, $count);
}

if ($failedWorkers !== array()) {
    echo "\n  Workers that produced no result: " . implode(', ', $failedWorkers) . "\n";
}

// ---- The machine ---------------------------------------------------------
echo "\n=== The machine ===\n\n";

$load = @file_get_contents('/proc/loadavg');

if ($load !== false) {
    echo '  Load average: ' . trim($load) . "\n";
}

echo '  Logical processors: ' . load_logical_processors() . "\n";

$memory = @file_get_contents('/proc/meminfo');

if ($memory !== false) {
    if (preg_match('/MemTotal:\s+(\d+) kB/', $memory, $matches)) {
        printf("  Memory total: %.1f GB\n", (int) $matches[1] / (1024 * 1024));
    }

    if (preg_match('/MemAvailable:\s+(\d+) kB/', $memory, $matches)) {
        printf("  Memory available: %.1f GB\n", (int) $matches[1] / (1024 * 1024));
    }
}

// ---- The result as JSON --------------------------------------------------
$summary = array(
    'label'             => $label,
    'url'               => $url . $path,
    'users'             => $users,
    'requests_per_user' => $requests,
    'total_requests'    => $totalRequests,
    'elapsed_seconds'   => round($runElapsed, 3),
    'throughput_rps'    => round($throughput, 2),
    'error_rate_percent' => round($errorRate, 3),
    'errors'            => $errors,
    'status_counts'     => $statusCounts,
    'latency_ms'        => array(
        'min'  => round($durations[0] * 1000, 2),
        'p50'  => round(load_percentile($durations, 0.50) * 1000, 2),
        'p90'  => round(load_percentile($durations, 0.90) * 1000, 2),
        'p95'  => round(load_percentile($durations, 0.95) * 1000, 2),
        'p99'  => round(load_percentile($durations, 0.99) * 1000, 2),
        'max'  => round($durations[$totalRequests - 1] * 1000, 2),
        'mean' => round((array_sum($durations) / max(1, $totalRequests)) * 1000, 2),
    ),
);

echo "\n=== The summary as JSON ===\n\n";
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

exit(0);
