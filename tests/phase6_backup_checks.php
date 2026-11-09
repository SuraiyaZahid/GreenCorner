<?php
/**
 * GreenCorner — Phase 6: backup and restore checks
 *
 * Verifies the backup and restore tooling: the policy it implements, the
 * safety fences it enforces, and the protection it applies.
 *
 * These are static and behavioural checks that run everywhere. The live
 * backup-and-restore cycle against a scratch database is a separate check
 * (tests/phase6_restore_test.php) that needs mysqldump and a MySQL server.
 *
 * Usage:
 *   php tests/phase6_backup_checks.php
 */

require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/../database/backup.php';

$root = dirname(__DIR__);

$runner = new TestRunner();

$runner->group('1. The policy');

$runner->test('The retention policy is 7 daily, 4 weekly and 12 monthly', function () {
    assert_equals(7, BACKUP_RETENTION['daily']);
    assert_equals(4, BACKUP_RETENTION['weekly']);
    assert_equals(12, BACKUP_RETENTION['monthly']);
});

$runner->test('The sessions and login_attempts tables are excluded', function () {
    assert_in_array('sessions', BACKUP_EXCLUDED_TABLES);
    assert_in_array('login_attempts', BACKUP_EXCLUDED_TABLES);
});

$runner->test('No business table is excluded', function () {
    foreach (array('users', 'products', 'orders', 'order_items', 'payments', 'cart_items') as $table) {
        assert_true(
            !in_array($table, BACKUP_EXCLUDED_TABLES, true),
            $table . ' must be included in a backup'
        );
    }
});

$runner->test('The dump uses --single-transaction (NFR-R-03)', function () {
    $command = backup_mysqldump_command('greencorner', '/tmp/out.sql');

    assert_contains('--single-transaction', $command);
    assert_contains('--quick', $command);
    assert_contains('--routines', $command);
    assert_contains('--triggers', $command);
});

$runner->test('The dump excludes the two tables', function () {
    $command = backup_mysqldump_command('greencorner', '/tmp/out.sql');

    assert_contains('--ignore-table=greencorner.sessions', $command);
    assert_contains('--ignore-table=greencorner.login_attempts', $command);
});

$runner->test('The password is never passed on the command line (D-001)', function () {
    $command = backup_mysqldump_command('greencorner', '/tmp/out.sql');

    // The structural property: mysqldump is never given a password
    // argument. This holds on any machine, with or without credentials.
    assert_not_contains('--password=', $command);

    // When this installation HAS a password, the command must not carry
    // that password either. The check is skipped when there is no local
    // configuration, because there is then no password to leak - the
    // command is built from the environment, not from a literal.
    if (defined('DB_PASS') && DB_PASS !== '') {
        assert_not_contains(DB_PASS, $command, 'The password must not appear in the command line');
    }
});

$runner->group('2. The protection of the backup');

$runner->test('The backup directory is created with mode 0700', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');

    assert_contains('0700', $source, 'The backup directory must be owner-only');
});

$runner->test('The backup file is created with mode 0600', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');

    assert_contains('chmod($finalFile, 0600)', $source, 'The backup file must be owner-only');
});

$runner->test('The backup is encrypted with GPG', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');

    assert_contains('--symmetric', $source);
    assert_contains('AES256', $source);
});

$runner->test('The encryption key is read from the environment', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');

    assert_contains('BACKUP_ENCRYPTION_KEY', $source);
});

$runner->test('The backup refuses to run without an encryption key', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');

    assert_contains(
        'The backup would be written unencrypted',
        $source,
        'The tool must refuse to write an unencrypted backup'
    );
});

$runner->test('The backup refuses to write into the web root', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');

    assert_contains('Refusing to write a backup into the web root', $source);
});

$runner->test('The default backup directory is outside the web root', function () {
    $default = APP_ROOT . '/storage/backups';

    assert_true(
        strpos(str_replace('\\', '/', $default), '/public/') === false,
        'The default backup directory must be outside public/'
    );
});

$runner->group('3. The uploads are included');

$runner->test('The backup archives the uploads directory', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');

    assert_contains('uploads.tar.gz', $source);
    assert_contains('UPLOAD_DIR', $source);
});

$runner->test('The archive holds the database, the uploads and the manifest', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');

    assert_contains('database.sql uploads.tar.gz manifest.json', $source);
});

$runner->test('The manifest records the file count and the table counts', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');

    assert_contains("'uploaded_files'", $source);
    assert_contains("'table_counts'", $source);
});

