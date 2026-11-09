<?php
/**
 * GreenCorner — Forced Change Password page (P-04)
 *
 * Implements FR-1.9: shown when a temporary password must be changed, and it
 * blocks navigation to every other protected page until the change is made.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';
require_once dirname(__DIR__, 2) . '/helpers/csrf.php';

$pageTitle = 'Change your password';

$errors = $_SESSION['password_errors'] ?? array();
unset($_SESSION['password_errors']);

// The CSRF error, when the form is re-rendered after a rejected token
// (decision D-004).
$csrfError = isset($_SESSION['csrf_error']) && is_string($_SESSION['csrf_error'])
    ? $_SESSION['csrf_error']
    : '';
unset($_SESSION['csrf_error']);

require __DIR__ . '/../layouts/header.php';
?>

<section class="form-panel">
    <h1>Change your password</h1>
    <p class="panel-intro">
        Your password must be changed before you can continue. Please choose a
        new password of at least 8 characters.
    </p>

    <?php if ($csrfError !== ''): ?>
        <div class="alert alert-error" role="alert">
            <?= e($csrfError) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(APP_URL) ?>/auth/change-password" novalidate>
        <?= csrf_field() ?>

        <div class="field">
            <label for="current_password">Current password</label>
            <input
                type="password"
                id="current_password"
                name="current_password"
                autocomplete="current-password"
                required
                <?= isset($errors['current_password']) ? 'aria-invalid="true"' : '' ?>
            >
            <?php if (isset($errors['current_password'])): ?>
                <p class="field-error" role="alert"><?= e($errors['current_password']) ?></p>
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
                <?= isset($errors['new_password']) ? 'aria-invalid="true"' : '' ?>
            >
            <p class="field-hint">Use at least 8 characters.</p>
            <?php if (isset($errors['new_password'])): ?>
                <p class="field-error" role="alert"><?= e($errors['new_password']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="new_password_confirmation">Confirm new password</label>
            <input
                type="password"
                id="new_password_confirmation"
                name="new_password_confirmation"
                autocomplete="new-password"
                <?= isset($errors['new_password_confirmation']) ? 'aria-invalid="true"' : '' ?>
            >
            <?php if (isset($errors['new_password_confirmation'])): ?>
                <p class="field-error" role="alert"><?= e($errors['new_password_confirmation']) ?></p>
            <?php endif; ?>
        </div>

        <button type="submit" class="button">Save new password</button>
    </form>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
