<?php
/**
 * GreenCorner — 403 Access Denied page (P-21)
 *
 * Implements FR-7.4, FR-7.5 and §15.2. The page states only that the
 * request was refused and which resources the account's role permits. No
 * data from the attempted resource is included.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

$pageTitle = $pageTitle ?? 'Access denied';

$groupLabels = array(
    'browse'   => 'browse the catalogue',
    'shopping' => 'use the cart, checkout and order history',
    'admin'    => 'manage products, categories, orders and users',
);

$allowedText = array();
foreach ($allowed as $group) {
    if (isset($groupLabels[$group])) {
        $allowedText[] = $groupLabels[$group];
    }
}

require __DIR__ . '/../layouts/header.php';
?>

<section class="error-panel">
    <p class="error-code">403</p>
    <h1>Access denied</h1>
    <p>Your account does not have permission to open that page.</p>

    <?php if ($allowedText !== array()): ?>
        <p>
            As a <strong><?= e($role ?? 'guest') ?></strong> you may
            <?= e(implode(', ', $allowedText)) ?>.
        </p>
    <?php endif; ?>

    <p>
        <a class="button" href="<?= e(APP_URL) ?>/">Go to the home page</a>
    </p>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
