<?php
/**
 * GreenCorner — Application Logging
 *
 * A small append-only logging facility for the events the approved
 * requirements require to be recorded:
 *
 *   BR-13  a cancellation whose stock cannot be restored is "logged for
 *          manual handling" (FR-6.8, acceptance criterion 6)
 *   the Admin-initiated password reset audit trail
 *
 * The format is **JSON Lines**: one valid JSON object per line, so a file
 * can be read line by line and parsed entry by entry without a full-file
 * parse.
 *
 * The log directory is `storage/logs/`. It sits outside the web root (the
 * document root is `public/`), and `storage/.htaccess` denies it as well,
 * so the files can never be fetched over HTTP (SEC-6).
 *
 * Rules that this file enforces:
 *
 *   - **Nothing sensitive is ever written.** Passwords, password hashes,
 *     temporary credentials and card instrument data must never reach a log
 *     line. The callers pass structured data; this file refuses to encode
 *     any key whose name looks like a secret.
 *   - Writes are **append-locked**, so two concurrent requests cannot
 *     interleave halves of a line.
 *   - The file is **rotated at approximately 1 MB**, so the log cannot grow
 *     without bound.
 *
 * Implements:
 *   BR-13  the manual-handling log for unrestorable cancellations
 *   FR-6.10 the Admin password-reset audit record
 */

require_once __DIR__ . '/functions.php';

require_once dirname(__DIR__) . '/config/bootstrap.php';

/**
 * The directory the log files are written to.
 *
 * It is outside the web root, so the files are not reachable over HTTP.
 */
if (!defined('LOG_DIRECTORY')) {
    define('LOG_DIRECTORY', __DIR__ . '/../storage/logs');
}

/**
 * The size at which a log file is rotated, in bytes.
 */
if (!defined('LOG_ROTATE_BYTES')) {
    define('LOG_ROTATE_BYTES', 1048576);
}

/**
 * The channel used for the manual-handling log of FR-6.8 / BR-13.
 */
const LOG_CHANNEL_ORDER_CANCELLATION = 'order_cancellation';

/**
 * The channel used for the Admin action audit trail.
 */
const LOG_CHANNEL_ADMIN_ACTIONS = 'admin_actions';

/**
 * The key names that must never appear in a log entry.
 *
 * A key that matches one of these patterns is dropped from the entry
 * entirely, so a caller cannot accidentally write password material into a
 * log file (SEC-15, SEC-18).
 *
 * @var array<int, string>
 */
const LOG_FORBIDDEN_KEY_PATTERNS = array(
    'password',
    'passwd',
    'pwd',
    'secret',
    'token',
    'csrf',
    'card_number',
    'cardnumber',
    'cvv',
    'pin',
    'authorization',
    'cookie',
);

/**
 * Whether a key name looks like it carries secret material.
 *
 * @param string $key
 * @return bool
 */
