<?php
/**
 * GreenCorner — Restore tool
 *
 * Restores a backup taken by database/backup.php.
 *
 * Implements decision D-019.
 *
 * THE SAFETY RULES
 *
 * This tool restores into a SCRATCH instance and nothing else. A restore is
 * the most destructive operation in the project, so it is fenced in:
 *
 *   1. The target database name MUST end with '_scratch'. A restore into
 *      any other name is refused, so the project's own database can never
 *      be overwritten by accident.
 *   2. The target uploads directory MUST be a scratch directory. It must
 *      not be the application's own storage/uploads.
 *   3. The tool never writes to the application's own database. It opens a
 *      SEPARATE connection to the scratch database using the credentials
 *      given on the command line, so the application's handle is untouched.
 *   4. The scratch database must not already exist, unless --force is
 *      given. That way a restore can never silently destroy something.
 *
 * A backup that has never been restored is an untested hypothesis. This tool
 * is how the hypothesis is tested.
 *
 * Usage:
 *   php database/restore.php --file=BACKUP.tar.gz.gpg --scratch-db=NAME
 *                             --scratch-uploads=DIR [--key=PASSPHRASE]
 *                             [--force]
 *
 * Environment:
 *   BACKUP_ENCRYPTION_KEY  the GPG passphrase, when --key is not given
 */

require_once __DIR__ . '/../config/bootstrap.php';

require_once __DIR__ . '/backup.php';

/**
 * The result of the restore.
 */
class RestoreResult
{
    public $ok = false;
    public $file = '';
    public $scratchDatabase = '';
    public $scratchUploads = '';
    public $tablesRestored = array();
    public $rowCounts = array();
    public $uploadedFilesRestored = 0;
    public $checks = array();
    public $errors = array();
    public $warnings = array();
}

/**
 * Read an option from the command line.
 */
function restore_option(string $name, ?string $default = null): ?string
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
 * Whether a flag is present on the command line.
 */
function restore_flag(string $name): bool
{
    global $argv;

    return in_array('--' . $name, $argv, true);
}

/**
 * Connect to the scratch database with its own credentials.
 *
 * The application's own handle is never used, so a restore cannot disturb
 * the running application even by accident.
 */
function restore_connect(string $database): mysqli
{
    $host = getenv('SCRATCH_DB_HOST') ?: DB_HOST;
    $port = (int) (getenv('SCRATCH_DB_PORT') ?: DB_PORT);
    $user = getenv('SCRATCH_DB_USER') ?: DB_USER;
    $password = getenv('SCRATCH_DB_PASS');
    $password = $password === false ? DB_PASS : $password;

    $mysqli = @new mysqli($host, $user, $password, $database, $port);

    if ($mysqli->connect_errno) {
        throw new RuntimeException('The scratch database could not be reached: ' . $mysqli->connect_error);
    }

    if (!$mysqli->set_charset(DB_CHARSET)) {
        throw new RuntimeException('Could not set the character set on the scratch database.');
    }

    // The strict mode is verified on the scratch connection too: a restore
    // onto a server without it would silently accept rows the live
    // database rejects (R-SQL-1, R-SQL-3).
    if (!$mysqli->query("SET SESSION sql_mode = '" . DB_SESSION_SQL_MODE . "'")) {
        throw new RuntimeException('Could not set the session SQL mode on the scratch database.');
    }

    $modeResult = $mysqli->query('SELECT @@SESSION.sql_mode AS mode');

    if (!$modeResult) {
        throw new RuntimeException('Could not read the session SQL mode on the scratch database.');
    }

    $row = $modeResult->fetch_assoc();
    $modeResult->free();

    $effectiveMode = isset($row['mode']) ? $row['mode'] : '';

    if (stripos($effectiveMode, 'STRICT_TRANS_TABLES') === false) {
        throw new RuntimeException(
            'The scratch database does not report STRICT_TRANS_TABLES. Its mode is "' . $effectiveMode . '".'
        );
    }

    return $mysqli;
}

