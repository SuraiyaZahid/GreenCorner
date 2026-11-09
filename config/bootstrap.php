<?php
/**
 * GreenCorner — Application Bootstrap
 *
 * The single entry point for loading the configuration. Every part of the
 * application — the front controller, the helpers and the test suite —
 * starts here, so that configuration is always loaded the same way
 * (SRS §10.1, FR-6.12).
 *
 * It does four things:
 *
 *   1. Marks the request as an application request, which lets the
 *      configuration files refuse to run when they are fetched directly
 *      over HTTP (SEC-6).
 *   2. Checks that the PHP extensions the application depends on are
 *      actually loaded, so that a missing extension is reported as a
 *      configuration problem instead of as a fatal error deep inside a
 *      controller.
 *   3. Loads config/config.local.php (the untracked, real credentials) and
 *      config/config.php (the tracked defaults), through config/database.php.
 *   4. Makes the constants available to the rest of the application.
 *
 * Implements decision D-001 (see config/DECISIONS.md).
 */

// The guard constant. It is defined before anything else so the
// configuration files can check it.
if (!defined('GREENCORNER_APP')) {
    define('GREENCORNER_APP', true);
}

/**
 * The PHP extensions the application cannot run without.
 *
 * Each entry names the extension, the function that would fail without it,
 * and what the application uses it for. The check below runs once, at
 * bootstrap, so the failure is a clear message rather than a fatal
 * "Call to undefined function" raised from whichever controller happened to
 * touch the function first.
 *
 * Declared with a guard rather than as a bare `const`, so that including
 * this file through two different paths cannot raise a redeclaration error.
 */
if (!defined('GREEN_CORNER_REQUIRED_EXTENSIONS')) {
    define('GREEN_CORNER_REQUIRED_EXTENSIONS', array(
        'mbstring' => array(
            'function' => 'mb_strlen',
            'purpose'  => 'measuring field lengths in characters (name, email, address)',
        ),
        'mysqli' => array(
            'function' => 'mysqli',
            'purpose'  => 'the database driver (R-SQL-*)',
        ),
        'gd' => array(
            'function' => 'imagecreatetruecolor',
            'purpose'  => 'the product image pipeline (FR-6.1, FR-6.2)',
        ),
    ));
}

/**
 * The extensions that are not loaded.
 *
 * @return array<int, string> The names of the missing extensions.
 */
function green_corner_missing_extensions(): array
{
    $missing = array();

    foreach (GREEN_CORNER_REQUIRED_EXTENSIONS as $extension => $detail) {
        if (extension_loaded($extension)) {
            continue;
        }

        // The extension may be compiled in but its functions unavailable, so
        // the function is checked as well.
        if (!function_exists($detail['function'])) {
            $missing[] = $extension;
        }
    }

    return $missing;
}

/**
 * Refuse to start when a required PHP extension is missing.
 *
 * The message names every missing extension and how to install it, so the
 * operator does not have to read the fatal error to find out which
 * extension to add. Nothing about the application state is disclosed to a
 * visitor: the message is written to the error log, and the response is a
 * generic failure.
 *
 * @return void
 */
function green_corner_require_extensions(): void
{
    $missing = green_corner_missing_extensions();

    if ($missing === array()) {
        return;
    }

    $message = 'GreenCorner cannot start: the following PHP extension'
        . (count($missing) === 1 ? ' is' : 's are')
        . ' missing: ' . implode(', ', $missing) . ".\n"
        . "\n"
        . "Install " . (count($missing) === 1 ? 'it' : 'them') . " and restart the server:\n"
        . "\n"
        . '    docker-php-ext-install ' . implode(' ', $missing) . "\n"
        . "\n"
        . "The missing extensions are needed for:\n";

    foreach ($missing as $extension) {
        $detail = GREEN_CORNER_REQUIRED_EXTENSIONS[$extension];
        $message .= '  - ' . $extension . ': ' . $detail['purpose'] . "\n";
    }

    error_log('[GreenCorner] ' . $message);

    throw new RuntimeException($message);
}

green_corner_require_extensions();

require_once __DIR__ . '/database.php';
