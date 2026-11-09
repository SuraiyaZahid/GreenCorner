<?php
/**
 * GreenCorner — Product Detail page (P-06)
 *
 * Implements:
 *   FR-2.7  the original image, description, price, stock status and
 *           category; out-of-stock products are marked and their Add to Cart
 *           button is disabled; the description is output-encoded
 *   FR-2.12 the detail page uses the ORIGINAL image, never the thumbnail
 */

require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../helpers/authorization.php';
require_once __DIR__ . '/../../helpers/image.php';

$pageTitle = $product['name'];

// FR-3.12: from the detail page, Add to Cart navigates to the Cart page.
// The body class lets the JavaScript tell the two contexts apart.
$bodyClass = 'product-detail-page';

// An Admin may browse the catalogue but may not shop (FR-7.5).
$showAddToCart = current_role() === ROLE_USER;

$isOutOfStock = (int) $product['stock'] < 1;

require __DIR__ . '/../layouts/header.php';
?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(APP_URL) ?>/">Home</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(APP_URL) ?>/products">Products</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(APP_URL) ?>/products?category=<?= e((string) $product['category_id']) ?>">
        <?= e($product['category_name']) ?>
    </a>
</nav>

<article class="product-detail">
    <div class="product-detail-image">
        <img
            src="<?= e(upload_url((string) $product['image_path'])) ?>"
            alt="<?= e($product['name']) ?>"
            width="600"
            height="600"
        >
    </div>

    <div class="product-detail-info">
        <h1><?= e($product['name']) ?></h1>

        <p class="product-detail-price"><?= e(format_money($product['price'])) ?></p>

        <p class="product-detail-category">
            <span class="label">Category</span>
            <a href="<?= e(APP_URL) ?>/products?category=<?= e((string) $product['category_id']) ?>">
                <?= e($product['category_name']) ?>
            </a>
        </p>

        <p class="product-detail-stock">
            <span class="label">Availability</span>
            <?php if ($isOutOfStock): ?>
                <span class="stock-badge stock-out">Out of stock</span>
            <?php else: ?>
                <span class="stock-badge stock-in">In stock</span>
                <span class="stock-count"><?= e((string) $product['stock']) ?> available</span>
            <?php endif; ?>
        </p>

        <?php if ($product['description'] !== null && $product['description'] !== ''): ?>
            <?php // FR-2.7: the description is output-encoded (NFR-S-03). ?>
            <div class="product-detail-description">
                <h2>Description</h2>
                <p><?= e($product['description']) ?></p>
            </div>
        <?php endif; ?>

        <div class="product-detail-actions">
            <?php if ($showAddToCart): ?>
                <?php if ($isOutOfStock): ?>
                    <?php // FR-2.7: the button is disabled when the stock is zero. ?>
                    <button type="button" class="button button-quiet" disabled>
                        Out of stock
                    </button>
                <?php else: ?>
                    <button
                        type="button"
                        class="button add-to-cart"
                        data-product-id="<?= e((string) $product['id']) ?>"
                    >
                        Add to cart
                    </button>
                <?php endif; ?>
            <?php endif; ?>

            <a class="button button-secondary" href="<?= e(APP_URL) ?>/products">
                Continue browsing
            </a>
        </div>
    </div>
</article>

<?php if ($related !== array()): ?>
    <section class="section">
        <h2>More from <?= e($product['category_name']) ?></h2>
        <ul class="product-grid">
            <?php foreach ($related as $product): ?>
                <?php require __DIR__ . '/../partials/product_card.php'; ?>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
