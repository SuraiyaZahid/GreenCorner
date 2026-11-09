<?php
/**
 * GreenCorner — Landing page (P-01)
 *
 * Implements FR-2.1 and FR-2.4 at a preview level: the store name, a short
 * introduction, the category strip and a Browse Products call to action.
 */

require_once __DIR__ . '/../helpers/functions.php';

$pageTitle = 'Plants, seeds and garden supplies';

require __DIR__ . '/layouts/header.php';
?>

<section class="hero">
    <h1>GreenCorner</h1>
    <p class="hero-intro">
        A small, carefully chosen store for plants, seeds, pots, tools and
        fertilisers. Browse the catalogue, fill your cart, and check out in a
        few minutes.
    </p>
    <p>
        <a class="button" href="<?= e(APP_URL) ?>/products">Browse products</a>
    </p>
</section>

<?php if ($categories !== array()): ?>
    <section class="section">
        <h2>Shop by category</h2>
        <ul class="category-strip">
            <?php foreach ($categories as $category): ?>
                <li>
                    <a class="category-chip" href="<?= e(APP_URL) ?>/products?category=<?= e((string) $category['id']) ?>">
                        <span class="category-name"><?= e($category['name']) ?></span>
                        <span class="category-count"><?= e((string) $category['product_count']) ?> products</span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="section">
    <h2>New arrivals</h2>

    <?php if ($products === array()): ?>
        <div class="empty-state">
            <h3>No products yet</h3>
            <p>The catalogue is being prepared. Please check back soon.</p>
        </div>
    <?php else: ?>
        <ul class="product-grid">
            <?php foreach ($products as $product): ?>
                <?php require __DIR__ . '/partials/product_card.php'; ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/layouts/footer.php'; ?>
