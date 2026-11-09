<?php
/**
 * GreenCorner — Phase 7.3: fault-route dependency audit
 *
 * READ-ONLY. Counts how many Phase 6 tests depend on the /_fault route,
 * which is disabled when APP_ENV is 'production'. This determines whether
 * the regression suite can be run against a production-mode container.
 *
 * Usage:
 *   php tests/phase7_fault_dependency_audit.php
 */

$source = (string) file_get_contents(__DIR__ . '/phase6_tests.php');

// Split the file into individual $runner->test(...) blocks.
$parts = preg_split('/(?=\$runner->test\()/', $source);

$total = 0;
$faultDependent = 0;
$faultNames = array();
$otherNames = array();

foreach ($parts as $part) {
    if (strpos($part, '$runner->test(') === false) {
        continue;
    }

    $total++;

    $name = '(unnamed)';
    if (preg_match('/\$runner->test\(\s*[\'"]([^\'"]+)[\'"]/', $part, $m) === 1) {
        $name = $m[1];
    }

    if (strpos($part, '_fault') !== false) {
        $faultDependent++;
        $faultNames[] = $name;
    } else {
        $otherNames[] = $name;
    }
}

echo "=== The fault-route dependency of tests/phase6_tests.php ===" . PHP_EOL;
echo PHP_EOL;
echo '  Total test blocks      : ' . $total . PHP_EOL;
echo '  Fault-dependent        : ' . $faultDependent . PHP_EOL;
echo '  Not fault-dependent    : ' . ($total - $faultDependent) . PHP_EOL;
echo PHP_EOL;

echo "=== The fault-dependent tests ===" . PHP_EOL . PHP_EOL;
foreach ($faultNames as $name) {
    echo '  - ' . $name . PHP_EOL;
}
echo PHP_EOL;

echo "=== The tests that do not depend on the fault route ===" . PHP_EOL . PHP_EOL;
foreach ($otherNames as $name) {
    echo '  - ' . $name . PHP_EOL;
}
echo PHP_EOL;

echo "=== The conclusion ===" . PHP_EOL . PHP_EOL;
echo '  The FaultInjectionController refuses to act when APP_ENV is' . PHP_EOL;
echo '  "production", answering HTTP 404. Every fault-dependent test above' . PHP_EOL;
echo '  therefore fails against a production-mode container.' . PHP_EOL;
echo PHP_EOL;
echo '  Fault-dependent tests: ' . $faultDependent . ' of ' . $total . '.';
echo PHP_EOL;
echo '  The Phase 6 suite CANNOT pass with APP_ENV=production.' . PHP_EOL;
