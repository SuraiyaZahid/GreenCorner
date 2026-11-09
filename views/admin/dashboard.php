<?php
/**
 * GreenCorner — Admin Dashboard (P-14)
 *
 * Implements FR-6.1:
 *   The dashboard displays counts of products, orders and users. The product
 *   count excludes soft-deleted products.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

$pageTitle = 'Admin dashboard';
$adminSection = 'dashboard';

require __DIR__ . '/../layouts/header.php';
?>

<?php require __DIR__ . '/../layouts/nav.php'; ?>

<section class="dashboard">
    <h1>Admin dashboard</h1>
    <p class="panel-intro">
        Manage the catalogue and categories from here.
    </p>

    <ul class="admin-stats">
        <li class="admin-stat">
            <span class="admin-stat-label">Products</span>
            <span class="admin-stat-value" id="stat-products"><?= e((string) $productCount) ?></span>
            <span class="admin-stat-note">
                <?= e((string) $deletedCount) ?> soft-deleted
            </span>
        </li>
        <li class="admin-stat">
            <span class="admin-stat-label">Orders</span>
            <span class="admin-stat-value" id="stat-orders"><?= e((string) $orderCount) ?></span>
            <span class="admin-stat-note">all orders, including cancelled</span>
        </li>
        <li class="admin-stat">
            <span class="admin-stat-label">Users</span>
            <span class="admin-stat-value" id="stat-users"><?= e((string) $userCount) ?></span>
            <span class="admin-stat-note">registered accounts</span>
        </li>
    </ul>

    <div class="quick-links">
        <a class="button" href="<?= e(APP_URL) ?>/admin/products/new">Add a product</a>
        <a class="button button-secondary" href="<?= e(APP_URL) ?>/admin/products">Manage products</a>
        <a class="button button-secondary" href="<?= e(APP_URL) ?>/admin/categories">Manage categories</a>
    </div>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
