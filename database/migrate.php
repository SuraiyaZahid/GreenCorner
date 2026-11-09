<?php
/**
 * GreenCorner — Migration runner
 *
 * Applies the SQL files in database/migrations/ that have not been applied
 * yet, and records each one in the `schema_migrations` table so that a file
 * is never applied twice.
 *
 * Usage:
 *   php database/migrate.php
 *   php database/migrate.php --status
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/db.php';

$statusOnly = in_array('--status', array_slice($argv, 1), true);

/**
 * Create the bookkeeping table if it does not exist yet.
 */
function migrate_ensure_table(): void
{
    db()->query(
        'CREATE TABLE IF NOT EXISTS `schema_migrations` ('
        . '`name` VARCHAR(190) NOT NULL,'
        . '`applied_at` INT UNSIGNED NOT NULL,'
        . 'PRIMARY KEY (`name`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * The migration files, in name order.
 *
 * @return string[]
 */
function migrate_files(): array
{
    $files = glob(__DIR__ . '/migrations/*.sql');
    return $files === false ? array() : $files;
}

/**
 * The names of the migrations that have already been applied.
 *
 * @return string[]
 */
function migrate_applied(): array
{
    $rows = db_select('SELECT `name` FROM `schema_migrations`');
    $names = array();

    foreach ($rows as $row) {
        $names[] = (string) $row['name'];
    }

    return $names;
}

migrate_ensure_table();

$applied = migrate_applied();
$files = migrate_files();

foreach ($files as $file) {
    $name = basename($file);

    if (in_array($name, $applied, true)) {
        echo 'skip    ' . $name . ' (already applied)' . PHP_EOL;
        continue;
    }

    if ($statusOnly) {
        echo 'pending ' . $name . PHP_EOL;
        continue;
    }

    $sql = file_get_contents($file);

    if ($sql === false) {
        throw new RuntimeException('Could not read migration file: ' . $file);
    }

    // The USE statement in the file is replaced with the configured database,
    // so the runner works whatever the local credentials are.
    $sql = preg_replace('/^\s*USE\s+[^;]+;\s*$/mi', '', $sql);

    if (!db()->multi_query($sql)) {
        throw new RuntimeException('Migration failed (' . $file . '): ' . db()->error);
    }

    // multi_query() queues every result; each one must be consumed before the
    // next statement may run.
    do {
        $result = db()->store_result();

        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while (db()->more_results() && db()->next_result());

    if (db()->errno !== 0) {
        throw new RuntimeException('Migration failed (' . $file . '): ' . db()->error);
    }

    db_write(
        'INSERT INTO `schema_migrations` (`name`, `applied_at`) VALUES (?, ?)',
        'si',
        array($name, time())
    );

    echo 'applied ' . $name . PHP_EOL;
}

if ($statusOnly) {
    echo PHP_EOL . 'Applied migrations:' . PHP_EOL;

    foreach (migrate_applied() as $name) {
        echo '  ' . $name . PHP_EOL;
    }
}
