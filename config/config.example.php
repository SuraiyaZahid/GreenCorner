<?php
/**
 * GreenCorner — Example Local Configuration
 *
 * Copy this file to config/config.local.php and fill in the real values for
 * your machine or deployment:
 *
 *     copy config/config.example.php config/config.local.php
 *
 * config/config.local.php is listed in .gitignore and must never be
 * committed. Everything in this file is a placeholder — no value here works
 * against a real database.
 *
 * This file is REQUIRED: the tracked config/config.php carries no database
 * password, so the application refuses to start until config/config.local.php
 * exists and DB_PASS is set.
 *
 * Each constant below is only defined when it has not been defined already,
 * so the local file overrides the tracked defaults in config/config.php.
 *
 * Implements decision D-001 (see config/DECISIONS.md).
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

// 'development' or 'production'. Production enables the Secure session
// cookie flag (SRS §4.4, BR-17, SC-16).
if (!defined('APP_ENV')) {
    define('APP_ENV', 'development');
}

// True while developing: verbose error logging.
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', true);
}

// ---------------------------------------------------------------------------
// Application identity
// ---------------------------------------------------------------------------

// The base URL of the installation, with NO trailing slash.
// Examples: 'http://localhost:8000' or 'https://shop.example.com'
if (!defined('APP_URL')) {
    define('APP_URL', 'http://127.0.0.1:8000');
}

// ---------------------------------------------------------------------------
// Database (MySQLi)
//
// Replace every placeholder below with the real values for this
// installation. The password must never be committed to Git.
// ---------------------------------------------------------------------------

// The database server host.
if (!defined('DB_HOST')) {
    define('DB_HOST', 'CHANGE_ME_DB_HOST');
}

// The database server port.
if (!defined('DB_PORT')) {
    define('DB_PORT', 3306);
}

// The database name.
if (!defined('DB_NAME')) {
    define('DB_NAME', 'CHANGE_ME_DB_NAME');
}

// The database account.
if (!defined('DB_USER')) {
    define('DB_USER', 'CHANGE_ME_DB_USER');
}

// The database password.
if (!defined('DB_PASS')) {
    define('DB_PASS', 'CHANGE_ME_DB_PASSWORD');
}

// ---------------------------------------------------------------------------
// Mobile wallet merchant numbers (bKash and Nagad)
// ---------------------------------------------------------------------------
//
// GreenCorner accepts manual merchant payments from bKash and Nagad: the
// customer sends the money to the merchant number below, then submits the
// sender number and the transaction id, and an Admin verifies it.
//
// Put YOUR OWN store numbers here. Leave a value empty to hide that
// payment method from the checkout page entirely.
//
// These are the numbers the customer dials or types into their wallet app,
// so they are public information — unlike a gateway API credential. They
// still belong in this file rather than in the code, because a store number
// changes and must never be hardcoded in a view or a controller.
//
// WHEN YOU LATER INTEGRATE THE OFFICIAL bKash OR NAGAD API
//
// The manual flow above needs no API credential. If you want the store to
// initiate a payment, receive a callback, query a transaction or issue a
// refund automatically, add the gateway credentials here as well:
//
//   BKASH_APP_KEY, BKASH_APP_SECRET, BKASH_USERNAME, BKASH_PASSWORD
//   NAGAD_APP_KEY, NAGAD_APP_SECRET, NAGAD_USERNAME, NAGAD_PASSWORD
//
// The bKash documentation is at https://developer.bka.sh/. Until those
// credentials exist and the integration is written, every wallet payment is
// verified by hand by an Admin.

if (!defined('BKASH_MERCHANT_NUMBER')) {
    define('BKASH_MERCHANT_NUMBER', 'CHANGE_ME_BKASH_MERCHANT_NUMBER');
}

if (!defined('NAGAD_MERCHANT_NUMBER')) {
    define('NAGAD_MERCHANT_NUMBER', 'CHANGE_ME_NAGAD_MERCHANT_NUMBER');
}
