<?php
/**
 * GreenCorner — Profile page (P-13)
 *
 * Implements:
 *   FR-5.5  the account's own details, and the form that changes the
 *           account's full name
 *   FR-5.6  the form that changes the account's password, which requires
 *           the current password
 *
 * The page shows the account's name and email address. **Only the name is
 * editable**: FR-5.5 forbids a User from changing their own role, email
 * address or account status through this interface, so no such control is
 * offered here, and the controller never reads such a field (RBAC-9).
 *
 * Expects:
 *   $profileUser   array<string, mixed>  The signed-in account.
 *   $profileErrors array<string, string>  The field errors, if any.
 *   $profileName   string                The name shown in the form.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';
require_once dirname(__DIR__, 2) . '/helpers/csrf.php';

$pageTitle = 'Your profile';

$profileUser = $profileUser ?? current_user();
$profileErrors = $profileErrors ?? array();
$profileName = $profileName ?? (string) $profileUser['full_name'];

// The CSRF error, when the form is re-rendered after a rejected token
// (decision D-004).
$csrfError = isset($_SESSION['csrf_error']) && is_string($_SESSION['csrf_error'])
    ? $_SESSION['csrf_error']
    : '';
unset($_SESSION['csrf_error']);

$nameError = $profileErrors['full_name'] ?? '';
$currentPasswordError = $profileErrors['current_password'] ?? '';
$newPasswordError = $profileErrors['new_password'] ?? '';
$confirmPasswordError = $profileErrors['new_password_confirmation'] ?? '';

// A field error that belongs to neither form is the CSRF error.
if ($nameError === '' && $newPasswordError === '' && $currentPasswordError === '' && $confirmPasswordError === '' && $csrfError !== '') {
    $nameError = $csrfError;
}

require __DIR__ . '/../layouts/header.php';
?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(APP_URL) ?>/">Home</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(APP_URL) ?>/dashboard">Dashboard</a>
    <span aria-hidden="true">/</span>
    <span>Profile</span>
</nav>

<section class="form-panel">
    <h1>Your profile</h1>

    <dl class="profile-facts">
        <div>
            <dt>Email address</dt>
            <dd><?= e((string) $profileUser['email']) ?></dd>
        </div>
        <div>
            <dt>Member since</dt>
            <dd><?= e(date('j F Y', strtotime((string) $profileUser['created_at']))) ?></dd>
        </div>
    </dl>

    <p class="panel-intro">
        Your email address and account status are managed by the store, so they
        cannot be changed here.
    </p>

    <h2>Change your name</h2>

    <form method="post" action="<?= e(APP_URL) ?>/profile/name" novalidate>
        <?= csrf_field() ?>

        <div class="field">
            <label for="full_name">Full name</label>
            <input
                type="text"
                id="full_name"
                name="full_name"
                maxlength="120"
                autocomplete="name"
                value="<?= e($profileName) ?>"
                required
                <?= $nameError !== '' ? 'aria-invalid="true"' : '' ?>
            >
            <?php if ($nameError !== ''): ?>
                <p class="field-error" role="alert"><?= e($nameError) ?></p>
            <?php endif; ?>
        </div>

        <button type="submit" class="button">Save name</button>
    </form>

    <h2>Change your password</h2>

    <p class="panel-intro">
        You need your current password to choose a new one. When you change your
        password, this device stays signed in and your other devices are signed
        out.
    </p>

    <form method="post" action="<?= e(APP_URL) ?>/profile/password" novalidate>
        <?= csrf_field() ?>

        <div class="field">
            <label for="current_password">Current password</label>
            <input
                type="password"
                id="current_password"
                name="current_password"
                autocomplete="current-password"
                required
                <?= $currentPasswordError !== '' ? 'aria-invalid="true"' : '' ?>
            >
            <?php if ($currentPasswordError !== ''): ?>
                <p class="field-error" role="alert"><?= e($currentPasswordError) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="new_password">New password</label>
            <input
                type="password"
                id="new_password"
                name="new_password"
                minlength="8"
                autocomplete="new-password"
                required
                <?= $newPasswordError !== '' ? 'aria-invalid="true"' : '' ?>
            >
            <p class="field-hint">Use at least 8 characters.</p>
            <?php if ($newPasswordError !== ''): ?>
                <p class="field-error" role="alert"><?= e($newPasswordError) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="new_password_confirmation">Confirm new password</label>
            <input
                type="password"
                id="new_password_confirmation"
                name="new_password_confirmation"
                autocomplete="new-password"
                required
                <?= $confirmPasswordError !== '' ? 'aria-invalid="true"' : '' ?>
            >
            <?php if ($confirmPasswordError !== ''): ?>
                <p class="field-error" role="alert"><?= e($confirmPasswordError) ?></p>
            <?php endif; ?>
        </div>

        <button type="submit" class="button">Change password</button>
    </form>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
