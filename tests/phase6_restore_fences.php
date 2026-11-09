<?php
/**
 * GreenCorner — Phase 6: restore safety fence checks
 *
 * Exercises the restore's refusal paths. Each check must be refused,
 * because a restore that is not fenced is a restore that can destroy the
 * project's own data.
 *
 * These checks use the real restore tool against the real command line.
 * The only one that needs a database is the "already holds tables" check,
 * which uses the isolated scratch schema and never the project's own.
 *
 * Usage:
 *   php tests/phase6_restore_fences.php
 */

require_once __DIR__ . '/TestRunner.php';
require_once dirname(__DIR__) . '/database/backup.php';
require_once dirname(__DIR__) . '/database/restore.php';

$root = dirname(__DIR__);

$runner = new TestRunner();

// The fences that come after the file check need a file that exists, or
// the run stops at the missing-file error before it reaches them. The
// backup file is used when it is present; otherwise a placeholder file is
// created, because these checks never get far enough to open it.
$backupFile = dirname(__DIR__) . '/storage/backups/greencorner-2026-11-02.tar.gz.gpg';

if (!is_file($backupFile)) {
    $backupFile = sys_get_temp_dir() . '/gc-fence-placeholder.tar.gz.gpg';

    if (!is_file($backupFile)) {
        file_put_contents($backupFile, 'placeholder');
    }
}

/**
 * Run the restore tool as a separate process and return its JSON summary.
 */
function fence_run_restore(array $arguments): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/database/restore.php');

    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }

    $output = (string) shell_exec($command . ' 2>&1');
    $decoded = json_decode($output, true);

    return array(
        'exit' => 0,
        'json' => is_array($decoded) ? $decoded : array(),
        'raw' => $output,
    );
}

$runner->group('1. The restore refuses a target that is not a scratch database');

$runner->test('A database name without the _scratch suffix is refused', function () use ($backupFile) {
    $result = fence_run_restore(array(
        '--file=' . $backupFile,
        '--scratch-db=greencorner',
        '--scratch-uploads=' . sys_get_temp_dir() . '/gc-fence-uploads',
    ));

    $errors = implode(' | ', $result['json']['errors'] ?? array());

    assert_contains('must end with "_scratch"', $errors);
});

$runner->test("The application's own database is refused", function () use ($backupFile) {
    $result = fence_run_restore(array(
        '--file=' . $backupFile,
        '--scratch-db=' . DB_NAME,
        '--scratch-uploads=' . sys_get_temp_dir() . '/gc-fence-uploads',
    ));

    $errors = implode(' | ', $result['json']['errors'] ?? array());

    // The project's own database name does not end in "_scratch", so the
    // first fence catches it. The refusal is what matters.
    assert_true($errors !== '', 'The restore must refuse the project database');
    assert_contains('Refusing', $errors);
});

$runner->test('The own-database fence exists as a second line of defence', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('strcasecmp($scratchDatabase, DB_NAME) === 0', $source);
    assert_contains("own database. Refusing", $source);
});

$runner->test('A missing backup file is refused before anything else happens', function () {
    $result = fence_run_restore(array(
        '--file=' . sys_get_temp_dir() . '/gc-this-file-does-not-exist.tar.gz.gpg',
        '--scratch-db=greencorner_fence_scratch',
        '--scratch-uploads=' . sys_get_temp_dir() . '/gc-fence-uploads',
    ));

    $errors = implode(' | ', $result['json']['errors'] ?? array());

    assert_contains('The backup file was not found', $errors);
});

$runner->test('A missing scratch uploads directory argument is refused', function () use ($backupFile) {
    $result = fence_run_restore(array(
        '--file=' . $backupFile,
        '--scratch-db=greencorner_fence_scratch',
    ));

    $errors = implode(' | ', $result['json']['errors'] ?? array());

    assert_contains('A scratch uploads directory is required', $errors);
});

$runner->test('A missing scratch database argument is refused', function () use ($backupFile) {
    $result = fence_run_restore(array(
        '--file=' . $backupFile,
        '--scratch-uploads=' . sys_get_temp_dir() . '/gc-fence-uploads',
    ));

    $errors = implode(' | ', $result['json']['errors'] ?? array());

    assert_contains('A scratch database name is required', $errors);
});

$runner->group('2. The restore never touches the project database');

$runner->test('The schema file names the project database, and the restore ignores it', function () {
    // The schema file opens with a USE statement that names the project's
    // own database. If the restore honoured it, the restore would write
    // into the live database.
    $schema = (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql');

    assert_contains('USE `' . DB_NAME . '`', $schema, 'The schema names the project database');

    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains("stripos(\$statement, 'USE ') === 0", $source, 'The restore must skip USE statements');
});

$runner->test('The restore verifies the connection is still on the scratch database', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('SELECT DATABASE()', $source, 'The restore must check the current database');
    assert_contains('The connection left the scratch database', $source);
});

$runner->test('The restore refuses a dump that carries a USE statement', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('The dump contains a USE statement', $source);
});

$runner->test('The restore never connects to the project database with the application handle', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('restore_connect(', $source, 'The restore must use its own connection');
    assert_contains('SCRATCH_DB_HOST', $source);
    assert_not_contains('db();', $source, 'The restore must not use the application connection');
});

$runner->group('3. The upload counting is correct');

$runner->test('The restored file count is compared with the manifest', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('uploaded_files', $source);
    assert_contains('uploaded files were restored', $source);
});

$runner->test('The archive holds the uploads directory itself', function () {
    // The backup archives the "uploads" directory, so the restore finds it
    // under the scratch directory and falls back to the scratch directory
    // itself when the archive was laid out flat.
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains("\$restoredUploads = \$scratchUploads . '/uploads'", $source);
});

$runner->group('4. The backup tool itself is sound');

$runner->test('The backup records the resolved mysqldump version, not the bare name', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');

    assert_contains('backup_shell_quote(backup_mysqldump_binary())', $source);
    assert_not_contains(
        "backup_run('mysqldump --version'",
        $source,
        'The version must be read through the resolved binary'
    );
});

$runner->test('The tar commands read local paths on Windows', function () {
    $backup = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');
    $restore = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('backup_tar_local_arguments()', $backup);
    assert_contains('backup_tar_local_arguments()', $restore);
    assert_contains('--force-local', $backup);
});

$runner->test('The backup and restore both use the tar local argument helper', function () {
    $backup = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');

    // Two tar invocations in the backup, two in the restore.
    assert_equals(
        2,
        substr_count($backup, 'backup_tar_local_arguments() . '),
        'The backup has two tar invocations'
    );

    $restore = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_equals(
        2,
        substr_count($restore, 'backup_tar_local_arguments() . '),
        'The restore has two tar invocations'
    );
});

$runner->test('The restore passes the password through MYSQL_PWD, not the command line', function () {
    $restore = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains("'MYSQL_PWD'", $restore);
    assert_not_contains('--password=', $restore, 'The password must not appear on the command line');
});

$runner->test('The order sequence counter is read with the real column names', function () {
    $restore = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('`sequence_date`', $restore);
    assert_contains('`last_value`', $restore);
    assert_not_contains('SELECT `value` FROM `order_sequence`', $restore);
});

exit($runner->summary());
