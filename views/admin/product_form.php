<?php
/**
 * GreenCorner — Admin product form (create and edit)
 *
 * Implements:
 *   FR-6.2  create a product with a mandatory image
 *   FR-6.3  update a product; the image is optional on update
 *
 * The same view serves both actions. $product is null when creating, and
 * holds the current values when editing.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';
require_once dirname(__DIR__, 2) . '/helpers/authorization.php';
require_once dirname(__DIR__, 2) . '/helpers/csrf.php';
require_once dirname(__DIR__, 2) . '/helpers/image.php';

// The form serves both actions. $product is null when creating, and holds
// the current values when editing. On a validation failure it holds the
// values the Admin typed, which may not include an id — so the action is
// chosen by the presence of that id, not merely by $product being set.
$productId = (int) ($product['id'] ?? 0);
$isEditing = $productId > 0;
$pageTitle = $isEditing ? 'Edit product' : 'Add a product';
$adminSection = 'products';

$formAction = $isEditing
    ? APP_URL . '/admin/products/' . $productId . '/update'
    : APP_URL . '/admin/products';

// The values shown in the form. On a validation failure these are the
// values the Admin typed, so nothing has to be retyped.
$name        = (string) ($product['name'] ?? '');
$description = (string) ($product['description'] ?? '');
$price       = (string) ($product['price'] ?? '');
$stock       = (string) ($product['stock'] ?? '');
$categoryId  = (int) ($product['category_id'] ?? 0);

if ($isEditing && (int) ($product['is_deleted'] ?? 0) === 1) {
    $errors['is_deleted'] = 'This product has been removed. Saving it restores it to the shop.';
}

// The current image, when editing an existing product.
$currentThumbnail = $isEditing ? (string) ($product['thumbnail_path'] ?? '') : '';
?>

<?php require __DIR__ . '/../layouts/header.php'; ?>

<?php require __DIR__ . '/../layouts/nav.php'; ?>

<section class="form-panel admin-form-panel">
    <h1><?= $isEditing ? 'Edit product' : 'Add a product' ?></h1>

    <?php if (isset($errors['is_deleted'])): ?>
        <div class="alert alert-error" role="status"><?= e($errors['is_deleted']) ?></div>
    <?php endif; ?>

    <?php if (isset($errors['_csrf'])): ?>
        <div class="alert alert-error" role="alert"><?= e($errors['_csrf']) ?></div>
    <?php endif; ?>

    <?php if ($errors !== array()): ?>
        <div class="alert alert-error" role="alert">
            Please correct the highlighted fields below.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e($formAction) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <?php
        $fieldName = 'name';
        $label = 'Product name';
        $value = $name;
        $required = true;
        $hint = null;
        $attributes = 'maxlength="150"';
        require __DIR__ . '/../partials/admin_field.php';
        ?>

        <?php
        $fieldName = 'description';
        $label = 'Description';
        $value = $description;
        $type = 'textarea';
        $required = false;
        $hint = 'A short description shown on the product page.';
        $attributes = 'maxlength="5000"';
        require __DIR__ . '/../partials/admin_field.php';
        ?>

        <div class="field-row">
            <?php
            $fieldName = 'price';
            $label = 'Price';
            $value = $price;
            $type = 'text';
            $required = true;
            $hint = 'Up to two decimal places, for example 12.50.';
            $attributes = 'inputmode="decimal" maxlength="11"';
            require __DIR__ . '/../partials/admin_field.php';
            ?>

            <?php
            $fieldName = 'stock';
            $label = 'Stock';
            $value = $stock;
            $type = 'text';
            $required = true;
            $hint = 'Whole number of items in stock.';
            $attributes = 'inputmode="numeric" maxlength="7"';
            require __DIR__ . '/../partials/admin_field.php';
            ?>
        </div>

        <div class="field">
            <label for="field-category_id">Category</label>
            <select
                id="field-category_id"
                name="category_id"
                <?= isset($errors['category_id']) ? 'aria-invalid="true"' : '' ?>
            >
                <option value="">Choose a category</option>
                <?php foreach ($categories as $category): ?>
                    <option
                        value="<?= e((string) $category['id']) ?>"
                        <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>
                    >
                        <?= e($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if (isset($errors['category_id'])): ?>
                <p class="field-error" id="field-category_id-error"><?= e($errors['category_id']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="field-image">
                Image
                <span class="field-required" aria-hidden="true">*</span>
            </label>

            <?php if ($currentThumbnail !== ''): ?>
                <p class="current-image">
                    <img
                        src="<?= e(upload_url($currentThumbnail)) ?>"
                        alt="Current image of <?= e($name) ?>"
                        width="200"
                        height="200"
                    >
                    <span class="field-hint">Current image</span>
                </p>
            <?php endif; ?>

            <input
                type="file"
                id="field-image"
                name="image"
                accept="image/jpeg,image/png,image/webp"
                <?= $isEditing ? '' : 'required' ?>
                <?= isset($errors['image']) ? 'aria-invalid="true"' : '' ?>
            >

            <?php if (isset($errors['image'])): ?>
                <p class="field-error" id="field-image-error"><?= e($errors['image']) ?></p>
            <?php else: ?>
                <p class="field-hint" id="field-image-hint">
                    <?= $isEditing
                        ? 'Optional. Leave empty to keep the current image. JPG, PNG or WebP, up to 2 MB.'
                        : 'JPG, PNG or WebP, up to 2 MB.' ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="button">
                <?= $isEditing ? 'Save changes' : 'Create product' ?>
            </button>
            <a class="button button-quiet" href="<?= e(APP_URL) ?>/admin/products">Cancel</a>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
