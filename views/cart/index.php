<?php
/**
 * GreenCorner — Cart page (P-08)
 *
 * Implements:
 *   FR-3.2  each line shows the thumbnail, name, unit price, quantity and
 *           line total, with a grand total
 *   FR-3.5  an item can be removed
 *   FR-3.6  a quantity can be changed
 *   FR-3.7  the current price is shown; a changed price is announced with
 *           the old and the new price
 *   FR-3.8  an unavailable item is shown and blocks checkout
 *   FR-3.9  the empty-cart state with a link to the listing
 *   FR-3.11 the Proceed to Payment action
 *
 * Expects:
 *   $items    array<int, array<string, mixed>>  The cart lines.
 *   $total    float                             The grand total.
 *   $cartCount int                              The number of items in the cart.
 *   $blocked  array<int, array<string, mixed>>  The unavailable lines.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';
require_once dirname(__DIR__, 2) . '/helpers/image.php';
require_once dirname(__DIR__, 2) . '/helpers/csrf.php';

$pageTitle = 'Your cart';
$isEmpty = $items === array();

require __DIR__ . '/../layouts/header.php';
?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(APP_URL) ?>/">Home</a>
    <span aria-hidden="true">/</span>
    <span>Cart</span>
</nav>

<section class="cart">
    <h1>Your cart</h1>

    <?php if ($isEmpty): ?>
        <?php // FR-3.9: the empty-cart message and the link back to the listing. ?>
        <div class="empty-state">
            <h2>Your cart is empty</h2>
            <p>
                Browse the catalogue and add something you like — your cart is
                saved to your account, so it will still be here when you come
                back.
            </p>
            <a class="button" href="<?= e(APP_URL) ?>/products">Browse products</a>
        </div>

        <?php // FR-7.7: the token the background cart requests carry. ?>
        <form id="cart-form" method="post" action="<?= e(APP_URL) ?>/cart/update" hidden>
            <?= csrf_field() ?>
        </form>
    <?php else: ?>
        <?php if ($blocked !== array()): ?>
            <?php // FR-3.8 / FR-3.11: checkout is blocked while an item is unavailable. ?>
            <div class="alert alert-error" role="alert">
                <p><strong>Some items need your attention before you can check out.</strong></p>
                <ul>
                    <?php foreach ($blocked as $item): ?>
                        <li>
                            <?php if ($item['deleted']): ?>
                                <strong><?= e($item['name']) ?></strong> is no longer available.
                            <?php else: ?>
                                <strong><?= e($item['name']) ?></strong> is out of stock.
                            <?php endif; ?>
                            Please remove it from your cart to continue.
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php // FR-7.7: every state-changing request carries a CSRF token. The
              // form is the no-JavaScript fallback; the background requests
              // carry the same token. ?>
        <form id="cart-form" method="post" action="<?= e(APP_URL) ?>/cart/update">
            <?= csrf_field() ?>

            <table class="cart-table" id="cart-table">
                <caption class="visually-hidden">Items in your cart</caption>
                <thead>
                    <tr>
                        <th scope="col">Product</th>
                        <th scope="col">Unit price</th>
                        <th scope="col">Quantity</th>
                        <th scope="col">Line total</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="cart-items">
                <?php foreach ($items as $item): ?>
                    <tr
                        class="cart-row<?= $item['unavailable'] ? ' cart-row-unavailable' : '' ?>"
                        data-product-id="<?= e((string) $item['product_id']) ?>"
                        data-line-total="<?= e(number_format((float) $item['line_total'], 2, '.', '')) ?>"
                    >
                        <td class="cart-cell-product">
                            <a class="cart-product-link" href="<?= e(APP_URL) ?>/products/<?= e((string) $item['product_id']) ?>">
                                <img
                                    class="cart-thumbnail"
                                    src="<?= e(upload_url((string) $item['thumbnail_path'])) ?>"
                                    alt="<?= e($item['name']) ?>"
                                    width="80"
                                    height="80"
                                    loading="lazy"
                                >
                                <span class="cart-product-name"><?= e($item['name']) ?></span>
                            </a>

                            <?php if ($item['deleted']): ?>
                                <?php // FR-3.8: the item stays visible with "No longer available". ?>
                                <span class="cart-flag cart-flag-unavailable">No longer available</span>
                            <?php elseif ($item['unavailable']): ?>
                                <span class="cart-flag cart-flag-unavailable">Out of stock</span>
                            <?php else: ?>
                                <?php if ($item['stock_reduced']): ?>
                                    <?php // FR-3.8: the stock fell below the cart quantity. ?>
                                    <span class="cart-flag cart-flag-warning">
                                        Only <?= e((string) $item['stock']) ?> left — please reduce the quantity.
                                    </span>
                                <?php endif; ?>

                                <?php if ($item['price_changed']): ?>
                                    <?php // FR-3.7: the old and the new price are both shown. ?>
                                    <span class="cart-flag cart-flag-notice">
                                        Price changed: was <?= e(format_money($item['price_at_add'])) ?>,
                                        now <?= e(format_money($item['unit_price'])) ?>.
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <td class="cart-cell-price">
                            <span class="cart-unit-price"><?= e(format_money($item['unit_price'])) ?></span>
                        </td>

                        <td class="cart-cell-quantity">
                            <?php if ($item['unavailable']): ?>
                                <span class="cart-quantity-static"><?= e((string) $item['quantity']) ?></span>
                            <?php else: ?>
                                <label class="visually-hidden" for="qty-<?= e((string) $item['product_id']) ?>">
                                    Quantity for <?= e($item['name']) ?>
                                </label>
                                <input
                                    class="cart-quantity-input"
                                    id="qty-<?= e((string) $item['product_id']) ?>"
                                    type="number"
                                    inputmode="numeric"
                                    min="1"
                                    max="<?= e((string) $item['max_quantity']) ?>"
                                    value="<?= e((string) $item['quantity']) ?>"
                                    data-product-id="<?= e((string) $item['product_id']) ?>"
                                >
                            <?php endif; ?>
                        </td>

                        <td class="cart-cell-line-total">
                            <span class="cart-line-total"><?= e(format_money($item['line_total'])) ?></span>
                        </td>

                        <td class="cart-cell-actions">
                            <button
                                type="button"
                                class="button button-small button-quiet cart-remove"
                                data-product-id="<?= e((string) $item['product_id']) ?>"
                            >
                                Remove
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row" colspan="3">Grand total</th>
                        <td class="cart-cell-line-total">
                            <span class="cart-grand-total" id="cart-grand-total">
                                <?= e(format_money($total)) ?>
                            </span>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </form>

        <div class="cart-actions">
            <a class="button button-secondary" href="<?= e(APP_URL) ?>/products">Continue browsing</a>

            <?php if ($blocked === array()): ?>
                <?php // FR-3.11: the Proceed to Payment action. ?>
                <a class="button" href="<?= e(APP_URL) ?>/checkout/payment">Proceed to payment</a>
            <?php else: ?>
                <?php // FR-3.11: the action is withheld entirely while an item is
                      // unavailable, so there is no way to reach the checkout. ?>
                <span class="button button-disabled" aria-disabled="true">
                    Checkout unavailable
                </span>
                <p class="cart-blocked-note">
                    Please remove the unavailable item<?= count($blocked) > 1 ? 's' : '' ?> above to continue.
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
