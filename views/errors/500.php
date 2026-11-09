<?php
/**
 * GreenCorner — 500 Server Error page (P-22)
 *
 * Implements §15.3 and NFR-R-05. The message is generic: the underlying
 * exception is logged server-side and is never rendered to the client.
 */

require_once __DIR__ . '/../../helpers/functions.php';

$pageTitle = $pageTitle ?? 'Something went wrong';

require __DIR__ . '/../layouts/header.php';
?>

<section class="error-panel">
    <p class="error-code">500</p>
    <h1>Something went wrong</h1>
    <p>Please try again. If the problem continues, contact the store.</p>
    <p>
        <a class="button" href="<?= e(APP_URL) ?>/">Go to the home page</a>
    </p>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
