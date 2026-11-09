<?php
/**
 * GreenCorner — Shared page header
 *
 * Expects these variables from the controller:
 *   $pageTitle  string  The title shown in the browser tab.
 *
 * Also uses the flash message helper and the current user's role to render
 * the navigation bar correctly (FR-7.5: no cart badge for an Admin).
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';
require_once dirname(__DIR__, 2) . '/helpers/csrf.php';
require_once dirname(__DIR__, 2) . '/helpers/authorization.php';
require_once dirname(__DIR__, 2) . '/helpers/security_headers.php';

$pageTitle = $pageTitle ?? APP_NAME;
$flash = get_flash();

// Phase 6 test support: the fault-injection controller can make this layout
// fail while it renders, so that the static fallback in public/index.php —
// which is what is left when even the branded 500 page cannot be rendered —
// can be exercised over HTTP. The controller refuses to act in production
// and for any account that is not an Admin (decision D-018).
if (layout_render_fault_active()) {
    throw new RuntimeException('Injected layout render fault for the Phase 6 test suite.');
}

// The account is loaded defensively: a database outage must not blank every
// page that includes this layout (decision D-018). safe_current_user() never
// throws and re-logs the failure server-side (NFR-R-05, D-011).
$currentUser = safe_current_user();
$currentRole = $currentUser === null ? null : (string) $currentUser['role_name'];

// FR-7.5: the cart count is not rendered in the navigation for an Admin.
$cartCount = 0;
if ($currentRole === 'User' && $currentUser !== null) {
    require_once dirname(__DIR__, 2) . '/models/Cart.php';
    try {
        $cartCount = Cart::countItems((int) $currentUser['id']);
    } catch (Throwable $e) {
        // The badge is cosmetic, so a failure only costs the count. The
        // detail is logged server-side and never rendered (NFR-R-05, D-011).
        error_log('[GreenCorner] Cart::countItems() failed: ' . $e->getMessage());
        $cartCount = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> &middot; <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/style.css">
    <?php // The background requests need the application's base URL. This is
          // the ONLY inline script in the application, so it is the only
          // element that needs the Content Security Policy nonce (D-018). ?>
    <script nonce="<?= e(csp_nonce()) ?>">window.GreenCornerBaseUrl = <?= json_encode(APP_URL) ?>;</script>
</head>
<body<?= isset($bodyClass) && $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
<a class="skip-link" href="#main">Skip to main content</a>

<header class="site-header">
    <div class="container header-inner">
        <a class="logo" href="<?= e(APP_URL) ?>/">
            <span class="logo-mark" aria-hidden="true">🌿</span>
            <span class="logo-text"><?= e(APP_NAME) ?></span>
        </a>

        <nav class="site-nav" aria-label="Main navigation">
            <?php if ($currentUser === null): ?>
                <a href="<?= e(APP_URL) ?>/auth/login">Sign in</a>
                <a class="button button-small" href="<?= e(APP_URL) ?>/auth/register">Create account</a>
            <?php else: ?>
                <?php if ($currentRole === 'User'): ?>
                    <a href="<?= e(APP_URL) ?>/dashboard">Dashboard</a>
                    <a href="<?= e(APP_URL) ?>/orders">Orders</a>
                    <a href="<?= e(APP_URL) ?>/cart" class="cart-link">
                        Cart
                        <span class="cart-count" id="cart-count"><?= e((string) $cartCount) ?></span>
                    </a>
                    <?php // P-13: the Profile page is a User-only resource. ?>
                    <a href="<?= e(APP_URL) ?>/profile">Profile</a>
                <?php else: ?>
                    <a href="<?= e(APP_URL) ?>/admin/dashboard">Admin dashboard</a>
                <?php endif; ?>

                <form class="logout-form" method="post" action="<?= e(APP_URL) ?>/auth/logout">
                    <?= csrf_field() ?>
                    <button type="submit" class="button button-small button-quiet">Sign out</button>
                </form>
            <?php endif; ?>
        </nav>
    </div>
</header>

<?php if ($flash !== null): ?>
    <div class="container">
        <div class="alert alert-<?= e($flash['type']) ?>" role="status">
            <?= e($flash['message']) ?>
        </div>
    </div>
<?php endif; ?>

<main id="main" class="container page-main">
