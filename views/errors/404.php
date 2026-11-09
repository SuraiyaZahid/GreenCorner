<?php
/**
 * GreenCorner — 404 Not Found page (P-07)
 *
 * Implements FR-2.13 and §15.1. The same page and status serve the
 * "Product no longer available" response of FR-2.8, with the wording
 * adapted to that case.
 *
 * No stack trace, SQL fragment or file path is disclosed.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

$pageTitle = $pageTitle ?? 'Page not found';

require __DIR__ . '/../layouts/header.php';
?>

<section class="error-panel">
    <p class="error-code">404</p>
    <h1><?= e($pageTitle) ?></h1>
    <p><?= e($message ?? 'We could not find the page you were looking for.') ?></p>
    <p>
        <?php // FR-2.13 and FR-2.8: a path back to the catalogue. ?>
        <a class="button" href="<?= e(APP_URL) ?>/products">Browse other products</a>
        <a class="button button-secondary" href="<?= e(APP_URL) ?>/">Go to the home page</a>
    </p>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
