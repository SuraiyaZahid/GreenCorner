<?php
/**
 * GreenCorner — Configuration Loader (Database Settings)
 *
 * This file is the only place that loads the application configuration
 * (SRS §10.1, FR-6.12). Every other part of the application reads its
 * settings from the constants defined here.
 *
 * Four files are involved:
 *
 *   config/config.php          Tracked. Non-secret defaults. Carries NO
 *                              database password.
 *   config/config.example.php  Tracked. Placeholder template for a new
 *                              installation.
 *   config/config.local.php    NOT tracked (see .gitignore). The real
 *                              credentials for this machine or deployment.
 *   config/bootstrap.php       The entry point every caller uses. It defines
 *                              the access guard and loads this file.
 *
 * The local file is REQUIRED before the application can reach the database.
 * The tracked file carries no password, so there is nothing to fall back on:
 * if config/config.local.php is missing, or a required credential is empty,
 * the connection is refused with one clear error that names the missing key
 * and the file to create. It never falls back to an empty or guessed value.
 *
 * The requirement is enforced by helpers/db.php at the moment the connection
 * is opened, not by this loader. This loader only DEFINES the settings, so
 * that a file which merely reads the configuration - a static check, a
 * documentation generator - can load it on a machine that has no
 * credentials for this installation. The static check suite runs in CI
 * against a fresh checkout, where config/config.local.php deliberately does
 * not exist, and those checks never open a connection.
 *
 * Implements decision D-001 (see config/DECISIONS.md).
 */

// ---------------------------------------------------------------------------
// Guard against direct access
// ---------------------------------------------------------------------------

// The config/ directory sits outside the web root, but this file refuses to
// do anything useful if it is reached directly over HTTP (SEC-6).
//
// The constant is defined here so that every entry point loads the
// configuration through this file, and only through this file.
if (!defined('GREENCORNER_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------------------------------------------------------------------------
// Load the tracked defaults, then the local override
// ---------------------------------------------------------------------------

$configDirectory = __DIR__;
$defaultConfigFile = $configDirectory . '/config.php';
$localConfigFile   = $configDirectory . '/config.local.php';

if (!is_file($defaultConfigFile)) {
    throw new RuntimeException(
        'GreenCorner configuration error: the file ' . $defaultConfigFile . ' is missing.'
    );
}

// The local file carries the real credentials. The tracked file has no
// password default, so there is nothing to fall back on.
//
// The local override is loaded FIRST, so that the constants it defines are
// the ones that survive: config/config.php only defines a constant when it
// has not been defined already.
//
// The local file may redefine any constant. Each constant is guarded so an
// undefined one does not raise a PHP warning, which would otherwise be
// written into the response body and corrupt the HTTP status code.
//
// The file is loaded when it exists. When it does NOT exist, the tracked
// defaults are still loaded, so that the non-secret settings are available.
// The credential requirement is NOT dropped: helpers/db.php refuses to open
// a connection without DB_PASS, and says exactly which file to create. That
// way a static check can read the configuration on a machine that has no
// credentials for this installation, while the application still refuses to
// talk to the database without them.
if (is_file($localConfigFile)) {
    require_once $localConfigFile;
}

require_once $defaultConfigFile;

/**
 * The settings that must resolve to a usable value before the application
 * can connect to the database.
 *
 * Each entry lists the constants that satisfy it. The first constant that is
 * defined and non-empty satisfies the requirement.
 *
 * This is a constant rather than a local variable because the check below
 * runs from a function, which cannot see the enclosing scope.
 */
if (!defined('GREEN_CORNER_REQUIRED_DB_SETTINGS')) {
    define('GREEN_CORNER_REQUIRED_DB_SETTINGS', array(
        'DB_HOST' => array('DB_HOST'),
        'DB_NAME' => array('DB_NAME'),
        'DB_USER' => array('DB_USER'),
        'DB_PASS' => array('DB_PASS'),
    ));
}

/**
 * The name of a setting that is missing, or null when every setting is
 * present.
 */
function green_corner_missing_setting(array $requirements): ?string
{
    foreach ($requirements as $label => $constants) {
        foreach ($constants as $constant) {
            if (!defined($constant)) {
                continue;
            }

            $value = constant($constant);

            // A non-empty string or any non-string value counts as present.
            // Only a null, false or empty string is missing.
            if ($value !== null && $value !== false && $value !== '') {
                continue 2;
            }
        }

        return $label;
    }

    return null;
}

/**
 * Refuse to go on when a database setting is missing.
 *
 * This is called by helpers/db.php at the moment the connection is opened,
 * so that the requirement is enforced exactly where the credentials are
 * used. The application still never connects with a missing or empty
 * password: it stops here instead (decision D-001).
 *
 * The message tells the developer exactly what to do. It names the setting
 * and the file to create, and it never contains a credential.
 *
 * @throws RuntimeException if a setting is missing or empty.
 * @return void
 */
function green_corner_require_database_settings(): void
{
    $missingSetting = green_corner_missing_setting(GREEN_CORNER_REQUIRED_DB_SETTINGS);

    if ($missingSetting === null) {
        return;
    }

    $message = "GreenCorner configuration error: the setting {$missingSetting} is missing or empty.\n"
        . "\n"
        . "Create the local configuration file and fill it in:\n"
        . "\n"
        . '    copy config/config.example.php config/config.local.php' . "\n"
        . "\n"
        . "Then set {$missingSetting} in config/config.local.php.\n";

    throw new RuntimeException($message);
}
