<?php
/**
 * GreenCorner — Customer order detail (P-12)
 *
 * Implements:
 *   FR-5.2  the items, the line totals, the order total, the order date,
 *            the fulfilment status, the payment method, the payment status
 *            and the delivery address
 *   FR-5.7  a cancelled order keeps its items and its explanation
 *
 * Expects:
 *   $order  array<string, mixed>  The order row.
 *   $items  array<int, array<string, mixed>>  The order items.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';
require_once dirname(__DIR__, 2) . '/models/WalletPayment.php';
require_once dirname(__DIR__, 2) . '/models/Refund.php';

$pageTitle = 'Order ' . $order['order_number'];

$paymentMethod = (string) ($order['payment_method'] ?? '');
$paymentStatus = (string) ($order['payment_status'] ?? '');
$isCancelled = (string) $order['status'] === 'cancelled';
$isPaidUnfulfillable = (string) $order['status'] === Refund::ORDER_PAID_UNFULFILLABLE;

$orderWallet = isset($orderWallet) && is_array($orderWallet) ? $orderWallet : null;
$orderRefunds = isset($orderRefunds) && is_array($orderRefunds) ? $orderRefunds : array();

$methodLabel = 'None';

if ($paymentMethod === 'cod') {
    $methodLabel = 'Cash on delivery';
} elseif ($paymentMethod === 'card') {
    $methodLabel = 'Card';
} elseif (WalletPayment::isWalletMethod($paymentMethod)) {
    $methodLabel = WalletPayment::methodLabel($paymentMethod);
}

// FR-4.17: a submitted wallet payment says it is awaiting verification. It
// never claims to be paid before the Admin has checked it.
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

$refundStatus = '';

if ($orderRefunds !== array()) {
    $refundStatus = Refund::label((string) $orderRefunds[0]['status']);
}

require __DIR__ . '/../layouts/header.php';
?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(APP_URL) ?>/">Home</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(APP_URL) ?>/dashboard">Dashboard</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(APP_URL) ?>/orders">Orders</a>
    <span aria-hidden="true">/</span>
    <span><?= e((string) $order['order_number']) ?></span>
</nav>

<section class="order-detail">
    <?php if ($isCancelled): ?>
        <div class="alert alert-error" role="alert">
            <strong>This order was cancelled by the store. No payment is required.</strong>
        </div>
    <?php elseif ($isPaidUnfulfillable): ?>
        <?php // FR-4.14: the money was received but the order cannot be supplied. ?>
        <div class="alert alert-notice" role="status">
            <strong>Your payment was received, but this order could not be supplied.</strong>
            The product was no longer available. Your money is being returned to
            you, and we will let you know when it has been sent back.
        </div>
    <?php elseif ($paymentStatus === 'pending_verification'): ?>
        <?php // FR-4.17: the message says the payment is being checked. ?>
        <div class="alert alert-notice" role="status">
            <strong>Your payment is being checked.</strong>
            We verify every <?= e(strtolower($methodLabel)) ?> payment by hand
            against our wallet statement. We will let you know as soon as it is
            confirmed.
        </div>
    <?php elseif ($paymentStatus === 'failed'): ?>
        <div class="alert alert-error" role="alert">
            <strong>This payment was not accepted.</strong>
            Please contact the store so that we can arrange another way to pay.
        </div>
    <?php endif; ?>

    <h1>Order <?= e((string) $order['order_number']) ?></h1>

    <dl class="order-summary-facts">
        <div>
            <dt>Placed on</dt>
            <dd><?= e(date('j F Y, H:i', strtotime((string) $order['created_at']))) ?></dd>
        </div>
        <div>
            <dt>Order status</dt>
            <dd>
                <span class="order-status order-status-<?= e((string) $order['status']) ?>">
                    <?= e(ucfirst(str_replace('_', ' ', (string) $order['status']))) ?>
                </span>
            </dd>
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

        <?php // FR-4.17: the reference, the transaction id and the
              // verification status of a wallet payment. ?>
        <?php if ($orderWallet !== null): ?>
            <div>
                <dt>Order reference</dt>
                <dd><strong class="order-number"><?= e((string) $orderWallet['reference_code']) ?></strong></dd>
            </div>
            <div>
                <dt>Transaction ID</dt>
                <dd class="order-number"><?= e((string) $orderWallet['transaction_id']) ?></dd>
            </div>
            <div>
                <dt>Payment verification</dt>
                <dd>
                    <span class="payment-status payment-status-<?= e((string) $orderWallet['status']) ?>">
                        <?= e(ucfirst(str_replace('_', ' ', (string) $orderWallet['status']))) ?>
                    </span>
                </dd>
            </div>
        <?php endif; ?>

        <?php // FR-4.15 / FR-4.17: the refund status, when there is a refund. ?>
        <?php if ($refundStatus !== ''): ?>
            <div>
                <dt>Refund status</dt>
                <dd>
                    <span class="payment-status payment-status-<?= e((string) $orderRefunds[0]['status']) ?>">
                        <?= e($refundStatus) ?>
                    </span>
                </dd>
            </div>
        <?php endif; ?>
    </dl>

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
        <a class="button button-secondary" href="<?= e(APP_URL) ?>/orders">Back to my orders</a>
    </div>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
