<?php
/**
 * GreenCorner — Application Configuration
 *
 * Single source of truth for environment settings. Every other part of the
 * application reads its configuration from this file (SRS §10.1, FR-6.12).
 *
 * Values marked ENVIRONMENT are read from a local, untracked override file
 * (config/config.local.php) when present, so that real credentials never
 * live in version control.
 */

// The config/ directory is outside the web root, but this file refuses to
// run when it is reached directly over HTTP (SEC-6, decision D-001).
if (!defined('GREENCORNER_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------------

// Every ENVIRONMENT constant below is only defined when it has not been
// defined already, so that config/config.local.php — which is loaded first
// and is not tracked in version control — always wins.
//
// 'development' or 'production'. Production refuses to run the seed script
// and enables the Secure session cookie flag (SRS §4.4, BR-17, SC-16).
if (!defined('APP_ENV')) {
    define('APP_ENV', 'development');
}

// True while developing: verbose errors, no Secure cookie flag.
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', true);
}

// ---------------------------------------------------------------------------
// Application identity
// ---------------------------------------------------------------------------

if (!defined('APP_NAME')) {
    define('APP_NAME', 'GreenCorner');
}

// Base URL of the installation, with NO trailing slash.
// Examples: 'http://localhost:8000' or 'https://shop.example.com'
// Override with the GREEN_CORNER_URL environment variable, which is how the
// test suite points the application at the address it is served on.
if (!defined('APP_URL')) {
    define('APP_URL', rtrim((string) (getenv('GREEN_CORNER_URL') ?: 'http://127.0.0.1:8000'), '/'));
}

// Root path of the project on disk (used to resolve paths outside the web root).
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// ---------------------------------------------------------------------------
// Database (MySQLi)
// ---------------------------------------------------------------------------
//
// The host, port, database name, user and password are ENVIRONMENT
// settings. They are supplied by config/config.local.php, which is not
// tracked in version control, so that a real credential never reaches Git
// (decision D-001, see config/DECISIONS.md).
//
// There is deliberately NO password default here. The tracked file must not
// carry a credential of any kind, so config/database.php treats a missing
// config/config.local.php as a fatal error and explains how to create one.
//
// The connection details below are the non-secret ones: the host, port,
// character set and collation. The database name and user are also set here
// because they are not secret, but they may be overridden by the local file.
//
// Every constant is only defined when it has not been defined already, so
// the local file — which is loaded first — always wins.

if (!defined('DB_HOST')) {
    define('DB_HOST', '127.0.0.1');
}

if (!defined('DB_PORT')) {
    define('DB_PORT', 3306);
}

if (!defined('DB_NAME')) {
    define('DB_NAME', 'greencorner');
}

if (!defined('DB_USER')) {
    define('DB_USER', 'greencorner');
}

// DB_PASS has no default on purpose. It is defined by
// config/config.local.php, and config/database.php fails with a clear
// message when it is missing.

if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

if (!defined('DB_COLLATION')) {
    define('DB_COLLATION', 'utf8mb4_unicode_ci');
}

/**
 * The session SQL mode string. R-SQL-2 requires the application to name
 * STRICT_TRANS_TABLES explicitly in the session mode it sets, so that a
 * connection cannot silently inherit a relaxed mode.
 */
define('DB_SESSION_SQL_MODE', 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE');

// ---------------------------------------------------------------------------
// Mobile wallet merchant numbers (bKash and Nagad)
// ---------------------------------------------------------------------------
//
// GreenCorner accepts manual merchant payments from bKash and Nagad: the
// customer sends the money to the store's merchant number and then submits
// the sender number and the transaction id for an Admin to verify.
//
// The numbers below are PLACEHOLDERS. They are deliberately not the numbers
// from any screenshot or example document: each store must put its own
// merchant number in config/config.local.php, which is untracked and
// therefore never reaches Git.
//
// An empty value means the wallet method is not available in this
// installation. The checkout page hides the method entirely in that case,
// so a customer is never shown an instruction to send money to a number
// nobody is watching.
//
// These numbers are PUBLIC information. They are what the customer dials
// or types into their wallet app, so unlike a gateway API credential they
// do not need to be secret — but they are still configuration rather than
// code, because a store number changes and the value must never be
// hardcoded in a view or a controller.
//
// FUTURE GATEWAY CREDENTIALS
//
// No bKash or Nagad API credential (app key, app secret, username,
// password, encryption key) is read here, because no gateway call is made
// yet. When the official merchant API is integrated, its credentials belong
// in config/config.local.php under names of this shape:
//
//   BKASH_APP_KEY, BKASH_APP_SECRET, BKASH_USERNAME, BKASH_PASSWORD
//   NAGAD_APP_KEY, NAGAD_APP_SECRET, NAGAD_USERNAME, NAGAD_PASSWORD
//
// The official documentation is https://developer.bka.sh/ for bKash and the
// Nagad merchant guide for Nagad. Until those credentials exist and the
// integration is written, every wallet payment is verified BY HAND by an
// Admin — see models/WalletPayment.php.

if (!defined('BKASH_MERCHANT_NUMBER')) {
    define('BKASH_MERCHANT_NUMBER', '');
}

if (!defined('NAGAD_MERCHANT_NUMBER')) {
    define('NAGAD_MERCHANT_NUMBER', '');
}

// The short dial codes the customer uses to reach the merchant payment
// menu. These are fixed by the operators, not by the store, so they live
// in the tracked configuration rather than in the local override.
if (!defined('BKASH_MERCHANT_DIAL_CODE')) {
    define('BKASH_MERCHANT_DIAL_CODE', '*247#');
}

if (!defined('NAGAD_MERCHANT_DIAL_CODE')) {
    define('NAGAD_MERCHANT_DIAL_CODE', '*167#');
}

// ---------------------------------------------------------------------------
// Store currency (BR-1, SC-6)
// ---------------------------------------------------------------------------

define('CURRENCY_CODE', 'BDT');
define('CURRENCY_SYMBOL', '৳');

// ---------------------------------------------------------------------------
// Sessions (FR-1.10, FR-7.6)
// ---------------------------------------------------------------------------

// Inactivity timeout in seconds: 8 hours.
define('SESSION_IDLE_TIMEOUT', 28800);

// Absolute maximum lifetime in seconds: 7 days.
define('SESSION_ABSOLUTE_TIMEOUT', 604800);

// Length of the generated session identifier, in characters (FR-7.6).
define('SESSION_ID_LENGTH', 32);

// Name of the session cookie.
define('SESSION_COOKIE_NAME', 'greencorner_session');

// ---------------------------------------------------------------------------
// Login rate limiting (NFR-S-06, SEC-13)
// ---------------------------------------------------------------------------

// Maximum failed login attempts allowed from one source address.
define('LOGIN_MAX_ATTEMPTS', 5);

// How long (seconds) a source address stays locked out after too many failures.
define('LOGIN_LOCKOUT_SECONDS', 900);

// ---------------------------------------------------------------------------
// Uploads (FR-2.12, SEC-4)
// ---------------------------------------------------------------------------

define('MAX_UPLOAD_BYTES', 2097152); // 2 MB
define('ALLOWED_IMAGE_MIME', ['image/jpeg', 'image/png', 'image/webp']);

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------

// Directory that stores uploaded product images (outside the web root, SEC-6).
define('UPLOAD_DIR', APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads');

// Public URL prefix under which the upload directory is served.
define('UPLOAD_URL', APP_URL . '/uploads');
