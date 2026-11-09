<?php
/**
 * GreenCorner — Minimal Test Runner
 *
 * A small, dependency-free test harness. Each test file returns an array of
 * test closures, keyed by name. The runner reports PASS / FAIL per test and
 * exits non-zero when any test fails, so the suite can be run in CI.
 */

require_once dirname(__DIR__) . '/config/bootstrap.php';

class TestRunner
{
    /** @var array<int, array{name: string, group: string, status: string, message: string}> */
    private $results = array();

    /** @var string The group currently being run. */
    private $group = 'General';

    /**
     * Start a new group of tests.
     */
    public function group(string $name): void
    {
        $this->group = $name;
        echo "\n" . $name . "\n" . str_repeat('-', strlen($name)) . "\n";
    }

    /**
     * Run a single test closure.
     */
    public function test(string $name, callable $fn): void
    {
        try {
            $fn();
            $this->results[] = array(
                'name'    => $name,
                'group'   => $this->group,
                'status'  => 'PASS',
                'message' => '',
            );
            echo "  [PASS] " . $name . "\n";
        } catch (Throwable $e) {
            $this->results[] = array(
                'name'    => $name,
                'group'   => $this->group,
                'status'  => 'FAIL',
                'message' => $e->getMessage(),
            );
            echo "  [FAIL] " . $name . "\n";
            echo "         " . $e->getMessage() . "\n";
        }
    }

    /**
     * Print the summary and return the exit code.
     */
    public function summary(): int
    {
        $passed = 0;
        $failed = 0;

        foreach ($this->results as $result) {
            if ($result['status'] === 'PASS') {
                $passed++;
            } else {
                $failed++;
            }
        }

        $total = count($this->results);

        echo "\n" . str_repeat('=', 62) . "\n";
        echo "RESULTS:  {$passed} passed, {$failed} failed, {$total} total\n";
        echo str_repeat('=', 62) . "\n";

        if ($failed > 0) {
            echo "\nFailures:\n";
            foreach ($this->results as $result) {
                if ($result['status'] === 'FAIL') {
                    echo "  - [{$result['group']}] {$result['name']}\n";
                    echo "    {$result['message']}\n";
                }
            }
        }

        return $failed > 0 ? 1 : 0;
    }
}

/**
 * Assert that a condition is true.
 */
function assert_true($condition, string $message = 'Expected true'): void
{
    if ($condition !== true) {
        throw new Exception($message);
    }
}

/**
 * Assert that a condition is false.
 */
function assert_false($condition, string $message = 'Expected false'): void
{
    if ($condition !== false) {
        throw new Exception($message);
    }
}

/**
 * Assert that two values are equal.
 */
function assert_equals($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new Exception(
            ($message !== '' ? $message . ' — ' : '')
            . 'expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

/**
 * Assert that a string contains a substring.
 */
function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (strpos($haystack, $needle) === false) {
        throw new Exception(
            ($message !== '' ? $message . ' — ' : '')
            . 'expected the output to contain "' . $needle . '"'
        );
    }
}

/**
 * Assert that a string does NOT contain a substring.
 */
function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new Exception(
            ($message !== '' ? $message . ' — ' : '')
            . 'expected the output NOT to contain "' . $needle . '"'
        );
    }
}

/**
 * Assert that an array contains a value.
 */
function assert_in_array($needle, array $haystack, string $message = ''): void
{
    if (!in_array($needle, $haystack, true)) {
        throw new Exception(
            ($message !== '' ? $message . ' — ' : '')
            . 'expected the array to contain ' . var_export($needle, true)
        );
    }
}

/**
 * Assert that a value is null.
 */
function assert_null($value, string $message = 'Expected null'): void
{
    if ($value !== null) {
        throw new Exception($message . ', got ' . var_export($value, true));
    }
}
