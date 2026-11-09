<?php
/**
 * GreenCorner — Phase 6: scratch database preparation
 *
 * Creates (or drops and recreates) an ISOLATED scratch schema for the
 * restore test. The scratch schema is never the application's own
 * database, and its name always ends with "_scratch".
 *
 * This helper is only ever used by the Phase 6 restore test. It refuses
 * to touch any database whose name does not end with "_scratch".
 *
 * Usage:
 *   php tests/phase6_scratch_setup.php --scratch-db=NAME --action=create|drop
 *
 *   The administrative account is taken from SCRATCH_ADMIN_USER and
 *   SCRATCH_ADMIN_PASS when they are set, because the application's own
 *   account is not granted the CREATE DATABASE privilege. The application
 *   account is still used for the restore itself.
 */

require_once dirname(__DIR__) . '/config/bootstrap.php';

function scratch_option(string $name, ?string $default = null): ?string
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

$database = scratch_option('scratch-db');
$action = scratch_option('action', 'create');

if ($database === null || $database === '') {
    fwrite(STDERR, "A scratch database name is required (--scratch-db).\n");
    exit(1);
}

// The fence: only a name that ends with "_scratch" may be touched, and the
// application's own database is refused outright.
if (substr($database, -8) !== '_scratch') {
    fwrite(STDERR, 'The name must end with "_scratch". Refusing: ' . $database . "\n");
    exit(1);
}

if (strcasecmp($database, DB_NAME) === 0) {
    fwrite(STDERR, "That is the application's own database. Refusing.\n");
    exit(1);
}

$connection = @new mysqli(DB_HOST, DB_USER, DB_PASS);

if ($connection->connect_errno) {
    fwrite(STDERR, 'The server could not be reached: ' . $connection->connect_error . "\n");
    exit(1);
}

// The application's account has no CREATE DATABASE privilege, so the
// schema is created through an administrative account when one is given.
$adminUser = getenv('SCRATCH_ADMIN_USER');
$adminPass = getenv('SCRATCH_ADMIN_PASS');

if ($adminUser !== false && $adminUser !== '') {
    $admin = @new mysqli(DB_HOST, $adminUser, $adminPass === false ? '' : $adminPass);

    if ($admin->connect_errno) {
        fwrite(STDERR, 'The administrative account could not be reached: ' . $admin->connect_error . "\n");
        exit(1);
    }

    $connection->close();
    $connection = $admin;
}

if ($action === 'drop') {
    $connection->query('DROP DATABASE IF EXISTS `' . $database . '`');
    $connection->close();

    echo "The scratch database " . $database . " was dropped.\n";
    exit(0);
}

$connection->query('DROP DATABASE IF EXISTS `' . $database . '`');
$connection->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

if ($connection->errno) {
    fwrite(STDERR, 'The scratch database could not be created: ' . $connection->error . "\n");
    exit(1);
}

// The application's account must be able to write into the scratch schema,
// because the restore tool connects with that account and never with the
// application's own database. The account's existing host is looked up
// first, because a GRANT to a host that does not exist would try to create
// a new account.
$hostRows = $connection->query(
    "SELECT `host` FROM `mysql`.`user` WHERE `user` = '" . $connection->real_escape_string(DB_USER) . "'"
);

$host = '%';

if ($hostRows && $hostRows->num_rows > 0) {
    $hostRow = $hostRows->fetch_assoc();
    $hostRows->free();

    if (isset($hostRow['host'])) {
        $host = (string) $hostRow['host'];
    }
}

$connection->query(
    "GRANT ALL PRIVILEGES ON `" . $database . "`.* TO '" . $connection->real_escape_string(DB_USER) . "'@'"
    . $connection->real_escape_string($host) . "'"
);
$connection->query('FLUSH PRIVILEGES');

if ($connection->errno) {
    fwrite(STDERR, 'The grant could not be made: ' . $connection->error . "\n");
    exit(1);
}

$connection->close();

echo "The scratch database " . $database . " was created empty.\n";
exit(0);
