<?php
/**
 * GreenCorner — Order Confirmation page (P-10)
 *
 * Implements:
 *   FR-4.13  the order number, the itemised summary, the total, the payment
 *            method and the payment status, with a link to the dashboard
 *   FR-4.17  the payment method, the order reference, the payment
 *            verification status, the order status and the refund status
 *            are all shown, with a meaningful message for each state
 *   FR-5.7   a cancelled order keeps its items and its explanation
 *
 * Expects:
 *   $order              array<string, mixed>       The order row.
 *   $items              array<int, array<string, mixed>>  The order items.
 *   $confirmationWallet  array<string, mixed>|null  The wallet submission.
 *   $confirmationRefunds array<int, array<string, mixed>>  The refunds.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';
require_once dirname(__DIR__, 2) . '/models/WalletPayment.php';
require_once dirname(__DIR__, 2) . '/models/Refund.php';

$pageTitle = 'Order ' . $order['order_number'];

$paymentMethod = (string) ($order['payment_method'] ?? '');
$paymentStatus = (string) ($order['payment_status'] ?? '');
$isCancelled = (string) $order['status'] === 'cancelled';
$isPaidUnfulfillable = (string) $order['status'] === Refund::ORDER_PAID_UNFULFILLABLE;

$confirmationWallet = isset($confirmationWallet) && is_array($confirmationWallet) ? $confirmationWallet : null;
$confirmationRefunds = isset($confirmationRefunds) && is_array($confirmationRefunds) ? $confirmationRefunds : array();

// FR-4.2 / FR-4.16: the label of the payment method.
$methodLabel = 'None';

if ($paymentMethod === 'cod') {
    $methodLabel = 'Cash on delivery';
} elseif ($paymentMethod === 'card') {
    $methodLabel = 'Card';
} elseif (WalletPayment::isWalletMethod($paymentMethod)) {
    $methodLabel = WalletPayment::methodLabel($paymentMethod);
}

// FR-4.17: the label of the payment status. A wallet payment that has been
// submitted but not yet checked says so; it never claims to be paid.
$statusLabel = 'None';

if ($paymentStatus === 'success') {
    $statusLabel = 'Paid';
} elseif ($paymentStatus === 'pending') {
    $statusLabel = 'Pending — pay on delivery';
} elseif ($paymentStatus === 'pending_verification') {
    $statusLabel = 'Awaiting verification';
} elseif ($paymentStatus === 'failed') {
    $statusLabel = 'Failed';
}

// FR-4.17: the refund status, when there is a refund.
$refundStatus = '';

if ($confirmationRefunds !== array()) {
    $refundStatus = Refund::label((string) $confirmationRefunds[0]['status']);
}

require __DIR__ . '/../layouts/header.php';
?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(APP_URL) ?>/">Home</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(APP_URL) ?>/dashboard">Dashboard</a>
    <span aria-hidden="true">/</span>
    <span>Order confirmation</span>
</nav>

<section class="order-confirmation">
    <?php if ($isCancelled): ?>
        <?php // FR-5.7: the cancelled order and its explanation. ?>
        <div class="alert alert-error" role="alert">
            <strong>This order was cancelled by the store. No payment is required.</strong>
        </div>
    <?php elseif ($isPaidUnfulfillable): ?>
        <?php // FR-4.14: the money was received, but the order cannot be supplied. ?>
        <div class="alert alert-notice" role="status">
            <strong>Your payment was received, but this order could not be supplied.</strong>
            The product was no longer available. Your money is being returned to
            you, and we will let you know when it has been sent back.
        </div>
    <?php elseif ($paymentStatus === 'pending_verification'): ?>
        <?php // FR-4.17: the message says the payment is being checked. It never
              // claims the payment is confirmed before the Admin has checked it. ?>
        <div class="alert alert-notice" role="status">
            <strong>Your order has been placed and your payment is being checked.</strong>
            We verify every <?= e(strtolower($methodLabel)) ?> payment by hand
            against our wallet statement, so your order may show as awaiting
            verification for a short while. We will let you know as soon as it
            is confirmed.
        </div>
    <?php elseif ($paymentStatus === 'failed'): ?>
        <div class="alert alert-error" role="alert">
            <strong>Your order was placed, but the payment was not accepted.</strong>
            Please contact the store so that we can arrange another way to pay.
        </div>
    <?php else: ?>
        <div class="alert alert-success" role="status">
            <strong>Thank you — your order has been placed.</strong>
            We have received your order and will be in touch about delivery.
        </div>
    <?php endif; ?>

    <h1>Order confirmation</h1>

    <dl class="order-summary-facts">
        <div>
            <dt>Order number</dt>
            <dd><strong class="order-number"><?= e((string) $order['order_number']) ?></strong></dd>
        </div>
        <div>
            <dt>Placed on</dt>
            <dd><?= e(date('j F Y, H:i', strtotime((string) $order['created_at']))) ?></dd>
        </div>
        <div>
            <dt>Payment method</dt>
            <dd><?= e($methodLabel) ?></dd>
        </div>
        <div>
            <dt>Payment status</dt>
            <dd>
                <span class="payment-status payment-status-<?= e($paymentStatus !== '' ? $paymentStatus : 'unknown') ?>">
                    <?= e($statusLabel) ?>
                </span>
            </dd>
        </div>
        <div>
            <dt>Order status</dt>
            <dd>
                <span class="order-status order-status-<?= e((string) $order['status']) ?>">
                    <?= e(ucfirst(str_replace('_', ' ', (string) $order['status']))) ?>
                </span>
            </dd>
        </div>

        <?php // FR-4.17: the reference the customer was shown, and the
              // transaction id they submitted. ?>
        <?php if ($confirmationWallet !== null): ?>
            <div>
                <dt>Order reference</dt>
                <dd><strong class="order-number"><?= e((string) $confirmationWallet['reference_code']) ?></strong></dd>
            </div>
            <div>
                <dt>Transaction ID</dt>
                <dd class="order-number"><?= e((string) $confirmationWallet['transaction_id']) ?></dd>
            </div>
            <div>
                <dt>Payment verification</dt>
                <dd>
                    <span class="payment-status payment-status-<?= e((string) $confirmationWallet['status']) ?>">
                        <?= e(ucfirst(str_replace('_', ' ', (string) $confirmationWallet['status']))) ?>
                    </span>
                </dd>
            </div>
        <?php endif; ?>

        <?php // FR-4.15 / FR-4.17: the refund status, when there is a refund. ?>
        <?php if ($refundStatus !== ''): ?>
            <div>
                <dt>Refund status</dt>
                <dd>
                    <span class="payment-status payment-status-<?= e((string) $confirmationRefunds[0]['status']) ?>">
                        <?= e($refundStatus) ?>
                    </span>
                </dd>
            </div>
        <?php endif; ?>
    </dl>

    <?php if ($confirmationWallet !== null && $paymentStatus === 'pending_verification'): ?>
        <div class="wallet-instructions">
            <h2 class="wallet-instructions-title">
                While we check your payment
            </h2>
            <p class="wallet-step">
                <span class="wallet-step-number">1</span>
                Keep this reference safe: it is how we match your payment against
                our <?= e(strtolower($methodLabel)) ?> statement.
                <strong class="wallet-reference"><?= e((string) $confirmationWallet['reference_code']) ?></strong>
            </p>
            <p class="wallet-step">
                <span class="wallet-step-number">2</span>
                We will check that the amount of
                <strong><?= e(format_money($confirmationWallet['amount'])) ?></strong>
                arrived from
                <strong class="order-number"><?= e((string) $confirmationWallet['sender_number']) ?></strong>.
            </p>
            <p class="wallet-step">
                <span class="wallet-step-number">3</span>
                As soon as we have confirmed it, your order status will change to
                <strong>paid</strong> and we will start preparing it for delivery.
            </p>
        </div>
    <?php endif; ?>

    <h2>Items</h2>

    <table class="order-items-table">
        <caption class="visually-hidden">Items in this order</caption>
        <thead>
            <tr>
                <th scope="col">Product</th>
                <th scope="col">Unit price</th>
                <th scope="col">Quantity</th>
                <th scope="col">Line total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e((string) $item['product_name_at_purchase']) ?></td>
                    <td><?= e(format_money($item['unit_price_at_purchase'])) ?></td>
                    <td><?= e((string) $item['quantity']) ?></td>
                    <td><?= e(format_money(Order::lineTotal($item))) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th scope="row" colspan="3">Total</th>
                <td><strong><?= e(format_money($order['total_amount'])) ?></strong></td>
            </tr>
        </tfoot>
    </table>

    <h2>Delivery address</h2>

    <address class="order-address">
        <?= e((string) $order['delivery_name']) ?><br>
        <?= e((string) $order['delivery_address']) ?><br>
        <?= e((string) $order['delivery_city']) ?><br>
        Phone: <?= e((string) $order['delivery_phone']) ?>
    </address>

    <div class="order-confirmation-actions">
        <?php // FR-4.13: the link to the dashboard. ?>
        <a class="button" href="<?= e(APP_URL) ?>/dashboard">Go to my dashboard</a>
        <a class="button button-secondary" href="<?= e(APP_URL) ?>/products">Continue browsing</a>
    </div>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
