<?php
/**
 * GreenCorner — Test Runner Entry Point
 *
 * Runs the Phase 1 and Phase 2 suites.
 *
 * Usage:
 *   php tests/run.php [base-url]
 *
 * The default base URL is http://127.0.0.1:8000.
 *
 * The suite drives the application over HTTP, so the application must be
 * running before the tests are executed. See tests/serve.sh.
 */

$baseUrl = $argv[1] ?? 'http://127.0.0.1:8000';

// Make the base URL available to the test files as a global.
$GLOBALS['baseUrl'] = $baseUrl;

require __DIR__ . '/phase1_tests.php';
