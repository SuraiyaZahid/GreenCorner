<?php
/**
 * GreenCorner — Product Listing page (P-05)
 *
 * Implements:
 *   FR-2.1  the listing shows a thumbnail, name and price per product
 *   FR-2.2  image-led cards: the thumbnail leads the card
 *   FR-2.4  category filter, preserved in the URL and across pagination
 *   FR-2.5  search by name, case-insensitive substring
 *   FR-2.6  multi-word search: every word must appear in the name
 *   FR-2.9  the empty-result state with a Clear Search action
 *   FR-2.10 pagination, 12 products per page
 *   FR-2.11 ordering: newest first, no sort control
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';
require_once dirname(__DIR__, 2) . '/helpers/authorization.php';

$pageTitle = 'Products';

// An Admin may browse the catalogue but may not shop (FR-7.5), so the
// Add to Cart button is not offered to an Admin.
$showAddToCart = current_role() === ROLE_USER;

require __DIR__ . '/../layouts/header.php';
?>

<section class="catalogue-header">
    <h1>Products</h1>
    <p class="catalogue-intro">
        Browse plants, seeds, pots, tools and fertilisers. Newest products
        appear first.
    </p>
</section>

<div class="catalogue-layout">
    <aside class="catalogue-sidebar">
        <h2>Categories</h2>
        <nav class="catalogue-categories" aria-label="Filter by category">
            <a
                class="category-filter-chip<?= $categoryId === null ? ' is-active' : '' ?>"
                href="<?= e(APP_URL) ?>/products<?= $search !== null ? '?search=' . e(rawurlencode($search)) : '' ?>"
            >
                All products
            </a>
            <?php foreach ($categories as $category): ?>
                <?php
                $parameters = array();
                if ($search !== null) {
                    $parameters['search'] = $search;
                }
                $parameters['category'] = (string) $category['id'];
                ?>
                <a
                    class="category-filter-chip<?= $categoryId === (int) $category['id'] ? ' is-active' : '' ?>"
                    href="<?= e(APP_URL) ?>/products?<?= e(http_build_query($parameters)) ?>"
                >
                    <?= e($category['name']) ?>
                    <span class="category-count">(<?= e((string) $category['product_count']) ?>)</span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="catalogue-main">
        <section class="catalogue-controls">
            <form class="catalogue-search" method="get" action="<?= e(APP_URL) ?>/products">
                <?php if ($categoryId !== null): ?>
                    <input type="hidden" name="category" value="<?= e((string) $categoryId) ?>">
                <?php endif; ?>

                <div class="field">
                    <label for="search">Search by product name</label>
                    <input
                        type="search"
                        id="search"
                        name="search"
                        value="<?= e($search ?? '') ?>"
                        maxlength="150"
                        autocomplete="off"
                        placeholder="e.g. fern"
                    >
                </div>

                <button type="submit" class="button">Search</button>

                <?php if (($search ?? null) !== null || $categoryId !== null): ?>
                    <a class="button button-quiet" href="<?= e(APP_URL) ?>/products">Clear</a>
                <?php endif; ?>
            </form>
        </section>

        <section class="catalogue-results">
            <p class="result-count">
                <?php if ($totalResults === 0): ?>
                    No products found
                <?php else: ?>
                    <?= e((string) $totalResults) ?>
                    <?= $totalResults === 1 ? 'product' : 'products' ?>
                    <?php if ($lastPage > 1): ?>
                        &middot; page <?= e((string) $page) ?> of <?= e((string) $lastPage) ?>
                    <?php endif; ?>
                <?php endif; ?>
            </p>

            <?php if ($products === array()): ?>
                <?php // FR-2.9: the empty-result state with a Clear Search action. ?>
                <div class="empty-state">
                    <h2>No products found</h2>
                    <p>
                        <?php if (($search ?? null) !== null): ?>
                            Nothing matches &ldquo;<?= e($search) ?>&rdquo;.
                        <?php else: ?>
                            There are no products in this category yet.
                        <?php endif; ?>
                    </p>
                    <p>
                        <a class="button" href="<?= e(APP_URL) ?>/products">Clear search</a>
                    </p>
                </div>
            <?php else: ?>
                <ul class="product-grid">
                    <?php foreach ($products as $product): ?>
                        <?php require __DIR__ . '/../partials/product_card.php'; ?>
                    <?php endforeach; ?>
                </ul>

                <?php require __DIR__ . '/../partials/pagination.php'; ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
