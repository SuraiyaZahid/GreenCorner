<?php
/**
 * GreenCorner — Admin Category Management (P-16)
 *
 * Implements FR-6.6:
 *   Add, rename and remove categories. A category that still has products
 *   attached cannot be removed until those products are reassigned by hand,
 *   and the blocking products are named.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';
require_once dirname(__DIR__, 2) . '/helpers/authorization.php';
require_once dirname(__DIR__, 2) . '/helpers/csrf.php';

$pageTitle = 'Admin categories';
$adminSection = 'categories';
?>

<?php require __DIR__ . '/../layouts/header.php'; ?>

<?php require __DIR__ . '/../layouts/nav.php'; ?>

<section class="admin-panel">
    <div class="admin-panel-header">
        <div>
            <h1>Categories</h1>
            <p class="panel-intro">
                Every product belongs to exactly one category. A category that
                still has products attached cannot be removed until they are
                reassigned.
            </p>
        </div>
    </div>

    <?php if (isset($errors['_csrf'])): ?>
        <div class="alert alert-error" role="alert"><?= e($errors['_csrf']) ?></div>
    <?php endif; ?>

    <?php if ($errors !== array()): ?>
        <div class="alert alert-error" role="alert">
            <?php if (isset($errors['delete'])): ?>
                <?= e($errors['delete']) ?>
            <?php else: ?>
                Please correct the highlighted fields below.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($blockedProducts !== array()): ?>
        <div class="alert alert-error" role="alert">
            <p><strong>These products must be reassigned first:</strong></p>
            <ul class="blocked-product-list">
                <?php foreach ($blockedProducts as $blocked): ?>
                    <li>
                        <?= e($blocked['name']) ?>
                        <?php if ((int) $blocked['is_deleted'] === 1): ?>
                            <span class="badge badge-deleted">Deleted</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($categories === array()): ?>
        <div class="empty-state">
            <h2>No categories yet</h2>
            <p>Add your first category to organise the catalogue.</p>
        </div>
    <?php else: ?>
        <ul class="admin-category-list">
            <?php foreach ($categories as $category): ?>
                <li class="admin-category-row">
                    <div class="admin-category-info">
                        <h2 class="admin-category-name"><?= e($category['name']) ?></h2>
                        <?php if (($category['description'] ?? '') !== ''): ?>
                            <p class="admin-category-description"><?= e($category['description']) ?></p>
                        <?php endif; ?>
                        <p class="admin-product-meta">
                            <?= e((string) $category['product_count']) ?>
                            <?= (int) $category['product_count'] === 1 ? 'product' : 'products' ?> attached
                        </p>
                    </div>

                    <div class="admin-category-actions">
                        <button
                            type="button"
                            class="button button-small button-secondary"
                            data-toggle-edit="category-<?= e((string) $category['id']) ?>"
                        >
                            Rename
                        </button>

                        <form
                            method="post"
                            action="<?= e(APP_URL) ?>/admin/categories/<?= e((string) $category['id']) ?>/delete"
                            data-confirm="Remove the category &quot;<?= e($category['name']) ?>&quot;?"
                        >
                            <?= csrf_field() ?>
                            <button
                                type="submit"
                                class="button button-small button-quiet"
                                <?= (int) $category['product_count'] > 0 ? 'disabled' : '' ?>
                            >
                                Remove
                            </button>
                        </form>
                    </div>

                    <form
                        class="admin-inline-form"
                        id="category-<?= e((string) $category['id']) ?>"
                        method="post"
                        action="<?= e(APP_URL) ?>/admin/categories/<?= e((string) $category['id']) ?>/update"
                        hidden
                    >
                        <?= csrf_field() ?>

                        <div class="field">
                            <label for="category-name-<?= e((string) $category['id']) ?>">Category name</label>
                            <input
                                type="text"
                                id="category-name-<?= e((string) $category['id']) ?>"
                                name="name"
                                value="<?= e($category['name']) ?>"
                                maxlength="100"
                                required
                            >
                        </div>

                        <div class="field">
                            <label for="category-description-<?= e((string) $category['id']) ?>">Description</label>
                            <input
                                type="text"
                                id="category-description-<?= e((string) $category['id']) ?>"
                                name="description"
                                value="<?= e((string) ($category['description'] ?? '')) ?>"
                                maxlength="255"
                            >
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="button button-small">Save</button>
                            <button
                                type="button"
                                class="button button-small button-quiet"
                                data-toggle-edit="category-<?= e((string) $category['id']) ?>"
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <section class="admin-add-panel">
        <h2>Add a category</h2>

        <form method="post" action="<?= e(APP_URL) ?>/admin/categories">
            <?= csrf_field() ?>

            <?php
            $fieldName = 'name';
            $label = 'Category name';
            $value = '';
            $required = true;
            $hint = null;
            $attributes = 'maxlength="100"';
            require __DIR__ . '/../partials/admin_field.php';
            ?>

            <?php
            $fieldName = 'description';
            $label = 'Description';
            $value = '';
            $type = 'text';
            $required = false;
            $hint = 'Optional.';
            $attributes = 'maxlength="255"';
            require __DIR__ . '/../partials/admin_field.php';
            ?>

            <div class="form-actions">
                <button type="submit" class="button">Add category</button>
            </div>
        </form>
    </section>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
