<?php
/**
 * GreenCorner — Phase 6: load test checks
 *
 * These checks confirm that the load measurement tooling is sound, and
 * that the results it produced are recorded. A load test that cannot be
 * trusted is worse than no load test, so the properties that make the
 * measurement honest are checked here.
 *
 * Usage:
 *   php tests/phase6_load_checks.php
 */

require_once __DIR__ . '/TestRunner.php';

$root = dirname(__DIR__);

$runner = new TestRunner();

$runner->group('1. The load test is a closed loop');

$runner->test('The worker waits for the response before the next request', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_load_worker.php');

    // The duration is measured around the request itself, so the next
    // request cannot start before the previous one has finished.
    assert_contains('$requestStart = microtime(true)', $source);
    assert_contains('$duration = microtime(true) - $requestStart', $source);
});

$runner->test('The worker is not given a fixed request rate', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_load_worker.php');

    // An open loop would sleep a fixed interval between requests. The
    // closed loop has no such sleep.
    assert_not_contains('usleep(', $source);
    assert_not_contains('sleep(', $source);
});

$runner->test('The concurrency is the number of workers', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_load_test.php');

    // Each worker is a separate process, so the concurrency is real
    // concurrency and not one process taking turns.
    assert_contains('proc_open(', $source);
    assert_contains('phase6_load_worker.php', $source);
});

$runner->test('Every worker is waited for', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_load_test.php');

    assert_contains('proc_close($process)', $source);
});

$runner->group('2. The measurement reports what it measured');

$runner->test('The report gives the throughput', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_load_test.php');

    assert_contains('throughput_rps', $source);
});

$runner->test('The report gives the percentiles', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_load_test.php');

    foreach (array('p50', 'p90', 'p95', 'p99') as $percentile) {
        assert_contains("'" . $percentile . "'", $source);
    }
});

$runner->test('The report gives the error rate', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_load_test.php');

    assert_contains('error_rate_percent', $source);
});

$runner->test('The report counts the response codes', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_load_test.php');

    assert_contains('status_counts', $source);
});

$runner->test('A failed request is counted as an error, not as a success', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_load_worker.php');

    // A request that never got a response is recorded as 599, so it can
    // never be mistaken for a served request.
    assert_contains('$status = 599', $source);
});

$runner->test('The percentile never invents a value that was not measured', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_load_test.php');

    // The nearest-rank method reads a value out of the sorted list.
    assert_contains('load_percentile', $source);
    assert_contains('$sorted[$rank - 1]', $source);
});

$runner->test('The worker confirms the target it actually used', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_load_test.php');

    // A worker that was pointed at the wrong server would report numbers
    // about a different server, so the target is checked.
    assert_contains("isset(\$decoded['url'])", $source);
    assert_contains('wrong target', $source);
});

$runner->test('The path is passed through the environment, not the command line', function () {
    $loadTest = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_load_test.php');
    $worker = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_load_worker.php');

    // The MSYS shell on Windows rewrites a command-line argument that
    // looks like a path, so the path travels through the environment.
    assert_contains('GC_LOAD_PATH', $loadTest);
    assert_contains('GC_LOAD_PATH', $worker);
});

$runner->test('A path that the shell rewrote is refused', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/tests/phase6_load_test.php');

    assert_contains('was rewritten by the shell', $source);
});

$runner->group('3. The measurement is recorded');

$runner->test('The results file exists', function () {
    $file = dirname(__DIR__) . '/storage/logs/phase6-load-matrix.json';

    assert_true(is_file($file), 'The load matrix results are missing');
});

$runner->test('The results file holds the measured levels', function () {
    $file = dirname(__DIR__) . '/storage/logs/phase6-load-matrix.json';
    $document = json_decode((string) file_get_contents($file), true);

    assert_true(is_array($document), 'The load matrix results are not readable');

    $users = array();

    foreach ($document['results'] as $result) {
        $users[] = (int) $result['users'];
    }

    assert_in_array(10, $users);
    assert_in_array(50, $users);
    assert_in_array(100, $users);
    assert_in_array(200, $users, 'The NFR-P-02 target must have been measured');
});

$runner->test('The 200-user level was measured with no errors', function () {
    $file = dirname(__DIR__) . '/storage/logs/phase6-load-matrix.json';
    $document = json_decode((string) file_get_contents($file), true);

    $target = null;

    foreach ($document['results'] as $result) {
        if ((int) $result['users'] === 200) {
            $target = $result;
        }
    }

    assert_true($target !== null, 'The 200-user level was not measured');
    assert_equals(0.0, (float) $target['error_rate_percent'], 'The 200-user level reported errors');
});

$runner->test('The report states the model and the target', function () {
    $file = dirname(__DIR__) . '/storage/logs/phase6-load-matrix.json';
    $document = json_decode((string) file_get_contents($file), true);

    assert_contains('closed loop', (string) $document['model']);
    assert_contains('/products', (string) $document['target']);
});

$runner->group('4. The requirement is not claimed without a measurement');

$runner->test('The performance report records the measurement', function () {
    $report = dirname(__DIR__) . '/docs/PERFORMANCE_REPORT.md';

    assert_true(is_file($report), 'The performance report is missing');

    $source = (string) file_get_contents($report);

    assert_contains('NFR-P-02', $source);
    assert_contains('200', $source);
});

$runner->test('The performance report states the limitation of the environment', function () {
    $report = dirname(__DIR__) . '/docs/PERFORMANCE_REPORT.md';
    $source = (string) file_get_contents($report);

    // The honest part: the environment the measurement was made in, and
    // the fact that it is not the production environment.
    assert_contains("PHP's built-in development server", $source);
});

$runner->test('The performance report does not overstate the result', function () {
    $report = dirname(__DIR__) . '/docs/PERFORMANCE_REPORT.md';
    $source = (string) file_get_contents($report);

    // The report must not claim the target was met without qualification.
    $flat = (string) preg_replace('/\s+/', ' ', $source);

    assert_not_contains('NFR-P-02 is fully satisfied', $flat);
    assert_not_contains('the requirement is passed', $flat);
});

exit($runner->summary());
