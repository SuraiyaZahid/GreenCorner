<?php
/**
 * GreenCorner — Admin User Management Model
 *
 * Data access for the Admin User List (P-19) and the Admin User Detail page
 * (P-20).
 *
 * Implements:
 *   FR-6.10 the list shows every account with its name, email and
 *           registration date; the Admin can set a temporary password that
 *           forces a change at the next login
 *   SEC-18  the Admin interface provides no means to retrieve an existing
 *           password or hash
 *
 * The rule that shapes this whole model: **no query in this file selects
 * `password_hash`.** The column is never read, never returned and never
 * echoed, so there is no code path by which an Admin — or a view, or a log
 * entry — could ever obtain it (FR-6.10 acceptance criterion 3, SEC-18).
 *
 * The only password operation this model performs is a *write*: it stores a
 * new hash and sets the forced-change flag.
 */

require_once __DIR__ . '/../helpers/db.php';

class UserAdmin
{
    /**
     * The number of accounts on one page of the Admin User List.
     *
     * The same page size as the Admin product list and the Admin order
     * list, so the Admin panel is consistent.
     */
    const PER_PAGE = 20;

    /**
     * The columns the Admin User List and the Admin User Detail page read.
     *
     * `password_hash` is deliberately absent.
     *
     * @var array<int, string>
     */
    private const LIST_COLUMNS = 'u.`id`, u.`full_name`, u.`email`, u.`role_id`,
            u.`is_active`, u.`must_change_password`, u.`created_at`,
            r.`name` AS `role_name`';

    /**
     * Find one account by id, without its password hash.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return db_select_one(
            'SELECT ' . self::LIST_COLUMNS . '
               FROM `users` u
               JOIN `roles` r ON r.`id` = u.`role_id`
              WHERE u.`id` = ?
              LIMIT 1',
            'i',
            array($id)
        );
    }

    /**
     * One page of the Admin User List, oldest registration first.
     *
     * The ordering is stable and includes the id, so two accounts registered
     * in the same second never swap places between pages.
     *
     * @param int $limit
     * @param int $offset
     * @return array<int, array<string, mixed>>
     */
    public static function listAll(int $limit, int $offset): array
    {
        return db_select(
            'SELECT ' . self::LIST_COLUMNS . '
               FROM `users` u
               JOIN `roles` r ON r.`id` = u.`role_id`
              ORDER BY u.`created_at` ASC, u.`id` ASC
              LIMIT ? OFFSET ?',
            'ii',
            array($limit, $offset)
        );
    }

    /**
     * The number of registered accounts.
     *
     * @return int
     */
    public static function countAll(): int
    {
        $row = db_select_one('SELECT COUNT(*) AS `total` FROM `users`');

        return (int) ($row['total'] ?? 0);
    }

    /**
     * The number of pages of the Admin User List.
     *
     * @param int $totalResults
     * @param int $perPage
     * @return int At least 1, so an empty list still has a valid page 1.
     */
    public static function pageCount(int $totalResults, int $perPage): int
    {
        if ($perPage <= 0) {
            return 1;
        }

        return (int) max(1, ceil($totalResults / $perPage));
    }

    /**
     * The number of orders the account has placed.
     *
     * The Admin User Detail page shows it, so the Admin can see whether the
     * account has any history before resetting its password.
     *
     * @param int $userId
     * @return int
     */
    public static function orderCount(int $userId): int
    {
        $row = db_select_one(
            'SELECT COUNT(*) AS `total` FROM `orders` WHERE `user_id` = ?',
            'i',
            array($userId)
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Set a temporary password on an account and force a change at the next
     * login (FR-6.10).
     *
     * The temporary password is stored **only as a hash** (FR-6.10 acceptance
     * criterion 4), and the forced-change flag is set at the same time
     * (FR-1.9), so the User cannot reach any protected page until the
     * password has been changed.
     *
     * The plaintext is used once, here, to produce the hash. It is never
     * stored, never returned and never logged (FR-1.3, FR-6.9's "no
     * password material in the logs" rule, SEC-18).
     *
     * @param int    $userId
     * @param string $temporaryPassword The plaintext, supplied by the Admin.
     * @return void
     */
    public static function setTemporaryPassword(int $userId, string $temporaryPassword): void
    {
        $hash = password_hash($temporaryPassword, PASSWORD_DEFAULT);

        if ($hash === false) {
            throw new RuntimeException('Could not hash the password.');
        }

        // `must_change_password` is set to 1, which is what forces the User
        // to the change-password page at the next login (FR-1.9).
        db_write(
            'UPDATE `users` SET `password_hash` = ?, `must_change_password` = 1 WHERE `id` = ?',
            'si',
            array($hash, $userId)
        );
    }
}
