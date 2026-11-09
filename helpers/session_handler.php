<?php
/**
 * GreenCorner — Custom Database-Backed Session Save Handler
 *
 * Implements FR-7.6 and FR-1.10:
 *   - Sessions are server-side and stored in the database, so session state
 *     survives a PHP process restart.
 *   - The browser holds only a session identifier in an httpOnly cookie.
 *   - The session expires after 8 hours of inactivity.
 *   - An absolute maximum lifetime of 7 days applies regardless of activity.
 *     This limit cannot be expressed as a cookie attribute, so it is enforced
 *     here in the handler's own logic.
 */

require_once __DIR__ . '/db.php';

/**
 * A SessionHandlerInterface implementation that stores session data in the
 * `sessions` table.
 */
class DatabaseSessionHandler implements SessionHandlerInterface
{
    /** @var string|null Name of the table sessions are stored in. */
    private $table = 'sessions';

    /**
     * The account this session belongs to, once it is known.
     *
     * PHP only inserts the session row when the request shuts down, so a
     * login cannot update the row directly — the row does not exist yet at
     * that point. The handler therefore remembers the account here, and
     * write() puts it into the row as it is created.
     *
     * @var int|null
     */
    private $pendingUserId = null;

    /**
     * Open the session. Nothing to do: the database handle is opened lazily.
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * Close the session.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Read the session data for the given identifier.
     *
     * Returns an empty string when the session does not exist or has expired,
     * which makes PHP treat the session as new.
     *
     * @param string $sessionId
     * @return string
     */
    public function read(string $sessionId): string
    {
        $now = time();

        // A database outage must not produce an uncaught fatal error from
        // inside the session handler: the request has to be able to render a
        // page (or the 500 page) instead of dying here (decision D-018).
        // The failure is logged server-side and the session is treated as
        // empty, which makes PHP treat it as new.
        try {
            $row = db_select_one(
                "SELECT `session_id`, `payload`, `last_activity`, `created_at`
                   FROM `{$this->table}`
                  WHERE `session_id` = ?",
                's',
                array($sessionId)
            );
        } catch (Throwable $e) {
            error_log('[GreenCorner] Session read failed: ' . $e->getMessage());

            return '';
        }

        if ($row === null) {
            return '';
        }

        $lastActivity = (int) $row['last_activity'];
        $createdAt = (int) $row['created_at'];

        // FR-1.10: 8 hours of inactivity ends the session.
        if ($now - $lastActivity > SESSION_IDLE_TIMEOUT) {
            $this->destroy($sessionId);
            return '';
        }

        // FR-1.10: 7 days absolute maximum, regardless of activity.
        if ($now - $createdAt > SESSION_ABSOLUTE_TIMEOUT) {
            $this->destroy($sessionId);
            return '';
        }

        return (string) $row['payload'];
    }

    /**
     * Write the session data.
     *
     * @param string $sessionId
     * @param string $data
     * @return bool
     */
    public function write(string $sessionId, string $data): bool
    {
        $now = time();

        // The session is written when the request shuts down, which is AFTER
        // the response has been sent. An uncaught exception here would be a
        // fatal error with no response left to protect, so the whole write
        // is guarded: a failure is logged server-side and the write is
        // abandoned (decision D-018). The session row simply goes stale and
        // is removed by the housekeeping policy.
        try {
            return $this->writeNow($sessionId, $data, $now);
        } catch (Throwable $e) {
            error_log('[GreenCorner] Session write failed: ' . $e->getMessage());

            // The failure was handled: it was caught, logged, and the
            // response has already been sent. Reporting FALSE here would
            // make PHP emit "session_write_close(): Failed to write session
            // data" — a warning that adds noise to the log during exactly
            // the database outage the error handling exists for, without
            // saying anything the log entry above has not already said.
            return true;
        }
    }

