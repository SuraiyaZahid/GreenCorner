<?php
/**
 * GreenCorner — Login page (P-03)
 *
 * Implements FR-1.5. The failure message is generic and never reveals
 * whether the email is registered (SEC-12).
 *
 * A failed sign-in re-renders this page with the error shown inline (D-003).
 * The email the visitor typed is preserved; the password field is always
 * empty.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';
require_once dirname(__DIR__, 2) . '/helpers/csrf.php';

$pageTitle = 'Sign in';

// The inline error, when the form is re-rendered after a failed attempt.
$loginError = isset($_SESSION['login_error']) && is_string($_SESSION['login_error'])
    ? $_SESSION['login_error']
    : '';

// The CSRF error, when the form is re-rendered after a rejected token
// (decision D-004).
$csrfError = isset($_SESSION['csrf_error']) && is_string($_SESSION['csrf_error'])
    ? $_SESSION['csrf_error']
    : '';

// The email the visitor typed, preserved so they do not have to type it
// again. The password is never preserved.
$loginEmail = isset($_SESSION['login_email']) && is_string($_SESSION['login_email'])
    ? $_SESSION['login_email']
    : '';

unset($_SESSION['login_error'], $_SESSION['login_email'], $_SESSION['csrf_error']);

require __DIR__ . '/../layouts/header.php';
?>

<section class="form-panel">
    <h1>Sign in</h1>
    <p class="panel-intro">Welcome back. Sign in to continue shopping.</p>

    <?php if ($loginError !== ''): ?>
        <div class="alert alert-error" role="alert">
            <?= e($loginError) ?>
        </div>
    <?php endif; ?>

    <?php if ($csrfError !== ''): ?>
        <div class="alert alert-error" role="alert">
            <?= e($csrfError) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(APP_URL) ?>/auth/login" novalidate>
        <?= csrf_field() ?>

        <div class="field">
            <label for="email">Email address</label>
            <input
                type="email"
                id="email"
                name="email"
                value="<?= e($loginEmail) ?>"
                maxlength="190"
                autocomplete="email"
                required
                autofocus
            >
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                value=""
                autocomplete="current-password"
                required
            >
        </div>

        <button type="submit" class="button">Sign in</button>
    </form>

    <p class="panel-footnote">
        New to <?= e(APP_NAME) ?>?
        <a href="<?= e(APP_URL) ?>/auth/register">Create an account</a>.
    </p>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
