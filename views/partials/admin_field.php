<?php
/**
 * GreenCorner — Admin form field partial
 *
 * One labelled field with its inline validation error, used by the product
 * and category forms.
 *
 * Expects:
 *   $fieldName  string                The field name, e.g. 'name'.
 *   $label      string                The visible label.
 *   $value      string                The current value.
 *   $errors     array<string,string>  The field-level error messages.
 *   $type       string                The input type (default 'text').
 *   $required   bool                  Whether the field is required.
 *   $hint       string|null           Optional hint text under the field.
 *   $attributes string                Extra HTML attributes for the input.
 */

$fieldName  = $fieldName ?? '';
$label      = $label ?? '';
$value      = $value ?? '';
$errors     = $errors ?? array();
$type       = $type ?? 'text';
$required   = $required ?? false;
$hint       = $hint ?? null;
$attributes = $attributes ?? '';

$error    = isset($errors[$fieldName]) ? (string) $errors[$fieldName] : '';
$inputId  = 'field-' . preg_replace('/[^a-z0-9]+/i', '-', (string) $fieldName);
$requiredAttr = $required ? ' required' : '';
$ariaInvalid = $error !== '' ? ' aria-invalid="true"' : '';
$ariaDescribed = '';
if ($error !== '') {
    $ariaDescribed = ' aria-describedby="' . e($inputId) . '-error"';
} elseif ($hint !== null) {
    $ariaDescribed = ' aria-describedby="' . e($inputId) . '-hint"';
}
?>
<div class="field">
    <label for="<?= e($inputId) ?>">
        <?= e($label) ?>
        <?php if ($required): ?>
            <span class="field-required" aria-hidden="true">*</span>
        <?php endif; ?>
    </label>

    <?php if ($type === 'textarea'): ?>
        <textarea
            id="<?= e($inputId) ?>"
            name="<?= e($fieldName) ?>"
            rows="6"
            <?= $requiredAttr ?><?= $ariaInvalid ?><?= $ariaDescribed ?>
            <?= $attributes ?>
        ><?= e($value) ?></textarea>
    <?php else: ?>
        <input
            type="<?= e($type) ?>"
            id="<?= e($inputId) ?>"
            name="<?= e($fieldName) ?>"
            value="<?= e($value) ?>"
            <?= $requiredAttr ?><?= $ariaInvalid ?><?= $ariaDescribed ?>
            <?= $attributes ?>
        >
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <p class="field-error" id="<?= e($inputId) ?>-error"><?= e($error) ?></p>
    <?php elseif ($hint !== null): ?>
        <p class="field-hint" id="<?= e($inputId) ?>-hint"><?= e($hint) ?></p>
    <?php endif; ?>
</div>
