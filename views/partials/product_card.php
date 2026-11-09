<?php
/**
 * GreenCorner — Product card partial.
 *
 * One card on the product listing (FR-2.1) and on the landing page.
 *
 * The card is image-led (FR-2.2): the thumbnail leads the card and the
 * product name sits beneath it. The THUMBNAIL is always used here — never
 * the original (FR-2.2 acceptance criterion 2).
 *
 * Expects:
 *   $product  array<string, mixed>  The product row.
 *   $showAddToCart  bool            Whether to show the Add to Cart button.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';
require_once dirname(__DIR__, 2) . '/helpers/image.php';

$showAddToCart = $showAddToCart ?? false;
$productId = (int) $product['id'];
$isOutOfStock = (int) $product['stock'] < 1;
?>
<li class="product-card">
    <a class="product-card-link" href="<?= e(APP_URL) ?>/products/<?= e((string) $productId) ?>">
        <span class="product-image">
            <img
                src="<?= e(upload_url((string) $product['thumbnail_path'])) ?>"
                alt="<?= e($product['name']) ?>"
                loading="lazy"
                width="400"
                height="400"
            >
            <?php if ($isOutOfStock): ?>
                <span class="product-badge">Out of stock</span>
            <?php endif; ?>
        </span>
        <span class="product-name"><?= e($product['name']) ?></span>
        <span class="product-price"><?= e(format_money($product['price'])) ?></span>
    </a>

    <?php if ($showAddToCart): ?>
        <div class="product-card-action">
            <?php if ($isOutOfStock): ?>
                <button type="button" class="button button-small button-quiet" disabled>
                    Out of stock
                </button>
            <?php else: ?>
                <button
                    type="button"
                    class="button button-small add-to-cart"
                    data-product-id="<?= e((string) $productId) ?>"
                >
                    Add to cart
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</li>
