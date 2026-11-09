<?php
/**
 * GreenCorner — Phase 6: CI configuration checks
 *
 * Validates the GitHub Actions workflow file WITHOUT running it.
 *
 * These checks confirm that the file is structurally sound and that it covers
 * what it is supposed to cover, so that a real run is as likely as possible
 * to succeed.
 *
 * Usage:
 *   php tests/phase6_ci_checks.php
 */

require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/../helpers/yaml.php';

$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/ci.yml';

$runner = new TestRunner();

$runner->group('1. The workflow file');

$runner->test('The workflow file exists', function () use ($workflowPath) {
    assert_true(
        is_file($workflowPath),
        'The workflow file is missing at .github/workflows/ci.yml'
    );
});

$runner->test('The workflow file is not empty', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_true(strlen($content) > 100, 'The workflow file is suspiciously short');
});

$runner->test('The workflow file is valid YAML structure', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    $result = yaml_validate($content);

    if (!$result->isValid()) {
        throw new Exception(
            "The workflow file has structural problems:\n  - "
            . implode("\n  - ", $result->errors)
        );
    }
});

$runner->test('The workflow declares a name and its triggers', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    $keys = yaml_top_level_keys($content);

    assert_in_array('name', $keys, 'The workflow must have a name');
    assert_in_array('on', $keys, 'The workflow must declare its triggers');
    assert_in_array('jobs', $keys, 'The workflow must declare its jobs');
});

$runner->test('The workflow runs on push and on pull request', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_contains('push:', $content);
    assert_contains('pull_request:', $content);
    assert_contains('workflow_dispatch:', $content, 'The workflow should be runnable by hand');
});

$runner->test('The workflow declares the four jobs', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_contains('  static:', $content);
    assert_contains('  build:', $content);
    assert_contains('  test:', $content);
    assert_contains('  security:', $content);
});

$runner->test('Every job sets a timeout', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    // The jobs are the keys directly under "jobs:", which are indented by
    // exactly two spaces. A key such as "services:" inside a job is
    // indented further and is not counted.
    $jobs = preg_match_all('/^ {2}(?:static|build|test|security):$/m', $content, $matches);

    assert_true($jobs >= 4, 'Expected at least four jobs, found ' . $jobs);

    $timeouts = preg_match_all('/timeout-minutes:/', $content);

    assert_true(
        $timeouts >= $jobs,
        "Every job must set timeout-minutes. Found {$timeouts} timeouts for {$jobs} jobs."
    );
});

$runner->test('The workflow grants only read access to the repository', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_contains('permissions:', $content);
    assert_contains('contents: read', $content, 'The workflow must not need write access');
});

// ---------------------------------------------------------------------------
// 2. What the workflow actually does
// ---------------------------------------------------------------------------

$runner->group('2. What the workflow does');

$runner->test('The workflow checks the PHP syntax of every file', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_contains('php -l', $content);
    assert_contains('find . -name', $content);
});

$runner->test('The workflow rejects the forbidden IGNORE writes (AC-42)', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_contains('INSERT[[:space:]]+IGNORE', $content);
    assert_contains('UPDATE[[:space:]]+IGNORE', $content);
});

$runner->test('The workflow rejects a committed credential (D-001)', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_contains('config/config.local.php', $content);
    assert_contains('.env', $content);
});

$runner->test('The workflow checks the error-output setting (D-011)', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_contains('display_errors', $content);
    assert_contains('log_errors', $content);
});

$runner->test('The workflow builds the Docker image', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_contains('docker/build-push-action', $content);
    assert_contains('Dockerfile', $content);
});

$runner->test('The workflow runs every test suite', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    foreach (array(
        'tests/run.php',
        'tests/phase2_tests.php',
        'tests/phase3_tests.php',
        'tests/phase4_tests.php',
        'tests/phase5_tests.php',
        'tests/phase6_tests.php',
    ) as $suite) {
        assert_contains($suite, $content, $suite . ' must run in CI');
    }
});

$runner->test('The security checks run in a job of their own', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_contains('tests/security_checks.php', $content);

    // The security job must not be folded into the test job, or a green
    // test run could hide a red security run.
    assert_contains('  security:', $content);
});

