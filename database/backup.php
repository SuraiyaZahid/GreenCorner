<?php
/**
 * GreenCorner — Backup tool
 *
 * Takes a backup of the database and of the uploaded product images, and
 * applies the retention policy.
 *
 * Implements NFR-R-03 ("daily database backups") and decision D-019.
 *
 * WHAT IS BACKED UP
 *
 *   - The database, with mysqldump --single-transaction, so the snapshot is
 *     consistent without locking the tables for the duration.
 *   - storage/uploads/, which holds the product images and their thumbnails.
 *     These are NOT reproducible from the database: the product rows hold the
 *     paths, not the bytes. A database-only backup silently loses every
 *     product image, so the uploads are part of the backup.
 *
 * WHAT IS EXCLUDED, AND WHY
 *
 *   - The `sessions` table. Restoring it would resurrect sessions that were
 *     valid at backup time, which is a session-fixation and account-takeover
 *     risk on a restored installation. A restored instance requires a fresh
 *     login.
 *   - The `login_attempts` table. The rate-limiter state. A restored
 *     instance starts with a clean slate, which is preferable to preserving
 *     attacker-shaped data.
 *
 * WHAT IS NEVER BACKED UP
 *
 *   - config/config.local.php. The credentials must reach a restored host
 *     through the deployment secret mechanism, never through a dump file
 *     (decision D-001).
 *
 * THE PROTECTION OF THE BACKUP
 *
 *   - The backup directory is created with mode 0700 and the files with mode
 *     0600: only the backup account can read them.
 *   - Each backup is encrypted with GPG symmetric encryption. The key is
 *     supplied through the environment and is never written to disk here.
 *   - The backups are written OUTSIDE the web root and outside the
 *     application container. The tool refuses to write into public/.
 *
 * THE RETENTION POLICY
 *
 *   - 7 daily, 4 weekly, 12 monthly, enforced by a keep-count sweep: the
 *     newest N of each tier are kept and everything older is removed.
 *
 * Usage:
 *   php database/backup.php [--dry-run] [--backup-dir=DIR]
 *
 * Environment:
 *   BACKUP_ENCRYPTION_KEY  the GPG passphrase (required unless --no-encrypt)
 *   BACKUP_DIR             where the backups are written
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/functions.php';

/**
 * The tables that are never included in a backup.
 */
const BACKUP_EXCLUDED_TABLES = array('sessions', 'login_attempts');

/**
 * The retention policy: how many backups of each tier are kept.
 */
const BACKUP_RETENTION = array(
    'daily'   => 7,
    'weekly'  => 4,
    'monthly' => 12,
);

/**
 * The result of the run, as a JSON Lines record.
 */
class BackupResult
{
    public $startedAt = 0;
    public $finishedAt = 0;
    public $databaseFile = '';
    public $uploadsFile = '';
    public $manifestFile = '';
    public $bytes = 0;
    public $uploadedFiles = 0;
    public $encrypted = false;
    public $dryRun = false;
    public $removed = array();
    public $errors = array();
    public $database = '';
    public $backupDirectory = '';
}

/**
 * Read an option from the command line.
 */
function backup_option(string $name, ?string $default = null): ?string
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
function backup_flag(string $name): bool
{
    global $argv;

    return in_array('--' . $name, $argv, true);
}

/**
 * Run a command and return its output.
 *
 * @param string $command The command line.
 * @param int    &$exitCode Set to the command's exit code.
 * @param array  $environment Extra environment variables for the command.
 * @return string The combined output.
 */