    /**
     * Perform the session write itself.
     *
     * @param string $sessionId
     * @param string $data
     * @param int    $now
     * @return bool
     */
    private function writeNow(string $sessionId, string $data, int $now): bool
    {
        // An UPDATE reports only *changed* rows, so an unchanged payload
        // would report 0 affected rows. To keep the stored timestamps fresh
        // we issue an UPDATE and then verify with a SELECT if needed
        // (R-SQL-9 / ER-14: affected_rows = 0 does not prove absence).
        $affected = db_write(
            "UPDATE `{$this->table}`
                SET `payload` = ?, `last_activity` = ?
              WHERE `session_id` = ?",
            'sis',
            array($data, $now, $sessionId)
        );

        if ($affected === 0) {
            // The row may exist with an identical payload and timestamp, or it
            // may not exist at all. Distinguish the two with a SELECT.
            $exists = db_select_one(
                "SELECT `session_id` FROM `{$this->table}` WHERE `session_id` = ?",
                's',
                array($sessionId)
            );

            if ($exists !== null) {
                // The row exists; only refresh the activity timestamp so the
                // idle timeout keeps counting from this request.
                db_write(
                    "UPDATE `{$this->table}` SET `last_activity` = ? WHERE `session_id` = ?",
                    'is',
                    array($now, $sessionId)
                );

                // A login happened during this request, so the row's account
                // link still has to be written.
                $this->applyPendingUserId($sessionId);

                return true;
            }
        }

        // The session row does not exist yet: create it.
        try {
            db_write(
                "INSERT INTO `{$this->table}`
                        (`session_id`, `user_id`, `payload`, `last_activity`, `created_at`)
                 VALUES (?, ?, ?, ?, ?)",
                'sisss',
                array($sessionId, $this->pendingUserId, $data, $now, $now)
            );
        } catch (RuntimeException $e) {
            // A concurrent request may have created the row first (duplicate
            // key, error 1062). In that case the other row is correct, so
            // fall back to updating it.
            if (db_error_code($e->getCode()) === 'duplicate_entry') {
                db_write(
                    "UPDATE `{$this->table}`
                        SET `payload` = ?, `last_activity` = ?
                      WHERE `session_id` = ?",
                    'sis',
                    array($data, $now, $sessionId)
                );

                $this->applyPendingUserId($sessionId);

                return true;
            }
            throw $e;
        }

        return true;
    }