function log_key_is_secret(string $key): bool
{
    $needle = strtolower($key);

    foreach (LOG_FORBIDDEN_KEY_PATTERNS as $pattern) {
        if (strpos($needle, $pattern) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Remove the secret-looking keys from a set of context values.
 *
 * The values are dropped, not masked: a masked secret is still a partial
 * disclosure, and there is no reason for the value to be in the entry at
 * all.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function log_scrub_context(array $context): array
{
    $clean = array();

    foreach ($context as $key => $value) {
        if (!is_string($key) || log_key_is_secret($key)) {
            continue;
        }

        if (is_array($value)) {
            $clean[$key] = log_scrub_context($value);
            continue;
        }

        if (is_scalar($value) || $value === null) {
            $clean[$key] = $value;
        }
    }

    return $clean;
}

/**
 * The path of a channel's log file.
 *
 * @param string $channel
 * @return string
 */
function log_file_path(string $channel): string
{
    // The channel becomes a filename, so only safe characters are kept.
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $channel);

    if ($safe === null || $safe === '') {
        $safe = 'application';
    }

    return LOG_DIRECTORY . '/' . $safe . '.log';
}

/**
 * Make sure the log directory exists and is not readable over the web.
 *
 * The directory is created with restrictive permissions, so the log files
 * are readable only by the account the web server runs as.
 *
 * @return void
 */
function log_ensure_directory(): void
{
    if (!is_dir(LOG_DIRECTORY)) {
        @mkdir(LOG_DIRECTORY, 0750, true);
    }

    // The directory is outside the web root, but a deny rule is added as
    // well so a misconfigured document root still cannot serve the files
    // (SEC-6).
    $htaccess = LOG_DIRECTORY . '/.htaccess';

    if (!is_file($htaccess)) {
        $rule = "# GreenCorner — the log directory is never served over HTTP.\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order deny,allow\n"
            . "    Deny from all\n"
            . "</IfModule>\n";

        @file_put_contents($htaccess, $rule);
        @chmod($htaccess, 0640);
    }
}

/**
 * Rotate a log file when it has grown past the size limit.
 *
 * The previous file is kept with a `.1` suffix, so the most recent history
 * is always available and the directory cannot grow without bound.
 *
 * @param string $path
 * @return void
 */
function log_rotate_if_needed(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $size = @filesize($path);

    if ($size === false || $size < LOG_ROTATE_BYTES) {
        return;
    }

    @rename($path, $path . '.1');
}

/**
 * Write one entry to a channel's log file.
 *
 * The entry is one JSON object on one line. The write is append-locked, so
 * two concurrent requests cannot interleave and produce an invalid file.
 *
 * @param string $channel The log channel, for example
 *                       LOG_CHANNEL_ORDER_CANCELLATION.
 * @param string $event   The event name, for example 'stock_not_restored'.
 * @param array<string, mixed> $context The event details. Secret-looking
 *                       keys are removed before the entry is written.
 * @return bool True when the entry was written.
 */
function gc_log(string $channel, string $event, array $context = array()): bool
{
    log_ensure_directory();

    $entry = array(
        'timestamp' => gmdate('c'),
        'channel'   => $channel,
        'event'     => $event,
    );

    foreach (log_scrub_context($context) as $key => $value) {
        $entry[$key] = $value;
    }

    // JSON_UNESCAPED_UNICODE keeps a product name readable; the invalid
    // UTF-8 flag stops a malformed byte sequence from producing an invalid
    // JSON line.
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($line === false) {
        return false;
    }

    $path = log_file_path($channel);

    log_rotate_if_needed($path);

    // The append lock is held for the write only.
    $handle = @fopen($path, 'ab');

    if ($handle === false) {
        error_log('[GreenCorner] Could not open the log file for writing: ' . $path);
        return false;
    }

    $written = false;

    if (flock($handle, LOCK_EX)) {
        $written = fwrite($handle, $line . "\n") !== false;
        fflush($handle);
        flock($handle, LOCK_UN);
    }

    fclose($handle);

    if ($written) {
        // The log file holds operational detail, so it is not world-readable.
        @chmod($path, 0640);
    }

    return $written;
}

/**
 * Read the entries of a channel's log file.
 *
 * Used by the tests, and by whoever handles the manual-handling cases.
 * Unreadable lines are skipped, so a partially written line never breaks
 * the reader.
 *
 * @param string $channel
 * @return array<int, array<string, mixed>>
 */
function gc_log_read(string $channel): array
{
    $path = log_file_path($channel);

    if (!is_file($path)) {
        return array();
    }

    $entries = array();
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ((array) $lines as $line) {
        $decoded = json_decode((string) $line, true);

        if (is_array($decoded)) {
            $entries[] = $decoded;
        }
    }

    return $entries;
}

/**
 * Remove the log files of a channel.
 *
 * Used by the tests, so a suite can assert on a log without inheriting the
 * entries of an earlier run.
 *
 * @param string $channel
 * @return void
 */
function gc_log_clear(string $channel): void
{
    $path = log_file_path($channel);

    if (is_file($path)) {
        @unlink($path);
    }

    if (is_file($path . '.1')) {
        @unlink($path . '.1');
    }
}