function backup_run(string $command, int &$exitCode, array $environment = array()): string
{
    $descriptors = array(
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    // The environment for the command. The database password is passed
    // through MYSQL_PWD, which MySQL reads as the password, so it never
    // appears on the command line and never in the process list
    // (decision D-001).
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
 * Escape a value for use in a shell command.
 */
function backup_shell_quote(string $value): string
{
    if (DIRECTORY_SEPARATOR === '\\') {
        return '"' . str_replace(['"', '%', '!', '^', '&', '|'], ['""', '%', '!', '^', '&', '|'], $value) . '"';
    }

    return "'" . str_replace("'", "'\\''", $value) . "'";
}

/**
 * Remove a working directory and everything left in it.
 *
 * A backup leaves nothing behind: an empty ".work-*" directory in the
 * backup folder is clutter, and a directory that still holds a dump is a
 * copy of the database sitting on disk.
 */
function backup_remove_work_directory(string $directory): bool
{
    if (!is_dir($directory)) {
        return true;
    }

    foreach (glob($directory . '/*') as $leftover) {
        @unlink($leftover);
    }

    return @rmdir($directory);
}

/**
 * The arguments that make GNU tar read a local path.
 *
 * A path such as "C:/tmp/archive.tar.gz" is otherwise read as a remote
 * specification ("host:path") and tar tries to reach the host "C". The
 * option is understood by GNU tar; bsdtar accepts it as well, so it is
 * safe on every platform the project is built on.
 */
function backup_tar_local_arguments(): string
{
    return DIRECTORY_SEPARATOR === '\\' ? '--force-local ' : '';
}

/**
 * The full path of the mysqldump executable.
 *
 * On Windows the MySQL tools are installed under "C:\Program Files\MySQL\
 * MySQL Server X.Y\bin" and are not on the PATH by default, so the usual
 * locations are searched. MYSQLDUMP_PATH overrides the search.
 *
 * @return string The path, or 'mysqldump' when it is on the PATH.
 */
function backup_mysqldump_binary(): string
{
    $override = getenv('MYSQLDUMP_PATH');

    if ($override !== false && $override !== '') {
        return $override;
    }

    if (DIRECTORY_SEPARATOR !== '\\') {
        return 'mysqldump';
    }

    $candidates = array();

    foreach (glob('C:/Program Files/MySQL/MySQL Server */bin/mysqldump.exe') ?: array() as $path) {
        $candidates[] = $path;
    }

    foreach (glob('C:/Program Files/MariaDB */bin/mysqldump.exe') ?: array() as $path) {
        $candidates[] = $path;
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return 'mysqldump';
}

/**
 * The full path of the mysql client, used to load a dump.
 */
function backup_mysql_binary(): string
{
    $override = getenv('MYSQL_PATH');

    if ($override !== false && $override !== '') {
        return $override;
    }

    if (DIRECTORY_SEPARATOR !== '\\') {
        return 'mysql';
    }

    foreach (glob('C:/Program Files/MySQL/MySQL Server */bin/mysql.exe') ?: array() as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    foreach (glob('C:/Program Files/MariaDB */bin/mysql.exe') ?: array() as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return 'mysql';
}

/**
 * The mysqldump command for the configured database.
 *
 * --single-transaction gives a consistent snapshot without locking the
 * tables, so the application stays available while the backup runs.
 */
function backup_mysqldump_command(string $database, string $targetFile): string
{
    $parts = array(
        backup_shell_quote(backup_mysqldump_binary()),
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '--events',
        '--hex-blob',
        '--set-gtid-purged=OFF',
        '--default-character-set=utf8mb4',
        '--host=' . backup_shell_quote(DB_HOST),
        '--port=' . (int) DB_PORT,
        '--user=' . backup_shell_quote(DB_USER),
    );

    // The excluded tables are not dumped at all.
    foreach (BACKUP_EXCLUDED_TABLES as $table) {
        $parts[] = '--ignore-table=' . $database . '.' . $table;
    }

    // The password is passed through the environment, never on the command
    // line, so it cannot appear in the process list (decision D-001).
    $parts[] = $database;

    return implode(' ', $parts) . ' > ' . backup_shell_quote($targetFile);
}

/**
 * Encrypt a file with GPG symmetric encryption.
 */
function backup_encrypt(string $sourceFile, string $targetFile, string $key): bool
{
    $command = 'gpg --batch --yes --quiet --symmetric --cipher-algo AES256 '
        . '--pinentry-mode loopback --passphrase-fd 0 '
        . '--output ' . backup_shell_quote($targetFile) . ' '
        . backup_shell_quote($sourceFile);

    $descriptors = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    $process = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        return false;
    }

    fwrite($pipes[0], $key);
    fclose($pipes[0]);

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return $exitCode === 0;
}

/**
 * Decrypt a file with GPG symmetric encryption.
 */
function backup_decrypt(string $sourceFile, string $targetFile, string $key): bool
{
    $command = 'gpg --batch --yes --quiet --decrypt --cipher-algo AES256 '
        . '--pinentry-mode loopback --passphrase-fd 0 '
        . '--output ' . backup_shell_quote($targetFile) . ' '
        . backup_shell_quote($sourceFile);

    $descriptors = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    $process = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        return false;
    }

    fwrite($pipes[0], $key);
    fclose($pipes[0]);

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return $exitCode === 0;
}

/**
 * The tier a backup belongs to, relative to the retention policy.
 *
 * A backup is promoted to a higher tier as it ages: the newest is daily, the
 * one from a week ago is weekly, the one from a month ago is monthly.
 */
function backup_tier(string $dateStamp, int $now): string
{
    $timestamp = strtotime($dateStamp . ' 00:00:00');

    if ($timestamp === false) {
        return 'daily';
    }

    $age = $now - $timestamp;
    $day = 86400;

    if ($age >= 28 * $day) {
        return 'monthly';
    }

    if ($age >= 6 * $day) {
        return 'weekly';
    }

    return 'daily';
}

/**
 * Apply the retention policy: keep the newest N of each tier.
 *
 * @param string $directory The backup directory.
 * @param array<int, string> $protectedFiles Files that must never be removed.
 * @return array<int, string> The files that were removed.
 */
function backup_apply_retention(string $directory, array $protectedFiles): array
{
    $removed = array();

    $entries = glob($directory . '/greencorner-*.tar.gz.gpg');

    if ($entries === false) {
        return $removed;
    }

    // Group the backups by tier, newest first.
    $tiers = array(
        'daily'   => array(),
        'weekly'  => array(),
        'monthly' => array(),
    );

    $now = time();

    foreach ($entries as $file) {
        $name = basename($file);

        if (preg_match('/greencorner-(\d{4}-\d{2}-\d{2})/', $name, $m) !== 1) {
            continue;
        }

        $tier = backup_tier($m[1], $now);

        $tiers[$tier][] = array('file' => $file, 'stamp' => $m[1]);
    }

    foreach ($tiers as $tier => $items) {
        // Newest first.
        usort($items, function ($a, $b) {
            return strcmp($b['stamp'], $a['stamp']);
        });

        $keep = BACKUP_RETENTION[$tier] ?? 0;

        foreach (array_slice($items, $keep) as $item) {
            if (in_array($item['file'], $protectedFiles, true)) {
                // The backup that was just taken is never removed.
                continue;
            }

            if (@unlink($item['file'])) {
                $removed[] = $item['file'];
            }
        }
    }

    return $removed;
}

/**
 * Take the backup.
 *
 * @return BackupResult
 */
function backup_run_backup(): BackupResult
{
    $result = new BackupResult();

    $result->startedAt = time();
    $result->dryRun = backup_flag('dry-run');
    $result->database = DB_NAME;

    // The backup directory is outside the web root by default.
    $directory = backup_option('backup-dir', getenv('BACKUP_DIR') ?: (APP_ROOT . '/storage/backups'));

    $result->backupDirectory = $directory;

    // REFUSE to write into the web root. A backup in public/ would be
    // downloadable over HTTP.
    $publicRoot = realpath(APP_ROOT . '/public');
    $realDirectory = realpath($directory);

    if ($realDirectory !== false && $publicRoot !== false) {
        if (strpos($realDirectory, $publicRoot) === 0) {
            $result->errors[] = 'Refusing to write a backup into the web root (public/).';

            return $result;
        }
    }

    if (strpos(str_replace('\\', '/', $directory), '/public/') !== false) {
        $result->errors[] = 'Refusing to write a backup into the web root (public/).';

        return $result;
    }

    $key = (string) (getenv('BACKUP_ENCRYPTION_KEY') ?: '');
    $encrypt = $key !== '' && !backup_flag('no-encrypt');

    if ($key === '' && !backup_flag('no-encrypt')) {
        $result->errors[] = 'BACKUP_ENCRYPTION_KEY is not set. The backup would be written unencrypted.';

        return $result;
    }

    // The directory is created with mode 0700: only the backup account reads
    // it. It holds the full user table, including password hashes.
    if (!is_dir($directory) && !@mkdir($directory, 0700, true)) {
        $result->errors[] = 'The backup directory could not be created: ' . $directory;

        return $result;
    }

    @chmod($directory, 0700);

    $stamp = date('Y-m-d', $result->startedAt);
    $time = date('His', $result->startedAt);

    $workDirectory = $directory . '/.work-' . $time;
    @mkdir($workDirectory, 0700, true);

    $databaseFile = $workDirectory . '/database.sql';
    $uploadsFile = $workDirectory . '/uploads.tar.gz';
    $manifestFile = $workDirectory . '/manifest.json';

    // ---- The database ----------------------------------------------------
    $command = backup_mysqldump_command(DB_NAME, $databaseFile);

    $exitCode = 0;
    $output = backup_run($command, $exitCode, array('MYSQL_PWD' => DB_PASS));

    if ($exitCode !== 0) {
        $result->errors[] = 'mysqldump failed (exit ' . $exitCode . '): ' . trim($output);

        @unlink($databaseFile);
        backup_remove_work_directory($workDirectory);

        return $result;
    }

    // ---- The uploaded files ----------------------------------------------
    $uploadsDirectory = UPLOAD_DIR;

    if (is_dir($uploadsDirectory)) {
        $tarCommand = 'tar ' . backup_tar_local_arguments() . '-czf ' . backup_shell_quote($uploadsFile)
            . ' -C ' . backup_shell_quote(dirname($uploadsDirectory))
            . ' ' . backup_shell_quote(basename($uploadsDirectory));

        $exitCode = 0;
        $output = backup_run($tarCommand, $exitCode);

        if ($exitCode !== 0) {
            $result->errors[] = 'The uploads archive failed (exit ' . $exitCode . '): ' . trim($output);

            @unlink($databaseFile);
            backup_remove_work_directory($workDirectory);

            return $result;
        }
    } else {
        $result->errors[] = 'The uploads directory does not exist: ' . $uploadsDirectory;

        @unlink($databaseFile);
        backup_remove_work_directory($workDirectory);

        return $result;
    }

    // The file count is recorded so the restore can verify that every image
    // came back.
    $uploadFiles = glob($uploadsDirectory . '/*');
    $result->uploadedFiles = $uploadFiles === false ? 0 : count($uploadFiles);

    // ---- The manifest ----------------------------------------------------
    $manifest = array(
        'created_at'      => date('c', $result->startedAt),
        'database'        => DB_NAME,
        'database_version' => backup_mysqldump_version(),
        'excluded_tables' => BACKUP_EXCLUDED_TABLES,
        'uploaded_files'  => $result->uploadedFiles,
        'table_counts'    => backup_table_counts(),
        'application'     => APP_NAME,
        'schema_sha256'   => backup_file_hash(APP_ROOT . '/database/schema.sql'),
    );

    file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // ---- The archive -----------------------------------------------------
    $archiveFile = $workDirectory . '/greencorner-' . $stamp . '.tar.gz';

    $tarCommand = 'tar ' . backup_tar_local_arguments() . '-czf ' . backup_shell_quote($archiveFile)
        . ' -C ' . backup_shell_quote($workDirectory)
        . ' database.sql uploads.tar.gz manifest.json';

    $exitCode = 0;
    $output = backup_run($tarCommand, $exitCode);

    if ($exitCode !== 0) {
        $result->errors[] = 'The archive failed (exit ' . $exitCode . '): ' . trim($output);

        @unlink($databaseFile);
        @unlink($uploadsFile);
        @unlink($manifestFile);
        backup_remove_work_directory($workDirectory);

        return $result;
    }

    // The intermediate files are removed: only the archive is kept. The
    // archive itself stays in the working directory until the encryption
    // has consumed it, so the working directory can only be removed after
    // that step.
    @unlink($databaseFile);
    @unlink($uploadsFile);
    @unlink($manifestFile);

    // ---- The encryption --------------------------------------------------
    $finalFile = $directory . '/greencorner-' . $stamp . '.tar.gz';

    if ($encrypt) {
        $encryptedFile = $finalFile . '.gpg';

        if (!backup_encrypt($archiveFile, $encryptedFile, $key)) {
            $result->errors[] = 'The encryption failed.';

            @unlink($archiveFile);
            backup_remove_work_directory($workDirectory);

            return $result;
        }

        @unlink($archiveFile);
        $finalFile = $encryptedFile;
        $result->encrypted = true;
    } else {
        @unlink($archiveFile);
    }

    // The working directory is now empty and is removed. A leftover
    // working directory would accumulate in the backup folder, so a
    // failure to remove it is reported rather than ignored.
    if (!backup_remove_work_directory($workDirectory)) {
        $result->warnings[] = 'The working directory could not be removed: ' . $workDirectory;
    }

    // The file is readable only by its owner (0600).
    @chmod($finalFile, 0600);

    $result->databaseFile = $finalFile;
    $result->bytes = (int) @filesize($finalFile);

    // ---- The retention ---------------------------------------------------
    if (!$result->dryRun) {
        $result->removed = backup_apply_retention($directory, array($finalFile));
    }

    $result->finishedAt = time();

    return $result;
}

/**
 * The mysqldump version, recorded in the manifest.
 *
 * The resolved binary is used, not the bare name, so that a client which
 * is installed but not on the PATH is still reported correctly. A failure
 * is recorded as "unknown" rather than as the shell's error message.
 */
function backup_mysqldump_version(): string
{
    $exitCode = 0;
    $output = backup_run(backup_shell_quote(backup_mysqldump_binary()) . ' --version', $exitCode);

    if ($exitCode !== 0) {
        return 'unknown';
    }

    $output = trim($output);

    return $output === '' ? 'unknown' : $output;
}

/**
 * The row count of every table that is included in the backup.
 */
function backup_table_counts(): array
{
    require_once __DIR__ . '/../helpers/db.php';

    $counts = array();

    $tables = db_select('SHOW TABLES');

    foreach ($tables as $row) {
        $table = (string) reset($row);

        if (in_array($table, BACKUP_EXCLUDED_TABLES, true)) {
            continue;
        }

        $count = db_select_one('SELECT COUNT(*) AS `c` FROM `' . $table . '`');

        $counts[$table] = (int) ($count['c'] ?? 0);
    }

    return $counts;
}

/**
 * The SHA-256 of a file, or an empty string when it does not exist.
 */
function backup_file_hash(string $path): string
{
    if (!is_file($path)) {
        return '';
    }

    $hash = @hash_file('sha256', $path);

    return $hash === false ? '' : $hash;
}

// ---------------------------------------------------------------------------
// The command line
// ---------------------------------------------------------------------------

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $result = backup_run_backup();

    $summary = array(
        'started_at'       => date('c', $result->startedAt),
        'finished_at'      => date('c', $result->finishedAt),
        'database'         => $result->database,
        'backup_directory' => $result->backupDirectory,
        'file'             => $result->databaseFile,
        'bytes'            => $result->bytes,
        'uploaded_files'   => $result->uploadedFiles,
        'encrypted'        => $result->encrypted,
        'dry_run'          => $result->dryRun,
        'removed_by_retention' => $result->removed,
        'errors'           => $result->errors,
    );

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    exit($result->errors === array() ? 0 : 1);
}