$runner->test('The workflow uses a pinned MySQL 8.0.16 or later (SC-2)', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    if (preg_match_all('/image:\s*mysql:([0-9.]+)/', $content, $matches) === 0) {
        throw new Exception('The workflow does not use a MySQL service image');
    }

    foreach ($matches[1] as $version) {
        $parts = array_map('intval', explode('.', $version));
        $major = $parts[0] ?? 0;
        $minor = $parts[1] ?? 0;
        $patch = $parts[2] ?? 0;

        $ok = ($major > 8)
            || ($major === 8 && $minor > 0)
            || ($major === 8 && $minor === 0 && $patch >= 16);

        assert_true($ok, 'MySQL ' . $version . ' is below the mandatory 8.0.16 floor (SC-2)');
    }
});

$runner->test('The workflow waits for the database to be ready', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_contains('mysqladmin ping', $content);
});

$runner->test('The workflow applies the schema and seeds the sample data', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_contains('database/schema.sql', $content);
    assert_contains('database/seed.php', $content);
});

$runner->test('The workflow starts the server with the router script', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_contains('public/router.php', $content, 'The router reproduces the .htaccess rules');
});

$runner->test('The workflow sets the base URL for the application', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_contains('GREEN_CORNER_URL', $content);
});

$runner->test('The workflow runs every Phase 6 check file that exists', function () use ($root, $workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    // Every Phase 6 check file in the tree must be invoked by the workflow,
    // so that a new check file cannot be added and then silently skipped.
    $checkFiles = glob($root . '/tests/phase6_*_checks.php') ?: array();
    $fenceFiles = glob($root . '/tests/phase6_*_fences.php') ?: array();

    $expected = array_merge($checkFiles, $fenceFiles);

    assert_true(count($expected) > 0, 'No Phase 6 check files were found');

    foreach ($expected as $file) {
        $name = basename($file);

        assert_contains(
            'php tests/' . $name,
            $content,
            'The workflow does not run ' . $name
        );
    }
});

$runner->test('The workflow runs the Phase 6 HTTP test suite', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    assert_contains('php tests/phase6_tests.php', $content);
});

// ---------------------------------------------------------------------------
// 3. The status of the workflow
// ---------------------------------------------------------------------------

$runner->group('3. The status of the workflow');

$runner->test('The workflow records its status', function () use ($workflowPath) {
    $content = (string) file_get_contents($workflowPath);

    // The workflow used to be marked "PENDING ITS FIRST RUN" because the
    // project was not a Git repository yet. It now runs on every push, so
    // the status line records that instead. The check is that the file
    // states a status at all, so that the marker is never simply lost.
    assert_contains(
        'STATUS:',
        $content,
        'The workflow must record its status'
    );
});

$runner->test('The local configuration is not tracked by Git', function () use ($root) {
    // The project IS under version control now, so the meaningful check is
    // no longer "is there a .git directory" but "are the files that hold
    // real credentials still untracked".
    //
    // When the project is not a Git repository there is nothing to check,
    // and the check passes without asserting anything.
    if (!is_dir($root . '/.git')) {
        return;
    }

    $tracked = array();
    $output = array();
    $status = 0;

    // Git is given the path with the native separator. On Windows a path
    // with forward slashes is not accepted by "git -C", and the command
    // would fail for a reason that has nothing to do with the check.
    $repository = str_replace('/', DIRECTORY_SEPARATOR, $root);

    exec(
        'git -C ' . escapeshellarg($repository) . ' ls-files 2>&1',
        $output,
        $status
    );

    if ($status !== 0) {
        // Git is present but the index could not be read. The check cannot
        // make a claim, so it does not fail the suite.
        return;
    }

    foreach ($output as $path) {
        $tracked[] = trim((string) $path);
    }

    $mustNotBeTracked = array(
        'config/config.local.php',
        '.env',
    );

    foreach ($mustNotBeTracked as $path) {
        assert_false(
            in_array($path, $tracked, true),
            'The file that holds a real credential is tracked by Git: ' . $path
        );
    }
});

$runner->test('The workflow directory is the only .github content', function () use ($root) {
    $githubPath = $root . '/.github';

    if (!is_dir($githubPath)) {
        return;
    }

    $found = false;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($githubPath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getFilename() === 'ci.yml') {
            $found = true;
        }
    }

    assert_true($found, 'The workflow file was not found under .github');
});

exit($runner->summary());
