<?php
/**
 * GreenCorner — Admin Product Management (P-15)
 *
 * Implements:
 *   FR-6.2  add a product
 *   FR-6.3  update any product field, including the image
 *   FR-6.5  removal is always a soft delete
 *
 * The list shows BOTH active and soft-deleted products, 20 per page. A
 * soft-deleted product is clearly marked, so the Admin can tell it apart
 * from a live one.
 */

require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../helpers/authorization.php';
require_once __DIR__ . '/../../helpers/csrf.php';
require_once __DIR__ . '/../../helpers/image.php';

$pageTitle = 'Admin products';
$adminSection = 'products';
?>

<?php require __DIR__ . '/../layouts/header.php'; ?>

<?php require __DIR__ . '/../layouts/nav.php'; ?>

<section class="admin-panel">
    <div class="admin-panel-header">
        <div>
            <h1>Products</h1>
            <p class="panel-intro">
                <?= e((string) $totalResults) ?>
                <?= $totalResults === 1 ? 'product' : 'products' ?> in total
                &middot; page <?= e((string) $page) ?> of <?= e((string) $lastPage) ?>
            </p>
        </div>
        <a class="button" href="<?= e(APP_URL) ?>/admin/products/new">Add a product</a>
    </div>

    <?php if ($products === array()): ?>
        <div class="empty-state">
            <h2>No products yet</h2>
            <p>Add your first product to fill the catalogue.</p>
            <p>
                <a class="button" href="<?= e(APP_URL) ?>/admin/products/new">Add a product</a>
            </p>
        </div>
    <?php else: ?>
        <ul class="admin-product-list">
            <?php foreach ($products as $product): ?>
                <?php $isDeleted = (int) $product['is_deleted'] === 1; ?>
                <li class="admin-product-row<?= $isDeleted ? ' is-deleted' : '' ?>">
                    <div class="admin-product-thumb">
                        <?php if (($product['thumbnail_path'] ?? '') !== ''): ?>
                            <img
                                src="<?= e(upload_url((string) $product['thumbnail_path'])) ?>"
                                alt=""
                                width="400"
                                height="400"
                                loading="lazy"
                            >
                        <?php else: ?>
                            <span class="admin-product-noimage" aria-hidden="true">—</span>
                        <?php endif; ?>
                    </div>

                    <div class="admin-product-info">
                        <h2 class="admin-product-name">
                            <?= e($product['name']) ?>
                            <?php if ($isDeleted): ?>
                                <span class="badge badge-deleted">Deleted</span>
                            <?php endif; ?>
                        </h2>
                        <p class="admin-product-meta">
                            <?= e($product['category_name']) ?>
                            &middot; <?= e(format_money($product['price'])) ?>
                            &middot; stock <?= e((string) $product['stock']) ?>
                        </p>
                    </div>

                    <div class="admin-product-actions">
                        <a
                            class="button button-small button-secondary"
                            href="<?= e(APP_URL) ?>/admin/products/<?= e((string) $product['id']) ?>/edit"
                        >
                            Edit
                        </a>

                        <?php if ($isDeleted): ?>
                            <form method="post" action="<?= e(APP_URL) ?>/admin/products/<?= e((string) $product['id']) ?>/restore">
                                <?= csrf_field() ?>
                                <button type="submit" class="button button-small button-quiet">Restore</button>
                            </form>
                        <?php else: ?>
                            <form
                                method="post"
                                action="<?= e(APP_URL) ?>/admin/products/<?= e((string) $product['id']) ?>/delete"
                                data-confirm="Remove &quot;<?= e($product['name']) ?>&quot;? The product will no longer be shown in the shop."
                            >
                                <?= csrf_field() ?>
                                <button type="submit" class="button button-small button-quiet">Remove</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($lastPage > 1): ?>
            <?php
            $query = array();
            require __DIR__ . '/../partials/admin_pagination.php';
            ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