/**
 * The version of the MySQL server, for the report.
 */
function restore_server_version(mysqli $mysqli): string
{
    $result = $mysqli->query('SELECT VERSION() AS v');

    if (!$result) {
        return 'unknown';
    }

    $row = $result->fetch_assoc();
    $result->free();

    return isset($row['v']) ? (string) $row['v'] : 'unknown';
}

/**
 * Run a command and return its output.
 *
 * The extra environment variables are given to the child process, so the
 * database password travels through MYSQL_PWD and never appears on the
 * command line or in the process list (decision D-001).
 */
function restore_run(string $command, int &$exitCode, array $environment = array()): string
{
    $descriptors = array(
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    $childEnvironment = $environment;

    foreach ($environment as $name => $value) {
        putenv($name . '=' . $value);
    }

    $process = proc_open($command, $descriptors, $pipes, null, $childEnvironment + $_ENV);

    if (!is_resource($process)) {
        $exitCode = 127;

        return 'the command could not be started';
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return $stdout . $stderr;
}

/**
 * Perform the restore.
 */
function restore_run_restore(): RestoreResult
{
    $result = new RestoreResult();

    $file = restore_option('file');
    $scratchDatabase = restore_option('scratch-db');
    $scratchUploads = restore_option('scratch-uploads');
    $key = restore_option('key');
    $force = restore_flag('force');

    if ($key === null) {
        $environmentKey = getenv('BACKUP_ENCRYPTION_KEY');
        $key = $environmentKey === false ? '' : $environmentKey;
    }

    if ($file === null || !is_file($file)) {
        $result->errors[] = 'The backup file was not found: ' . (string) $file;

        return $result;
    }

    $result->file = $file;

    // ---- The safety fences ----------------------------------------------
    if ($scratchDatabase === null || $scratchDatabase === '') {
        $result->errors[] = 'A scratch database name is required (--scratch-db).';

        return $result;
    }

    if (substr($scratchDatabase, -8) !== '_scratch') {
        $result->errors[] = 'The scratch database name must end with "_scratch". '
            . 'Refusing to restore into "' . $scratchDatabase . '".';

        return $result;
    }

    if (strcasecmp($scratchDatabase, DB_NAME) === 0) {
        $result->errors[] = 'The scratch database is the application\'s own database. Refusing.';

        return $result;
    }

    if ($scratchUploads === null || $scratchUploads === '') {
        $result->errors[] = 'A scratch uploads directory is required (--scratch-uploads).';

        return $result;
    }

    $realUploads = realpath(UPLOAD_DIR);
    $realScratch = realpath($scratchUploads);

    if ($realUploads !== false && $realScratch !== false && $realScratch === $realUploads) {
        $result->errors[] = 'The scratch uploads directory is the application\'s own uploads directory. Refusing.';

        return $result;
    }

    if (strpos(str_replace('\\', '/', $scratchUploads), '/storage/uploads') !== false
        && strpos(str_replace('\\', '/', $scratchUploads), '/scratch') === false) {
        $result->errors[] = 'The scratch uploads directory must not be the application\'s own uploads directory.';

        return $result;
    }

    $result->scratchDatabase = $scratchDatabase;
    $result->scratchUploads = $scratchUploads;

    // ---- The temporary directory -----------------------------------------
    $temporary = sys_get_temp_dir() . '/greencorner-restore-' . getmypid();

    if (!@mkdir($temporary, 0700, true)) {
        $result->errors[] = 'The temporary directory could not be created: ' . $temporary;

        return $result;
    }

    $cleanup = function () use ($temporary) {
        foreach (glob($temporary . '/*') as $leftover) {
            @unlink($leftover);
        }

        @rmdir($temporary);
    };

    // ---- The decryption --------------------------------------------------
    $archiveFile = $temporary . '/backup.tar.gz';

    if (substr($file, -4) === '.gpg') {
        if ($key === '') {
            $result->errors[] = 'The backup is encrypted but no passphrase was given.';

            $cleanup();

            return $result;
        }

        if (!backup_decrypt($file, $archiveFile, $key)) {
            $result->errors[] = 'The decryption failed. The passphrase may be wrong.';

            $cleanup();

            return $result;
        }

        $result->checks[] = 'The backup was decrypted successfully.';
    } else {
        $archiveFile = $file;
        $result->checks[] = 'The backup was not encrypted.';
    }

    // ---- The extraction --------------------------------------------------
    $exitCode = 0;
    $output = restore_run(
        'tar ' . backup_tar_local_arguments() . '-xzf ' . backup_shell_quote($archiveFile)
            . ' -C ' . backup_shell_quote($temporary),
        $exitCode
    );

    if ($exitCode !== 0) {
        $result->errors[] = 'The archive could not be extracted: ' . trim($output);

        $cleanup();

        return $result;
    }

    $databaseFile = $temporary . '/database.sql';
    $uploadsFile = $temporary . '/uploads.tar.gz';
    $manifestFile = $temporary . '/manifest.json';

    foreach (array($databaseFile, $uploadsFile, $manifestFile) as $required) {
        if (!is_file($required)) {
            $result->errors[] = 'The archive is missing ' . basename($required) . '.';

            $cleanup();

            return $result;
        }
    }

    // ---- The manifest ----------------------------------------------------
    $manifest = json_decode((string) file_get_contents($manifestFile), true);

    if (!is_array($manifest)) {
        $result->errors[] = 'The manifest could not be read.';

        $cleanup();

        return $result;
    }

    $result->checks[] = 'The manifest was read. It records ' . count($manifest['table_counts'] ?? array()) . ' tables.';

    // The excluded tables must NOT be in the dump.
    $dump = (string) file_get_contents($databaseFile);

    foreach (BACKUP_EXCLUDED_TABLES as $excluded) {
        if (preg_match('/CREATE TABLE[^;]*`?' . preg_quote($excluded, '/') . '`?/i', $dump) === 1) {
            $result->errors[] = 'The dump contains the excluded table "' . $excluded . '".';

            $cleanup();

            return $result;
        }
    }

    $result->checks[] = 'The dump excludes ' . implode(' and ', BACKUP_EXCLUDED_TABLES) . ', as intended.';

    // ---- The scratch database --------------------------------------------
    try {
        $mysqli = restore_connect($scratchDatabase);
    } catch (Throwable $e) {
        $result->errors[] = $e->getMessage();

        $cleanup();

        return $result;
    }

    $result->checks[] = 'The scratch database is reachable: ' . restore_server_version($mysqli);

    // The scratch database must be empty, unless --force was given.
    $existing = $mysqli->query('SHOW TABLES');
    $tableCount = 0;

    if ($existing) {
        while ($existing->fetch_assoc() !== null) {
            $tableCount++;
        }

        $existing->free();
    }

    if ($tableCount > 0 && !$force) {
        $result->errors[] = 'The scratch database already holds ' . $tableCount
            . ' tables. Use --force to restore over it.';

        $mysqli->close();
        $cleanup();

        return $result;
    }

    if ($tableCount > 0) {
        $result->warnings[] = 'The scratch database was not empty and --force was given.';
    }

    // ---- The schema ------------------------------------------------------
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');

    // The statements are executed one at a time so that a failure names the
    // statement that failed.
    $statements = preg_split('/;\s*\n/', $schema);

    foreach ($statements as $statement) {
        $statement = trim($statement);

        if ($statement === '' || strpos($statement, '--') === 0) {
            continue;
        }

        // A USE statement would move the connection to another database,
        // and the schema file names the application's own database. The
        // restore must never follow it: the connection has to stay on the
        // scratch database for the whole run (safety rule 3).
        if (stripos($statement, 'USE ') === 0) {
            continue;
        }

        // The dump statements are removed from the schema file: the dump
        // carries its own CREATE TABLE statements.
        if (stripos($statement, 'CREATE TABLE') !== false) {
            continue;
        }

        if (stripos($statement, 'INSERT INTO') !== false) {
            continue;
        }

        if (stripos($statement, 'DROP TABLE') !== false) {
            continue;
        }

        if ($mysqli->query($statement) === false) {
            $result->errors[] = 'A schema statement failed: ' . $mysqli->error;

            $mysqli->close();
            $cleanup();

            return $result;
        }
    }

    // The fence: the connection must still be on the scratch database. A
    // schema statement that moved it would otherwise send the rest of the
    // restore into the application's own database.
    $current = $mysqli->query('SELECT DATABASE() AS `db`');
    $currentRow = $current ? $current->fetch_assoc() : null;

    if ($current) {
        $current->free();
    }

    $currentDatabase = isset($currentRow['db']) ? (string) $currentRow['db'] : '';

    if (strcasecmp($currentDatabase, $scratchDatabase) !== 0) {
        $result->errors[] = 'The connection left the scratch database during the schema step. '
            . 'It is now on "' . $currentDatabase . '". The restore was stopped.';

        $mysqli->close();
        $cleanup();

        return $result;
    }

    $result->checks[] = 'The schema was applied to the scratch database.';

    // ---- The data --------------------------------------------------------
    // The dump must not carry a USE statement, because that would move the
    // loader into another database and the load would land in the
    // application's own schema. The check happens before the load.
    if (preg_match('/^\s*USE\s+/mi', $dump) === 1) {
        $result->errors[] = 'The dump contains a USE statement. It was refused, '
            . 'because it could send the load into a database other than the scratch one.';

        $mysqli->close();
        $cleanup();

        return $result;
    }

    $exitCode = 0;
    $output = restore_run(
        backup_shell_quote(backup_mysql_binary())
        . ' --host=' . backup_shell_quote(getenv('SCRATCH_DB_HOST') ?: DB_HOST)
        . ' --port=' . (int) (getenv('SCRATCH_DB_PORT') ?: DB_PORT)
        . ' --user=' . backup_shell_quote(getenv('SCRATCH_DB_USER') ?: DB_USER)
        . ' --default-character-set=utf8mb4 '
        . backup_shell_quote($scratchDatabase) . ' < ' . backup_shell_quote($databaseFile),
        $exitCode,
        array('MYSQL_PWD' => (string) (getenv('SCRATCH_DB_PASS') ?: DB_PASS))
    );

    if ($exitCode !== 0) {
        $result->errors[] = 'The dump could not be loaded: ' . trim($output);

        $mysqli->close();
        $cleanup();

        return $result;
    }

    $result->checks[] = 'The dump was loaded into the scratch database.';

    // The fence, again: after the load the connection must still be on the
    // scratch database.
    $current = $mysqli->query('SELECT DATABASE() AS `db`');
    $currentRow = $current ? $current->fetch_assoc() : null;

    if ($current) {
        $current->free();
    }

    $currentDatabase = isset($currentRow['db']) ? (string) $currentRow['db'] : '';

    if (strcasecmp($currentDatabase, $scratchDatabase) !== 0) {
        $result->errors[] = 'The connection left the scratch database during the load. '
            . 'It is now on "' . $currentDatabase . '". The restore was stopped.';

        $mysqli->close();
        $cleanup();

        return $result;
    }

    // ---- The verification ------------------------------------------------
    foreach ($manifest['table_counts'] ?? array() as $table => $expected) {
        $count = $mysqli->query('SELECT COUNT(*) AS `c` FROM `' . $table . '`');

        if (!$count) {
            $result->warnings[] = 'The table "' . $table . '" is missing from the scratch database.';

            continue;
        }

        $row = $count->fetch_assoc();
        $count->free();

        $actual = (int) ($row['c'] ?? 0);

        $result->rowCounts[$table] = $actual;

        if ($actual !== (int) $expected) {
            $result->errors[] = 'The table "' . $table . '" holds ' . $actual
                . ' rows, but the manifest records ' . $expected . '.';
        }
    }

    if ($result->errors === array()) {
        $result->checks[] = 'Every table in the manifest holds the recorded number of rows.';
    }

    // The excluded tables must not exist in the scratch database.
    $existing = $mysqli->query('SHOW TABLES');
    $restoredTables = array();

    if ($existing) {
        while ($row = $existing->fetch_assoc()) {
            $restoredTables[] = (string) reset($row);
        }

        $existing->free();
    }

    $result->tablesRestored = $restoredTables;

    foreach (BACKUP_EXCLUDED_TABLES as $excluded) {
        if (in_array($excluded, $restoredTables, true)) {
            $result->errors[] = 'The excluded table "' . $excluded . '" exists in the scratch database.';
        }
    }

    // ---- The uploads -----------------------------------------------------
    if (!is_dir($scratchUploads) && !@mkdir($scratchUploads, 0700, true)) {
        $result->errors[] = 'The scratch uploads directory could not be created: ' . $scratchUploads;

        $mysqli->close();
        $cleanup();

        return $result;
    }

    $exitCode = 0;
    $output = restore_run(
        'tar ' . backup_tar_local_arguments() . '-xzf ' . backup_shell_quote($uploadsFile)
            . ' -C ' . backup_shell_quote($scratchUploads),
        $exitCode
    );

    if ($exitCode !== 0) {
        $result->errors[] = 'The uploads could not be extracted: ' . trim($output);

        $mysqli->close();
        $cleanup();

        return $result;
    }

    // The archive holds the "uploads" directory itself.
    $restoredUploads = $scratchUploads . '/uploads';

    if (!is_dir($restoredUploads)) {
        $restoredUploads = $scratchUploads;
    }

    $restoredFiles = glob($restoredUploads . '/*');
    $result->uploadedFilesRestored = $restoredFiles === false ? 0 : count($restoredFiles);

    $expectedFiles = (int) ($manifest['uploaded_files'] ?? 0);

    if ($result->uploadedFilesRestored !== $expectedFiles) {
        $result->errors[] = 'The restored uploads hold ' . $result->uploadedFilesRestored
            . ' files, but the manifest records ' . $expectedFiles . '.';
    } else {
        $result->checks[] = 'All ' . $expectedFiles . ' uploaded files were restored.';
    }

    // ---- The order-number counter ---------------------------------------
    // The daily counter must not be restored to a stale value, or two
    // orders could receive the same number (decision D-012).
    $counter = $mysqli->query(
        'SELECT `sequence_date`, `last_value` FROM `order_sequence` ORDER BY `sequence_date` DESC LIMIT 1'
    );

    if ($counter) {
        $row = $counter->fetch_assoc();
        $counter->free();

        $result->checks[] = 'The restored order sequence counter is '
            . (isset($row['last_value']) ? (string) $row['last_value'] : 'absent')
            . ' for ' . (isset($row['sequence_date']) ? (string) $row['sequence_date'] : 'an unknown date')
            . '. It must be advanced past the highest existing order number before this data is promoted to production.';
    }

    $mysqli->close();

    $result->ok = $result->errors === array();

    $cleanup();

    return $result;
}

// ---------------------------------------------------------------------------
// The command line
// ---------------------------------------------------------------------------

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $result = restore_run_restore();

    $summary = array(
        'ok'                        => $result->ok,
        'file'                      => $result->file,
        'scratch_database'          => $result->scratchDatabase,
        'scratch_uploads'           => $result->scratchUploads,
        'tables_restored'           => $result->tablesRestored,
        'row_counts'                => $result->rowCounts,
        'uploaded_files_restored'   => $result->uploadedFilesRestored,
        'checks'                    => $result->checks,
        'warnings'                  => $result->warnings,
        'errors'                    => $result->errors,
    );

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    exit($result->ok ? 0 : 1);
}