$runner->group('4. The retention tiers');

$runner->test('A backup from today is a daily backup', function () {
    $now = time();
    $stamp = date('Y-m-d', $now);

    assert_equals('daily', backup_tier($stamp, $now));
});

$runner->test('A backup from a week ago is a weekly backup', function () {
    $now = time();
    $stamp = date('Y-m-d', $now - (7 * 86400));

    assert_equals('weekly', backup_tier($stamp, $now));
});

$runner->test('A backup from a month ago is a monthly backup', function () {
    $now = time();
    $stamp = date('Y-m-d', $now - (30 * 86400));

    assert_equals('monthly', backup_tier($stamp, $now));
});

$runner->test('The retention sweep removes the oldest backups', function () {
    $directory = sys_get_temp_dir() . '/gc-retention-' . getmypid();

    @mkdir($directory, 0700, true);

    // Twelve consecutive days of backups. The tiers work out as six daily
    // (the last six days), four weekly (six to twelve days old) and no
    // monthly ones. The sweep keeps seven daily and four weekly, so ten
    // survive and two are removed.
    for ($i = 0; $i < 12; $i++) {
        $stamp = date('Y-m-d', time() - ($i * 86400));
        file_put_contents($directory . '/greencorner-' . $stamp . '.tar.gz.gpg', 'x');
    }

    $protected = array();
    $removed = backup_apply_retention($directory, $protected);

    $remaining = glob($directory . '/greencorner-*.tar.gz.gpg');
    $remaining = $remaining === false ? 0 : count($remaining);

    assert_equals(10, $remaining, 'Seven daily and four weekly backups must remain');
    assert_equals(2, count($removed), 'The two oldest backups must be removed');

    foreach (glob($directory . '/*') as $file) {
        @unlink($file);
    }

    @rmdir($directory);
});

$runner->test('The retention sweep keeps exactly seven daily backups', function () {
    $directory = sys_get_temp_dir() . '/gc-retention-daily-' . getmypid();

    @mkdir($directory, 0700, true);

    // Six days of backups: all of them are in the daily tier, and the
    // policy keeps seven, so none may be removed.
    for ($i = 0; $i < 6; $i++) {
        $stamp = date('Y-m-d', time() - ($i * 86400));
        file_put_contents($directory . '/greencorner-' . $stamp . '.tar.gz.gpg', 'x');
    }

    $removed = backup_apply_retention($directory, array());

    $remaining = glob($directory . '/greencorner-*.tar.gz.gpg');
    $remaining = $remaining === false ? 0 : count($remaining);

    assert_equals(6, $remaining, 'Six daily backups are within the policy');
    assert_equals(0, count($removed), 'Nothing may be removed while the policy is not exceeded');

    foreach (glob($directory . '/*') as $file) {
        @unlink($file);
    }

    @rmdir($directory);
});

$runner->test('The retention sweep never removes the backup just taken', function () {
    $directory = sys_get_temp_dir() . '/gc-retention-protected-' . getmypid();

    @mkdir($directory, 0700, true);

    $today = date('Y-m-d', time());
    $newest = $directory . '/greencorner-' . $today . '.tar.gz.gpg';

    for ($i = 0; $i < 12; $i++) {
        $stamp = date('Y-m-d', time() - ($i * 86400));
        file_put_contents($directory . '/greencorner-' . $stamp . '.tar.gz.gpg', 'x');
    }

    backup_apply_retention($directory, array($newest));

    assert_true(
        is_file($newest),
        'The backup that was just taken must never be removed by the sweep'
    );

    foreach (glob($directory . '/*') as $file) {
        @unlink($file);
    }

    @rmdir($directory);
});

$runner->test('The backup removes its working directory', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');

    assert_contains('backup_remove_work_directory(', $source);

    // The working directory holds the archive until the encryption has
    // consumed it, so it cannot be removed before that step.
    $encryptAt = strpos($source, "backup_encrypt(\$archiveFile");
    $removeAt = strpos($source, 'backup_remove_work_directory($workDirectory);', $encryptAt);

    assert_true(
        $removeAt !== false && $removeAt > $encryptAt,
        'The working directory must be removed after the archive has been consumed'
    );
});

