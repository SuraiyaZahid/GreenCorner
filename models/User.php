<?php
/**
 * GreenCorner — User Model
 *
 * Data access for the `users` and `roles` tables.
 *
 * Implements the persistence half of:
 *   FR-1.1  registration creates an active user with the User role
 *   FR-1.2  the email is unique
 *   FR-1.3  passwords are stored only as salted hashes
 *   FR-1.4  every registration receives the User role
 *   FR-1.5  login compares against the stored hash
 *   FR-1.9  the forced-change flag
 *   FR-7.3  the current role and status are re-read per request
 */

require_once __DIR__ . '/../helpers/db.php';

class User
{
    /**
     * The minimum password length required by FR-1.3.
     */
    const MIN_PASSWORD_LENGTH = 8;

    /**
     * The maximum length of a full name (matches users.full_name).
     */
    const MAX_NAME_LENGTH = 120;

    /**
     * The maximum length of an email address (matches users.email).
     */
    const MAX_EMAIL_LENGTH = 190;

    /**
     * Find a user by email address, including the role name.
     *
     * @param string $email
     * @return array<string, mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        return db_select_one(
            "SELECT u.`id`, u.`full_name`, u.`email`, u.`password_hash`,
                    u.`role_id`, u.`is_active`, u.`must_change_password`,
                    u.`created_at`, r.`name` AS `role_name`
               FROM `users` u
               JOIN `roles` r ON r.`id` = u.`role_id`
              WHERE u.`email` = ?
              LIMIT 1",
            's',
            array($email)
        );
    }

    /**
     * Find a user by primary key, including the role name.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        return db_select_one(
            "SELECT u.`id`, u.`full_name`, u.`email`, u.`password_hash`,
                    u.`role_id`, u.`is_active`, u.`must_change_password`,
                    u.`created_at`, r.`name` AS `role_name`
               FROM `users` u
               JOIN `roles` r ON r.`id` = u.`role_id`
              WHERE u.`id` = ?
              LIMIT 1",
            'i',
            array($id)
        );
    }

    /**
     * Find the id of a role by name.
     *
     * @param string $name
     * @return int|null
     */
    public static function roleId(string $name): ?int
    {
        $row = db_select_one(
            'SELECT `id` FROM `roles` WHERE `name` = ? LIMIT 1',
            's',
            array($name)
        );

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Check whether an email address is already registered.
     *
     * @param string $email
     * @return bool
     */
    public static function emailExists(string $email): bool
    {
        $row = db_select_one(
            'SELECT `id` FROM `users` WHERE `email` = ? LIMIT 1',
            's',
            array($email)
        );

        return $row !== null;
    }

    /**
     * Create a user account.
     *
     * The role is always supplied by the caller from a server-side lookup —
     * never from the request body — so that a registration request carrying
     * `role_id` or `role = Admin` still produces a User-role account
     * (FR-1.4, RBAC-8).
     *
     * @param string $fullName
     * @param string $email
     * @param string $plainPassword
     * @param int    $roleId
     * @return int The new user's id.
     */
    public static function create(string $fullName, string $email, string $plainPassword, int $roleId): int
    {
        // FR-1.3 / NFR-S-02: a salted hash only. The plaintext is never
        // stored, logged or returned.
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

        if ($hash === false) {
            throw new RuntimeException('Could not hash the password.');
        }

        return db_insert_id(
            "INSERT INTO `users`
                    (`full_name`, `email`, `password_hash`, `role_id`, `is_active`, `must_change_password`)
             VALUES (?, ?, ?, ?, 1, 0)",
            'sssi',
            array($fullName, $email, $hash, $roleId)
        );
    }

    /**
     * Verify a password against the stored hash.
     *
     * @param string $plainPassword
     * @param string $storedHash
     * @return bool
     */
    public static function verifyPassword(string $plainPassword, string $storedHash): bool
    {
        return password_verify($plainPassword, $storedHash);
    }

    /**
     * Whether the stored hash needs re-hashing with the current algorithm.
     *
     * @param string $storedHash
     * @return bool
     */
    public static function passwordNeedsRehash(string $storedHash): bool
    {
        return password_needs_rehash($storedHash, PASSWORD_DEFAULT);
    }

    /**
     * Set a new password for a user and optionally require a change at the
     * next login.
     *
     * Used by the forced change password page (FR-1.9) and, later, by the
     * Admin-initiated reset (FR-6.10).
     *
     * @param int    $userId
     * @param string $plainPassword
     * @param bool   $mustChange
     * @return void
     */
    public static function setPassword(int $userId, string $plainPassword, bool $mustChange): void
    {
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

        if ($hash === false) {
            throw new RuntimeException('Could not hash the password.');
        }

        db_write(
            "UPDATE `users`
                SET `password_hash` = ?, `must_change_password` = ?
              WHERE `id` = ?",
            'sii',
            array($hash, $mustChange ? 1 : 0, $userId)
        );
    }

    /**
     * Update a user's full name.
     *
     * @param int    $userId
     * @param string $fullName
     * @return void
     */
    public static function updateName(int $userId, string $fullName): void
    {
        db_write(
            'UPDATE `users` SET `full_name` = ? WHERE `id` = ?',
            'si',
            array($fullName, $userId)
        );
    }

    /**
     * Count the registered accounts.
     *
     * @return int
     */
    public static function count(): int
    {
        $row = db_select_one('SELECT COUNT(*) AS `total` FROM `users`');

        return $row === null ? 0 : (int) $row['total'];
    }

    /**
     * Validate the registration fields.
     *
     * Implements ER-1: the name is required and within length, the email is
     * required and well-formed, and the password is at least 8 characters.
     *
     * @param string $fullName
     * @param string $email
     * @param string $password
     * @param string $confirmPassword
     * @return array<string, string> Field name => error message. Empty when valid.
     */
    public static function validateRegistration(
        string $fullName,
        string $email,
        string $password,
        string $confirmPassword
    ): array {
        $errors = array();

        // Full name
        if ($fullName === '') {
            $errors['full_name'] = 'Please enter your full name.';
        } elseif (mb_strlen($fullName) > self::MAX_NAME_LENGTH) {
            $errors['full_name'] = 'Your name may be at most ' . self::MAX_NAME_LENGTH . ' characters long.';
        }

        // Email
        if ($email === '') {
            $errors['email'] = 'Please enter your email address.';
        } elseif (mb_strlen($email) > self::MAX_EMAIL_LENGTH) {
            $errors['email'] = 'Your email address is too long.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        // Password
        if ($password === '') {
            $errors['password'] = 'Please choose a password.';
        } elseif (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors['password'] = 'Your password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters long.';
        }

        // Confirmation
        if ($confirmPassword !== '' && $password !== $confirmPassword) {
            $errors['password_confirmation'] = 'The two passwords do not match.';
        }

        return $errors;
    }
}
