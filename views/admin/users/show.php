<?php
/**
 * GreenCorner — Admin User Detail (P-20)
 *
 * Implements:
 *   FR-6.10 the Admin can initiate a password reset by setting a temporary
 *           password; the account is then forced to change it at the next
 *           login; the Admin can never view an existing password
 *   FR-1.9  the forced-change flag blocks every protected page until the
 *           password has been changed
 *
 * The page deliberately offers **no** role control (FR-6.10, RBAC-9) and
 * shows **no** password and **no** hash (FR-6.10 acceptance criterion 3,
 * SEC-18).
 *
 * Expects:
 *   $adminUser            array<string, mixed>  The account row.
 *   $adminUserOrderCount  int                   The number of orders the
 *                                               account has placed.
 *   $adminUserErrors      array<string, string>  The field errors, if any.
 */

require_once __DIR__ . '/../../../helpers/functions.php';
require_once __DIR__ . '/../../../helpers/csrf.php';

$pageTitle = 'User ' . $adminUser['email'];
$adminSection = 'users';

$mustChange = (int) $adminUser['must_change_password'] === 1;
$isActive = (int) $adminUser['is_active'] === 1;

$passwordError = $adminUserErrors['temporary_password'] ?? '';
$confirmationError = $adminUserErrors['temporary_password_confirmation'] ?? '';

require __DIR__ . '/../../layouts/header.php';
?>

<?php require __DIR__ . '/../../layouts/nav.php'; ?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(APP_URL) ?>/admin/dashboard">Admin</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(APP_URL) ?>/admin/users">Users</a>
    <span aria-hidden="true">/</span>
    <span><?= e((string) $adminUser['email']) ?></span>
</nav>

<section class="admin-panel user-detail">
    <h1><?= e((string) $adminUser['full_name']) ?></h1>

    <dl class="order-summary-facts">
        <div>
            <dt>Name</dt>
            <dd><?= e((string) $adminUser['full_name']) ?></dd>
        </div>
        <div>
            <dt>Email</dt>
            <dd><?= e((string) $adminUser['email']) ?></dd>
        </div>
        <div>
            <dt>Registered</dt>
            <dd><?= e(date('j F Y, H:i', strtotime((string) $adminUser['created_at']))) ?></dd>
        </div>
        <div>
            <dt>Role</dt>
            <dd>
                <span class="badge"><?= e((string) $adminUser['role_name']) ?></span>
            </dd>
        </div>
        <div>
            <dt>Orders placed</dt>
            <dd><?= e((string) $adminUserOrderCount) ?></dd>
        </div>
        <div>
            <dt>Account status</dt>
            <dd>
                <?php if ($isActive): ?>
                    <span class="payment-status payment-status-success">Active</span>
                <?php else: ?>
                    <span class="payment-status payment-status-failed">Inactive</span>
                <?php endif; ?>
            </dd>
        </div>
        <div>
            <dt>Password</dt>
            <dd>
                <?php if ($mustChange): ?>
                    <span class="payment-status payment-status-pending">
                        A temporary password is in force &mdash; the account must change it at the next login
                    </span>
                <?php else: ?>
                    Set by the account holder
                <?php endif; ?>
            </dd>
        </div>
    </dl>

    <div class="alert alert-notice" role="status">
        The existing password can never be viewed or retrieved, here or
        anywhere else in the store. To let this person back in, set a
        temporary password and give it to them directly.
    </div>

    <h2>Set a temporary password</h2>

    <p class="panel-intro">
        The account will be required to change the temporary password the next
        time it signs in, before it can reach any other page. Its other
        sessions will be signed out at the same time.
    </p>

    <form method="post" action="<?= e(APP_URL) ?>/admin/users/<?= e((string) $adminUser['id']) ?>/reset-password" novalidate>
        <?= csrf_field() ?>

        <div class="field">
            <label for="temporary_password">Temporary password</label>
            <input
                type="text"
                id="temporary_password"
                name="temporary_password"
                autocomplete="off"
                spellcheck="false"
                required
            >
            <p class="field-hint">
                At least 8 characters. Write it down and give it to the account
                holder — it cannot be shown again.
            </p>
            <?php if ($passwordError !== ''): ?>
                <p class="field-error" role="alert"><?= e($passwordError) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="temporary_password_confirmation">Repeat the temporary password</label>
            <input
                type="text"
                id="temporary_password_confirmation"
                name="temporary_password_confirmation"
                autocomplete="off"
                spellcheck="false"
                required
            >
            <?php if ($confirmationError !== ''): ?>
                <p class="field-error" role="alert"><?= e($confirmationError) ?></p>
            <?php endif; ?>
        </div>

        <button type="submit" class="button">Set the temporary password</button>
    </form>

    <div class="order-confirmation-actions">
        <a class="button button-secondary" href="<?= e(APP_URL) ?>/admin/users">Back to all users</a>
    </div>
</section>

<?php require __DIR__ . '/../../layouts/footer.php'; ?>
