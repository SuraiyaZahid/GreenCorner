<?php
/**
 * GreenCorner — Payment / Checkout page (P-09)
 *
 * Implements:
 *   FR-4.1  the order summary, the delivery address form and the payment
 *           form
 *   FR-4.2  Card, Cash on Delivery, bKash and Nagad radio buttons; Card is
 *           the default
 *   FR-4.3  inline field errors for the card details, the wallet details
 *           and the address
 *   FR-4.8  the delivery address form
 *   FR-4.12 the updated total, shown when a price has changed
 *   FR-4.13 the bKash and Nagad instruction panels, with the merchant
 *           number, the amount to send and the order reference
 *
 * Expects:
 *   $items          array<int, array<string, mixed>>  The order summary lines.
 *   $total          float                             The grand total.
 *   $method         string                            The selected payment method.
 *   $errors         array<string, string>             The inline field errors.
 *   $address        array<string, string>             The delivery address values.
 *   $walletMethods  array<int, string>                The wallet methods that are available.
 *   $walletContext  array<string, array<string, string>>  The merchant details of each wallet.
 *   $referenceCode  string                            The order reference shown to the customer.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';
require_once dirname(__DIR__, 2) . '/helpers/image.php';
require_once dirname(__DIR__, 2) . '/helpers/csrf.php';
require_once dirname(__DIR__, 2) . '/models/WalletPayment.php';

$pageTitle = 'Payment';

$errors = $errors ?? array();
$method = $method ?? 'card';
$address = $address ?? array(
    'delivery_name'    => '',
    'delivery_phone'   => '',
    'delivery_address' => '',
    'delivery_city'    => '',
);

// FR-4.13: the wallet methods that are configured, and the merchant details
// the instruction panels show. Both default to empty, so the page still
// renders on an installation with no wallet configured.
$walletMethods = isset($walletMethods) && is_array($walletMethods) ? $walletMethods : array();
$walletContext = isset($walletContext) && is_array($walletContext) ? $walletContext : array();
$referenceCode = isset($referenceCode) ? (string) $referenceCode : '';

// FR-4.12: the pending price change, shown for confirmation before the
// order is committed.
$priceChange = $_SESSION['checkout_price_change'] ?? null;

$isCod = $method === 'cod';
$isBkash = $method === WalletPayment::METHOD_BKASH;
$isNagad = $method === WalletPayment::METHOD_NAGAD;

/**
 * The inline error for one field, if there is one.
 */
function payment_field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? (string) $errors[$field] : '';
}

require __DIR__ . '/../layouts/header.php';
?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(APP_URL) ?>/">Home</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(APP_URL) ?>/cart">Cart</a>
    <span aria-hidden="true">/</span>
    <span>Payment</span>
</nav>