$runner->test('The work directory cleaner removes every leftover file', function () {
    require_once dirname(__DIR__) . '/database/backup.php';

    $directory = sys_get_temp_dir() . '/gc-work-clean-' . getmypid();

    @mkdir($directory, 0700, true);

    file_put_contents($directory . '/database.sql', 'x');
    file_put_contents($directory . '/uploads.tar.gz', 'x');
    file_put_contents($directory . '/manifest.json', 'x');

    assert_true(backup_remove_work_directory($directory), 'The work directory must be removable');
    assert_true(!is_dir($directory), 'The work directory must be gone');
});

$runner->test('The backup records the resolved mysqldump version', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');

    assert_contains('backup_shell_quote(backup_mysqldump_binary())', $source);
    assert_not_contains("backup_run('mysqldump --version'", $source);
});

$runner->test('The tar commands read local paths on Windows', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/backup.php');

    assert_contains('backup_tar_local_arguments()', $source);
    assert_contains('--force-local', $source);
});

$runner->group('5. The restore safety fences');

$runner->test('The restore refuses a database whose name does not end in _scratch', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('must end with "_scratch"', $source);
});

$runner->test('The restore refuses the application\'s own database', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains(
        "own database. Refusing",
        $source,
        'The restore must refuse the project database'
    );
});

$runner->test('The restore refuses the application\'s own uploads directory', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('own uploads directory. Refusing', $source);
});

$runner->test('The restore refuses a non-empty scratch database without --force', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('already holds', $source);
    assert_contains('--force', $source);
});

$runner->test('The restore uses its own connection, never the application\'s', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('SCRATCH_DB_HOST', $source);
    assert_contains('SCRATCH_DB_PASS', $source);
});

$runner->test('The restore verifies the strict SQL mode on the scratch database', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('STRICT_TRANS_TABLES', $source);
    assert_contains('does not report STRICT_TRANS_TABLES', $source);
});

$runner->test('The restore checks that the excluded tables are absent', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('The dump contains the excluded table', $source);
});

$runner->test('The restore compares the row counts with the manifest', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('but the manifest records', $source);
});

$runner->test('The restore compares the uploaded file count with the manifest', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('uploaded files were restored', $source);
});

$runner->test('The restore warns about the order-number counter (D-012)', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/database/restore.php');

    assert_contains('order_sequence', $source);
    assert_contains('must be advanced past the highest existing order number', $source);
});

// ---------------------------------------------------------------------------
// 6. The end-to-end backup and restore cycle
// ---------------------------------------------------------------------------

$runner->group('6. The end-to-end backup and restore cycle');

$runner->test('The cycle script exists', function () use ($root) {
    assert_true(
        is_file($root . '/tests/phase6_backup_cycle.sh'),
        'The backup and restore cycle script is missing'
    );
});

$runner->test('The cycle script only ever targets a scratch database', function () use ($root) {
    $source = (string) file_get_contents($root . '/tests/phase6_backup_cycle.sh');

    // The scratch name must carry the "_scratch" suffix, because that is
    // the fence the restore tool and the scratch setup script both apply.
    assert_contains('_scratch', $source);

    // The project's own database name must never appear as a restore target.
    assert_not_contains('--scratch-db="greencorner"', $source);
    assert_not_contains("--scratch-db=greencorner'", $source);
});

$runner->test('The cycle script removes every scratch artefact', function () use ($root) {
    $source = (string) file_get_contents($root . '/tests/phase6_backup_cycle.sh');

    // The scratch database is dropped...
    assert_contains('--action=drop', $source);

    // ...and the isolated storage directory and the work directories are
    // removed, so a cycle leaves nothing behind.
    assert_contains('storage/scratch', $source);
    assert_contains('.work-', $source);
});

$runner->test('The cycle script never writes a backup into the web root', function () use ($root) {
    $source = (string) file_get_contents($root . '/tests/phase6_backup_cycle.sh');

    // A path inside the document root would be a path into public/. The
    // word may appear in a comment explaining the refusal, but never as
    // part of a path the script uses.
    if (preg_match('#[\'"/]public/#', $source) === 1) {
        throw new Exception('The cycle script uses a path inside the web root');
    }
});

$runner->test('The cycle script never prints the encryption key', function () use ($root) {
    $source = (string) file_get_contents($root . '/tests/phase6_backup_cycle.sh');

    // The key may be passed to the tools, but it must never be echoed.
    if (preg_match('/echo[^\n]*ENCRYPTION_KEY/', $source) === 1) {
        throw new Exception('The cycle script prints the backup encryption key');
    }
});

exit($runner->summary());
