<?php
/**
 * GreenCorner — Registration page (P-02)
 *
 * Implements FR-1.1 … FR-1.4 and ER-1: field-level validation errors are
 * rendered inline on failure.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';
require_once dirname(__DIR__, 2) . '/helpers/csrf.php';

$pageTitle = 'Create your account';

$errors = $_SESSION['register_errors'] ?? array();
$old = $_SESSION['register_old'] ?? array('full_name' => '', 'email' => '');
unset($_SESSION['register_errors'], $_SESSION['register_old']);

// The CSRF error, when the form is re-rendered after a rejected token
// (decision D-004).
$csrfError = isset($_SESSION['csrf_error']) && is_string($_SESSION['csrf_error'])
    ? $_SESSION['csrf_error']
    : '';
unset($_SESSION['csrf_error']);

require __DIR__ . '/../layouts/header.php';
?>

<section class="form-panel">
    <h1>Create your account</h1>
    <p class="panel-intro">
        Register to start shopping. New accounts always receive the
        <strong>User</strong> role.
    </p>

    <?php if ($csrfError !== ''): ?>
        <div class="alert alert-error" role="alert">
            <?= e($csrfError) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(APP_URL) ?>/auth/register" novalidate>
        <?= csrf_field() ?>

        <div class="field">
            <label for="full_name">Full name</label>
            <input
                type="text"
                id="full_name"
                name="full_name"
                value="<?= e($old['full_name'] ?? '') ?>"
                maxlength="120"
                autocomplete="name"
                required
                <?= isset($errors['full_name']) ? 'aria-invalid="true"' : '' ?>
            >
            <?php if (isset($errors['full_name'])): ?>
                <p class="field-error" role="alert"><?= e($errors['full_name']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="email">Email address</label>
            <input
                type="email"
                id="email"
                name="email"
                value="<?= e($old['email'] ?? '') ?>"
                maxlength="190"
                autocomplete="email"
                required
                <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>
            >
            <?php if (isset($errors['email'])): ?>
                <p class="field-error" role="alert"><?= e($errors['email']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                minlength="8"
                autocomplete="new-password"
                required
                <?= isset($errors['password']) ? 'aria-invalid="true"' : '' ?>
            >
            <p class="field-hint">Use at least 8 characters.</p>
            <?php if (isset($errors['password'])): ?>
                <p class="field-error" role="alert"><?= e($errors['password']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="password_confirmation">Confirm password</label>
            <input
                type="password"
                id="password_confirmation"
                name="password_confirmation"
                autocomplete="new-password"
                <?= isset($errors['password_confirmation']) ? 'aria-invalid="true"' : '' ?>
            >
            <?php if (isset($errors['password_confirmation'])): ?>
                <p class="field-error" role="alert"><?= e($errors['password_confirmation']) ?></p>
            <?php endif; ?>
        </div>

        <button type="submit" class="button">Create account</button>
    </form>

    <p class="panel-footnote">
        Already have an account?
        <a href="<?= e(APP_URL) ?>/auth/login">Sign in</a>.
    </p>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