<section class="checkout">
    <h1>Payment</h1>

    <?php if (is_array($priceChange) && $priceChange !== array()): ?>
        <?php // FR-4.12: the customer must confirm the updated total. ?>
        <div class="alert alert-notice" role="alert">
            <p>
                <strong>The price has changed since you opened this page.</strong>
                The total is now <?= e(format_money($priceChange['total'])) ?>,
                previously <?= e(format_money($priceChange['previous_total'])) ?>.
                Please confirm the new total below to place your order.
            </p>
        </div>

        <form class="checkout-confirm" method="post" action="<?= e(APP_URL) ?>/checkout/confirm-price">
            <?= csrf_field() ?>
            <input type="hidden" name="payment_method" value="<?= e((string) ($priceChange['method'] ?? 'card')) ?>">

            <?php if (($priceChange['method'] ?? 'card') === 'card'): ?>
                <div class="form-row">
                    <label for="confirm-cardholder_name">Name on card</label>
                    <input type="text" id="confirm-cardholder_name" name="cardholder_name"
                           value="" autocomplete="cc-name" maxlength="120">
                </div>
                <div class="form-row">
                    <label for="confirm-card_number">Card number</label>
                    <input type="text" id="confirm-card_number" name="card_number"
                           value="" autocomplete="cc-number" inputmode="numeric">
                </div>
                <div class="form-row form-row-split">
                    <div>
                        <label for="confirm-expiry">Expiry</label>
                        <input type="text" id="confirm-expiry" name="expiry"
                               value="" autocomplete="cc-exp" placeholder="MM/YY" maxlength="7">
                    </div>
                    <div>
                        <label for="confirm-cvv">Security code</label>
                        <input type="text" id="confirm-cvv" name="cvv"
                               value="" autocomplete="cc-csc" inputmode="numeric" maxlength="4">
                    </div>
                </div>
            <?php elseif (WalletPayment::isWalletMethod((string) ($priceChange['method'] ?? ''))): ?>
                <?php // FR-4.13: the wallet details are re-entered with the confirmed total. ?>
                <?php $confirmIsNagad = ((string) ($priceChange['method'] ?? '')) === WalletPayment::METHOD_NAGAD; ?>
                <div class="form-row">
                    <label for="confirm-sender_number">Sender number</label>
                    <input type="text" id="confirm-sender_number"
                           name="<?= $confirmIsNagad ? 'nagad_sender_number' : 'sender_number' ?>"
                           value="" inputmode="numeric" maxlength="20">
                </div>
                <div class="form-row">
                    <label for="confirm-transaction_id">Transaction ID (TrxID)</label>
                    <input type="text" id="confirm-transaction_id"
                           name="<?= $confirmIsNagad ? 'nagad_transaction_id' : 'transaction_id' ?>"
                           value="" maxlength="64">
                </div>
                <input type="hidden" name="reference_code" value="<?= e($referenceCode) ?>">
            <?php endif; ?>

            <button type="submit" class="button">
                Confirm the new total and place my order
            </button>
            <a class="button button-secondary" href="<?= e(APP_URL) ?>/cart">Back to my cart</a>
        </form>
    <?php endif; ?>

    <div class="checkout-layout">
        <div class="checkout-main">
            <form class="checkout-form" method="post" action="<?= e(APP_URL) ?>/checkout/place-order" novalidate>
                <?= csrf_field() ?>

                <fieldset class="form-section">
                    <legend>Delivery address</legend>

                    <?php $nameError = payment_field_error($errors, 'delivery_name'); ?>
                    <div class="form-row<?= $nameError !== '' ? ' has-error' : '' ?>">
                        <label for="delivery_name">Recipient's name</label>
                        <input
                            type="text"
                            id="delivery_name"
                            name="delivery_name"
                            value="<?= e((string) ($address['delivery_name'] ?? '')) ?>"
                            maxlength="120"
                            autocomplete="name"
                        >
                        <?php if ($nameError !== ''): ?>
                            <p class="field-error" role="alert"><?= e($nameError) ?></p>
                        <?php endif; ?>
                    </div>

                    <?php $phoneError = payment_field_error($errors, 'delivery_phone'); ?>
                    <div class="form-row<?= $phoneError !== '' ? ' has-error' : '' ?>">
                        <label for="delivery_phone">Contact number</label>
                        <input
                            type="text"
                            id="delivery_phone"
                            name="delivery_phone"
                            value="<?= e((string) ($address['delivery_phone'] ?? '')) ?>"
                            maxlength="40"
                            autocomplete="tel"
                        >
                        <?php if ($phoneError !== ''): ?>
                            <p class="field-error" role="alert"><?= e($phoneError) ?></p>
                        <?php endif; ?>
                    </div>

                    <?php $addressError = payment_field_error($errors, 'delivery_address'); ?>
                    <div class="form-row<?= $addressError !== '' ? ' has-error' : '' ?>">
                        <label for="delivery_address">Street address</label>
                        <input
                            type="text"
                            id="delivery_address"
                            name="delivery_address"
                            value="<?= e((string) ($address['delivery_address'] ?? '')) ?>"
                            maxlength="255"
                            autocomplete="street-address"
                        >
                        <?php if ($addressError !== ''): ?>
                            <p class="field-error" role="alert"><?= e($addressError) ?></p>
                        <?php endif; ?>
                    </div>

                    <?php $cityError = payment_field_error($errors, 'delivery_city'); ?>
                    <div class="form-row<?= $cityError !== '' ? ' has-error' : '' ?>">
                        <label for="delivery_city">City or area</label>
                        <input
                            type="text"
                            id="delivery_city"
                            name="delivery_city"
                            value="<?= e((string) ($address['delivery_city'] ?? '')) ?>"
                            maxlength="100"
                            autocomplete="address-level2"
                        >
                        <?php if ($cityError !== ''): ?>
                            <p class="field-error" role="alert"><?= e($cityError) ?></p>
                        <?php endif; ?>
                    </div>
                </fieldset>

                <fieldset class="form-section">
                    <legend>Payment method</legend>

                    <?php // FR-4.2: mutually exclusive radio buttons; Card is selected by default. ?>
                    <div class="payment-methods">
                        <label class="payment-method">
                            <input
                                type="radio"
                                name="payment_method"
                                value="card"
                                <?= ($isCod || $isBkash || $isNagad) ? '' : 'checked' ?>
                                data-payment-method="card"
                            >
                            <span class="payment-method-label">Card</span>
                        </label>

                        <label class="payment-method">
                            <input
                                type="radio"
                                name="payment_method"
                                value="cod"
                                <?= $isCod ? 'checked' : '' ?>
                                data-payment-method="cod"
                            >
                            <span class="payment-method-label">Cash on delivery</span>
                        </label>

                        <?php // FR-4.13: bKash is only offered when a merchant number is configured. ?>
                        <?php if (in_array(WalletPayment::METHOD_BKASH, $walletMethods, true)): ?>
                            <label class="payment-method">
                                <input
                                    type="radio"
                                    name="payment_method"
                                    value="<?= e(WalletPayment::METHOD_BKASH) ?>"
                                    <?= $isBkash ? 'checked' : '' ?>
                                    data-payment-method="<?= e(WalletPayment::METHOD_BKASH) ?>"
                                >
                                <span class="payment-method-label">bKash</span>
                            </label>
                        <?php endif; ?>

                        <?php // FR-4.13: Nagad is only offered when a merchant number is configured. ?>
                        <?php if (in_array(WalletPayment::METHOD_NAGAD, $walletMethods, true)): ?>
                            <label class="payment-method">
                                <input
                                    type="radio"
                                    name="payment_method"
                                    value="<?= e(WalletPayment::METHOD_NAGAD) ?>"
                                    <?= $isNagad ? 'checked' : '' ?>
                                    data-payment-method="<?= e(WalletPayment::METHOD_NAGAD) ?>"
                                >
                                <span class="payment-method-label">Nagad</span>
                            </label>
                        <?php endif; ?>
                    </div>

                    <div class="card-fields" id="card-fields" <?= $isCod || $isBkash || $isNagad ? 'hidden' : '' ?>>
                        <?php $holderError = payment_field_error($errors, 'cardholder_name'); ?>
                        <div class="form-row<?= $holderError !== '' ? ' has-error' : '' ?>">
                            <label for="cardholder_name">Name on card</label>
                            <input
                                type="text"
                                id="cardholder_name"
                                name="cardholder_name"
                                value=""
                                autocomplete="cc-name"
                                maxlength="120"
                            >
                            <?php if ($holderError !== ''): ?>
                                <p class="field-error" role="alert"><?= e($holderError) ?></p>
                            <?php endif; ?>
                        </div>

                        <?php $numberError = payment_field_error($errors, 'card_number'); ?>
                        <div class="form-row<?= $numberError !== '' ? ' has-error' : '' ?>">
                            <label for="card_number">Card number</label>
                            <input
                                type="text"
                                id="card_number"
                                name="card_number"
                                value=""
                                autocomplete="cc-number"
                                inputmode="numeric"
                                maxlength="23"
                                placeholder="4242 4242 4242 4242"
                            >
                            <?php if ($numberError !== ''): ?>
                                <p class="field-error" role="alert"><?= e($numberError) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="form-row form-row-split">
                            <?php $expiryError = payment_field_error($errors, 'expiry'); ?>
                            <div class="<?= $expiryError !== '' ? 'has-error' : '' ?>">
                                <label for="expiry">Expiry (MM/YY)</label>
                                <input
                                    type="text"
                                    id="expiry"
                                    name="expiry"
                                    value=""
                                    autocomplete="cc-exp"
                                    placeholder="12/29"
                                    maxlength="7"
                                >
                                <?php if ($expiryError !== ''): ?>
                                    <p class="field-error" role="alert"><?= e($expiryError) ?></p>
                                <?php endif; ?>
                            </div>

                            <?php $cvvError = payment_field_error($errors, 'cvv'); ?>
                            <div class="<?= $cvvError !== '' ? 'has-error' : '' ?>">
                                <label for="cvv">Security code</label>
                                <input
                                    type="text"
                                    id="cvv"
                                    name="cvv"
                                    value=""
                                    autocomplete="cc-csc"
                                    inputmode="numeric"
                                    maxlength="4"
                                    placeholder="123"
                                >
                                <?php if ($cvvError !== ''): ?>
                                    <p class="field-error" role="alert"><?= e($cvvError) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <p class="form-hint">
                            This store uses a simulated card processor. Use
                            <strong>4242 4242 4242 4242</strong> to approve a
                            payment; any other valid card number is declined.
                            No card details are stored.
                        </p>
                    </div>

                    <?php // FR-4.13: the bKash instruction panel and the wallet fields. ?>
                    <?php if (in_array(WalletPayment::METHOD_BKASH, $walletMethods, true)): ?>
                        <div class="wallet-fields" id="bkash-fields" <?= $isBkash ? '' : 'hidden' ?>>
                            <?php $bkash = $walletContext[WalletPayment::METHOD_BKASH] ?? array(); ?>

                            <div class="wallet-instructions">
                                <h3 class="wallet-instructions-title">Pay with bKash</h3>

                                <p class="wallet-step">
                                    <span class="wallet-step-number">1</span>
                                    Send <strong><?= e(format_money($total)) ?></strong> to the
                                    bKash merchant number
                                    <strong class="wallet-number"><?= e((string) ($bkash['merchant_number'] ?? '')) ?></strong>
                                </p>

                                <p class="wallet-step">
                                    <span class="wallet-step-number">2</span>
                                    Open the bKash app on your phone, choose
                                    <strong>Send Money</strong>, or dial
                                    <strong class="wallet-number"><?= e((string) ($bkash['dial_code'] ?? '*247#')) ?></strong>
                                    and choose <strong>Merchant Payment</strong>.
                                </p>

                                <p class="wallet-step">
                                    <span class="wallet-step-number">3</span>
                                    Enter the merchant number above, enter the amount
                                    <strong><?= e(format_money($total)) ?></strong>, and use this
                                    reference:
                                    <strong class="wallet-reference"><?= e($referenceCode) ?></strong>
                                </p>

                                <p class="wallet-step">
                                    <span class="wallet-step-number">4</span>
                                    Take the Transaction ID (TrxID) from the receipt and
                                    enter it below, together with the number you paid from.
                                </p>

                                <p class="wallet-note">
                                    We check every payment by hand before your order is
                                    confirmed, so your order may show as
                                    <strong>awaiting payment verification</strong> for a short
                                    while.
                                </p>
                            </div>

                            <?php $senderError = payment_field_error($errors, 'sender_number'); ?>
                            <div class="form-row<?= $senderError !== '' ? ' has-error' : '' ?>">
                                <label for="bkash_sender_number">Sender number</label>
                                <input
                                    type="text"
                                    id="bkash_sender_number"
                                    name="sender_number"
                                    value=""
                                    inputmode="numeric"
                                    maxlength="20"
                                    placeholder="01712345678"
                                >
                                <?php if ($senderError !== ''): ?>
                                    <p class="field-error" role="alert"><?= e($senderError) ?></p>
                                <?php endif; ?>
                            </div>

                            <?php $trxError = payment_field_error($errors, 'transaction_id'); ?>
                            <div class="form-row<?= $trxError !== '' ? ' has-error' : '' ?>">
                                <label for="bkash_transaction_id">Transaction ID (TrxID)</label>
                                <input
                                    type="text"
                                    id="bkash_transaction_id"
                                    name="transaction_id"
                                    value=""
                                    maxlength="64"
                                    placeholder="8NPL23456ABC"
                                >
                                <?php if ($trxError !== ''): ?>
                                    <p class="field-error" role="alert"><?= e($trxError) ?></p>
                                <?php endif; ?>
                            </div>

                            <input type="hidden" name="reference_code" value="<?= e($referenceCode) ?>">
                        </div>
                    <?php endif; ?>

                    <?php // FR-4.13: the Nagad instruction panel and the wallet fields. ?>
                    <?php if (in_array(WalletPayment::METHOD_NAGAD, $walletMethods, true)): ?>
                        <div class="wallet-fields" id="nagad-fields" <?= $isNagad ? '' : 'hidden' ?>>
                            <?php $nagad = $walletContext[WalletPayment::METHOD_NAGAD] ?? array(); ?>

                            <div class="wallet-instructions">
                                <h3 class="wallet-instructions-title">Pay with Nagad</h3>

                                <p class="wallet-step">
                                    <span class="wallet-step-number">1</span>
                                    Send <strong><?= e(format_money($total)) ?></strong> to the
                                    Nagad merchant number
                                    <strong class="wallet-number"><?= e((string) ($nagad['merchant_number'] ?? '')) ?></strong>
                                </p>

                                <p class="wallet-step">
                                    <span class="wallet-step-number">2</span>
                                    Open the Nagad app on your phone, choose
                                    <strong>Send Money</strong>, or dial
                                    <strong class="wallet-number"><?= e((string) ($nagad['dial_code'] ?? '*167#')) ?></strong>
                                    and choose <strong>Merchant Pay</strong>.
                                </p>

                                <p class="wallet-step">
                                    <span class="wallet-step-number">3</span>
                                    Enter the merchant number above, enter the amount
                                    <strong><?= e(format_money($total)) ?></strong>, and use this
                                    reference:
                                    <strong class="wallet-reference"><?= e($referenceCode) ?></strong>
                                </p>

                                <p class="wallet-step">
                                    <span class="wallet-step-number">4</span>
                                    Take the Transaction ID (TrxID) from the receipt and
                                    enter it below, together with the number you paid from.
                                </p>

                                <p class="wallet-note">
                                    We check every payment by hand before your order is
                                    confirmed, so your order may show as
                                    <strong>awaiting payment verification</strong> for a short
                                    while.
                                </p>
                            </div>

                            <?php $nagadSenderError = payment_field_error($errors, 'sender_number'); ?>
                            <div class="form-row<?= $nagadSenderError !== '' ? ' has-error' : '' ?>">
                                <label for="nagad_sender_number">Sender number</label>
                                <input
                                    type="text"
                                    id="nagad_sender_number"
                                    name="nagad_sender_number"
                                    value=""
                                    inputmode="numeric"
                                    maxlength="20"
                                    placeholder="01712345678"
                                >
                                <?php if ($nagadSenderError !== ''): ?>
                                    <p class="field-error" role="alert"><?= e($nagadSenderError) ?></p>
                                <?php endif; ?>
                            </div>

                            <?php $nagadTrxError = payment_field_error($errors, 'transaction_id'); ?>
                            <div class="form-row<?= $nagadTrxError !== '' ? ' has-error' : '' ?>">
                                <label for="nagad_transaction_id">Transaction ID (TrxID)</label>
                                <input
                                    type="text"
                                    id="nagad_transaction_id"
                                    name="nagad_transaction_id"
                                    value=""
                                    maxlength="64"
                                    placeholder="7CYX123456789"
                                >
                                <?php if ($nagadTrxError !== ''): ?>
                                    <p class="field-error" role="alert"><?= e($nagadTrxError) ?></p>
                                <?php endif; ?>
                            </div>

                            <input type="hidden" name="reference_code" value="<?= e($referenceCode) ?>">
                        </div>
                    <?php endif; ?>
                </fieldset>

                <div class="checkout-submit">
                    <button type="submit" class="button">Place my order</button>
                    <a class="button button-secondary" href="<?= e(APP_URL) ?>/cart">Back to my cart</a>
                </div>
            </form>
        </div>

        <aside class="checkout-summary">
            <?php // FR-4.1: the order summary. ?>
            <h2>Order summary</h2>

            <ul class="summary-items">
                <?php foreach ($items as $item): ?>
                    <li class="summary-item">
                        <span class="summary-item-name">
                            <?= e($item['name']) ?>
                            <span class="summary-item-quantity">&times; <?= e((string) $item['quantity']) ?></span>
                        </span>
                        <span class="summary-item-total"><?= e(format_money($item['line_total'])) ?></span>
                        <span class="summary-item-price"><?= e(format_money($item['unit_price'])) ?> each</span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="summary-total">
                <span class="summary-total-label">Total</span>
                <span class="summary-total-value" id="summary-total"><?= e(format_money($total)) ?></span>
            </p>

            <p class="summary-note">
                The total shown here is confirmed again when you place the
                order. If a price changes in the meantime, we will ask you to
                approve the new total first.
            </p>
        </aside>
    </div>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
