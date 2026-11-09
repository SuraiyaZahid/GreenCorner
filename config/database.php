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
 * The local file is REQUIRED. The tracked file carries no password, so
 * there is nothing to fall back on: if config/config.local.php is missing,
 * or a required credential is empty, this loader stops with one clear error
 * that names the missing key and the file to create. It never falls back to
 * an empty or guessed value.
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

// The local file carries the real credentials and is REQUIRED. The tracked
// file has no password default, so there is nothing to fall back on.
//
// The local override is loaded FIRST, so that the constants it defines are
// the ones that survive: config/config.php only defines a constant when it
// has not been defined already.
//
// The local file may redefine any constant. Each constant is guarded so an
// undefined one does not raise a PHP warning, which would otherwise be
// written into the response body and corrupt the HTTP status code.
if (!is_file($localConfigFile)) {
    throw new RuntimeException(
        "GreenCorner configuration error: the file config/config.local.php is missing.\n"
        . "\n"
        . "It holds the database credentials for this installation and is not\n"
        . "tracked in version control. Create it from the example:\n"
        . "\n"
        . '    copy config/config.example.php config/config.local.php' . "\n"
        . "\n"
        . "Then fill in the database details in config/config.local.php.\n"
    );
}

require_once $localConfigFile;

require_once $defaultConfigFile;

/**
 * The settings that must resolve to a usable value before the application
 * can connect to the database.
 *
 * Each entry lists the constants that satisfy it. The first constant that is
 * defined and non-empty satisfies the requirement.
 */
$requiredSettings = array(
    'DB_HOST' => array('DB_HOST'),
    'DB_NAME' => array('DB_NAME'),
    'DB_USER' => array('DB_USER'),
    'DB_PASS' => array('DB_PASS'),
);

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

$missingSetting = green_corner_missing_setting($requiredSettings);

if ($missingSetting !== null) {
    // The message tells the developer exactly what to do. It names the
    // setting and the file to create, and it never contains a credential.
    $message = "GreenCorner configuration error: the setting {$missingSetting} is missing or empty.\n"
        . "\n"
        . "Create the local configuration file and fill it in:\n"
        . "\n"
        . '    copy config/config.example.php config/config.local.php' . "\n"
        . "\n"
        . "Then set {$missingSetting} in config/config.local.php.\n";

    throw new RuntimeException($message);
}
