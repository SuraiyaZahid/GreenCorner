<?php
/**
 * GreenCorner — Login Attempt Tracking
 *
 * Implements NFR-S-06 and SEC-13: login attempts are rate-limited per source
 * address and, where practical, per account.
 *
 * The counters live in the database (decision D-005). The JSON-file store
 * that Phase 1 used has been removed: a database-backed counter is shared by
 * every application server and does not depend on a file lock.
 *
 * The public interface is unchanged from Phase 1, so the controller needed no
 * changes:
 *   LoginAttempt::client_ip()
 *   LoginAttempt::isLockedOut($ip)
 *   LoginAttempt::attemptsRemaining($ip)
 *   LoginAttempt::recordFailure($ip, $email)
 *   LoginAttempt::clearFailures($ip)
 *
 * The policy is unchanged: LOGIN_MAX_ATTEMPTS failed attempts from one source
 * address within LOGIN_LOCKOUT_SECONDS produce a lockout.
 *
 * The source address and the account's email address are stored only as
 * SHA-256 hashes. The raw values are personal data (SEC-7) and never reach
 * the database.
 */

require_once __DIR__ . '/../helpers/db.php';

class LoginAttempt
{
    /**
     * The source address of the current request.
     *
     * REMOTE_ADDR is used and nothing else. Proxy headers are deliberately
     * ignored: they are supplied by the client and would let an attacker
     * choose their own identity for the rate limiter.
     */
    public static function client_ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }

    /**
     * Hash an address or an email address for storage.
     *
     * @param string $value
     * @return string A 64-character hexadecimal SHA-256 digest.
     */
    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * The start of the current lockout window, as a Unix timestamp.
     */
    private static function window_start(): int
    {
        return time() - LOGIN_LOCKOUT_SECONDS;
    }

    /**
     * Record a failed login attempt for the current source address.
     *
     * @param string $ip     The source address.
     * @param string $email  The email address that was attempted (optional).
     * @return void
     */
    public static function recordFailure(string $ip, string $email = ''): void
    {
        $emailHash = null;

        if ($email !== '') {
            $emailHash = self::hash(strtolower($email));
        }

        db_insert_id(
            'INSERT INTO `login_attempts` (`ip_hash`, `email_hash`, `created_at`) VALUES (?, ?, ?)',
            'ssi',
            array(self::hash($ip), $emailHash, time())
        );
    }

    /**
     * Clear the failed attempts for a source address (called on success).
     *
     * @param string $ip
     * @return void
     */
    public static function clearFailures(string $ip): void
    {
        db_write(
            'DELETE FROM `login_attempts` WHERE `ip_hash` = ?',
            's',
            array(self::hash($ip))
        );
    }

    /**
     * How many failed attempts the source address has made inside the
     * current lockout window.
     *
     * @param string $ip
     * @return int
     */
    public static function recentFailureCount(string $ip): int
    {
        $row = db_select_one(
            'SELECT COUNT(*) AS `total` FROM `login_attempts` WHERE `ip_hash` = ? AND `created_at` >= ?',
            'si',
            array(self::hash($ip), self::window_start())
        );

        return $row === null ? 0 : (int) $row['total'];
    }

    /**
     * Whether the source address has exceeded the attempt limit.
     *
     * @param string $ip
     * @return bool
     */
    public static function isLockedOut(string $ip): bool
    {
        return self::recentFailureCount($ip) >= LOGIN_MAX_ATTEMPTS;
    }

    /**
     * How many attempts remain before the source address is locked out.
     *
     * @param string $ip
     * @return int
     */
    public static function attemptsRemaining(string $ip): int
    {
        return max(0, LOGIN_MAX_ATTEMPTS - self::recentFailureCount($ip));
    }

    /**
     * Delete attempts that have fallen out of the lockout window.
     *
     * Rows outside the window cannot influence the limit, so they are only
     * housekeeping. This is called opportunistically when a failure is
     * recorded, so no scheduled job is needed.
     *
     * @return void
     */
    public static function pruneExpired(): void
    {
        db_write(
            'DELETE FROM `login_attempts` WHERE `created_at` < ?',
            'i',
            array(self::window_start())
        );
    }
}