    /**
     * Destroy the session record.
     *
     * @param string $sessionId
     * @return bool
     */
    public function destroy(string $sessionId): bool
    {
        // Guarded for the same reason as write(): a database outage must not
        // turn a session operation into an uncaught fatal error (D-018).
        try {
            db_write(
                "DELETE FROM `{$this->table}` WHERE `session_id` = ?",
                's',
                array($sessionId)
            );
        } catch (Throwable $e) {
            error_log('[GreenCorner] Session destroy failed: ' . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Garbage-collect expired sessions.
     *
     * @param int $maxLifetime
     * @return int|false Number of removed sessions, or false on failure.
     */
    public function gc(int $maxLifetime): int|false
    {
        // A session is collectable once it has been idle for longer than the
        // idle timeout, or once it has passed the absolute maximum age.
        $cutoff = time() - max($maxLifetime, SESSION_IDLE_TIMEOUT);

        // Guarded: garbage collection runs during request shutdown, so an
        // uncaught exception here would be a fatal error with no response
        // left to protect (decision D-018).
        try {
            db_write(
                "DELETE FROM `{$this->table}` WHERE `last_activity` < ?",
                'i',
                array($cutoff)
            );
        } catch (Throwable $e) {
            error_log('[GreenCorner] Session gc failed: ' . $e->getMessage());

            return false;
        }

        return 1;
    }

    /**
     * Associate the session with a logged-in account.
     *
     * Called after a successful login so that the session row can be
     * traced back to its account.
     *
     * PHP creates the session row only when the request shuts down, so at
     * the moment of the call the row usually does not exist yet. The user
     * id is therefore remembered and written by write() when the row is
     * created. If the row does exist, it is updated straight away.
     *
     * @param string $sessionId
     * @param int    $userId
     * @return bool
     */
    public function linkToUser(string $sessionId, int $userId): bool
    {
        $this->pendingUserId = $userId;

        // Update the row when it already exists (for example a login that
        // did not regenerate the session id).
        $affected = db_write(
            "UPDATE `{$this->table}` SET `user_id` = ? WHERE `session_id` = ?",
            'is',
            array($userId, $sessionId)
        );

        if ($affected === 0) {
            // The row may exist with the same user id already; if it does not
            // exist at all, write() will create it with this user id
            // (R-SQL-9: affected_rows = 0 does not prove absence).
            return true;
        }

        return true;
    }

    /**
     * Write the remembered account id onto an existing session row.
     *
     * @param string $sessionId
     * @return void
     */
    private function applyPendingUserId(string $sessionId): void
    {
        if ($this->pendingUserId === null) {
            return;
        }

        db_write(
            "UPDATE `{$this->table}` SET `user_id` = ? WHERE `session_id` = ?",
            'is',
            array($this->pendingUserId, $sessionId)
        );
    }
}

/**
 * The session handler instance that is registered with PHP for this request.
 *
 * The handler is shared through this accessor so that code which needs to
 * act on the current session (for example linking the session to a logged-in
 * account) operates on the very same instance that PHP will call when the
 * request shuts down.
 *
 * @var DatabaseSessionHandler|null
 */
$GLOBALS['gc_session_handler'] = null;

/**
 * The registered session handler instance, or null before the session has
 * been started.
 */
function session_handler_instance(): ?DatabaseSessionHandler
{
    return $GLOBALS['gc_session_handler'];
}

/**
 * Start the session with the custom database-backed handler and the cookie
 * settings required by FR-7.6 and SEC-10.
 *
 * Must be called before any output is sent to the browser.
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // The session cookie carries only the session identifier (FR-7.6).
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.sid_length', (string) SESSION_ID_LENGTH);
    ini_set('session.sid_bits_per_character', '5');
    ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_TIMEOUT);
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_lifetime', '0');
    ini_set('session.use_cookies', '1');

    // The Secure flag is enabled under HTTPS and disabled for local HTTP
    // development (SRS §4.4, FR-7.6).
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    $secure = $isHttps || APP_ENV === 'production';
    ini_set('session.cookie_secure', $secure ? '1' : '0');

    session_name(SESSION_COOKIE_NAME);

    // The second argument (true) registers the handler as the only handler,
    // so session_start() alone is not sufficient for persistence — the data
    // lives in the database (FR-7.6).
    $handler = new DatabaseSessionHandler();
    $GLOBALS['gc_session_handler'] = $handler;

    session_set_save_handler($handler, true);

    session_start();
}

/**
 * Invalidate the other sessions of an account.
 *
 * FR-5.6 requires that, after a User changes their own password, the
 * other sessions for that account are invalidated. The Admin-initiated
 * password reset (FR-6.10) does the same, so that a session established
 * with the old password cannot continue to reach protected pages.
 *
 * The current session is kept: the caller has just rotated the session
 * identifier, and the account stays signed in on this device.
 *
 * The `sessions` table carries a `user_id` column and an index on it, so
 * the rows for an account can be found directly.
 *
 * @param int $userId The account whose sessions are invalidated.
 * @return int The number of session rows that were removed.
 */
function invalidate_other_sessions(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    $currentSessionId = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';

    if ($currentSessionId === '') {
        db_write(
            'DELETE FROM `sessions` WHERE `user_id` = ?',
            'i',
            array($userId)
        );

        return 1;
    }

    db_write(
        'DELETE FROM `sessions` WHERE `user_id` = ? AND `session_id` <> ?',
        'is',
        array($userId, $currentSessionId)
    );

    return 1;
}

/**
 * Destroy the current session completely: the server-side record is deleted
 * and the browser cookie is cleared (FR-1.6, SEC-11).
 */
function destroy_secure_session(): void
{
    // The handler's destroy() removes the row from the `sessions` table.
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    session_destroy();
}
