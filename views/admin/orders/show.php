<?php
/**
 * GreenCorner — Admin Order Detail (P-18)
 *
 * Implements:
 *   FR-6.8  order items, customer, delivery, fulfilment and payment details
 *   FR-6.9  Admin confirmation of COD collection
 *   FR-6.10 the bKash and Nagad payment details, the approve and reject
 *           actions, and the refund information
 *   FR-4.14 the paid-but-unfulfillable case and the refund it opens
 *   FR-4.8  immutable delivery-address snapshot
 *   FR-4.16 payment status from the `payments` table
 *
 * Expects:
 *   $adminOrder                 array<string, mixed>  The order row.
 *   $adminOrderItems            array<int, array<string, mixed>>  The order items.
 *   $adminOrderPayment          array<string, mixed>|null  The payment row.
 *   $adminOrderTargets          array<int, string>  Permitted fulfilment statuses.
 *   $adminOrderCanConfirmCod    bool  Whether COD collection can be confirmed.
 *   $adminOrderWallet           array<string, mixed>|null  The wallet submission.
 *   $adminOrderRefunds          array<int, array<string, mixed>>  The refunds.
 *   $adminOrderCanVerifyWallet  bool  Whether the wallet payment can be acted on.
 *   $adminOrderErrors           array<string, string>  Field errors, if any.
 *   $adminOrderRequestedStatus  string  Refused status, if any.
 */

require_once dirname(__DIR__, 3) . '/helpers/functions.php';
require_once dirname(__DIR__, 3) . '/helpers/csrf.php';
require_once dirname(__DIR__, 3) . '/models/WalletPayment.php';
require_once dirname(__DIR__, 3) . '/models/Refund.php';

$pageTitle = 'Order ' . $adminOrder['order_number'];
$adminSection = 'orders';

$orderStatus = (string) $adminOrder['status'];
$paymentStatus = (string) ($adminOrderPayment['status'] ?? '');
$paymentMethod = (string) ($adminOrderPayment['method'] ?? '');
$statusError = $adminOrderErrors['status'] ?? '';
$paymentError = $adminOrderErrors['payment'] ?? '';
$refundError = $adminOrderErrors['refund'] ?? '';
$csrfError = $adminOrderErrors['_csrf'] ?? '';
$adminOrderTargets = isset($adminOrderTargets) && is_array($adminOrderTargets)
    ? $adminOrderTargets
    : array();
$adminOrderCanConfirmCod = isset($adminOrderCanConfirmCod) && $adminOrderCanConfirmCod === true;
$adminOrderRequestedStatus = isset($adminOrderRequestedStatus) ? (string) $adminOrderRequestedStatus : '';
$adminOrderWallet = isset($adminOrderWallet) && is_array($adminOrderWallet) ? $adminOrderWallet : null;
$adminOrderRefunds = isset($adminOrderRefunds) && is_array($adminOrderRefunds) ? $adminOrderRefunds : array();
$adminOrderCanVerifyWallet = isset($adminOrderCanVerifyWallet) && $adminOrderCanVerifyWallet === true;
$isCancelled = $orderStatus === 'cancelled';
$isPaidUnfulfillable = $orderStatus === Refund::ORDER_PAID_UNFULFILLABLE;

// FR-4.2 / FR-4.16: the label of the payment method.
$paymentMethodLabel = 'None';

if ($paymentMethod === 'cod') {
    $paymentMethodLabel = 'Cash on delivery';
} elseif ($paymentMethod === 'card') {
    $paymentMethodLabel = 'Card';
} elseif (WalletPayment::isWalletMethod($paymentMethod)) {
    $paymentMethodLabel = WalletPayment::methodLabel($paymentMethod);
}

// FR-4.16: the label of the payment status. A wallet payment that is
// still being checked is NOT a success, and is never labelled as one.
$paymentStatusLabel = 'None';

if ($paymentStatus === 'success') {
    $paymentStatusLabel = 'Paid';
} elseif ($paymentStatus === 'pending') {
    $paymentStatusLabel = 'Pending — pay on delivery';
} elseif ($paymentStatus === 'pending_verification') {
    $paymentStatusLabel = 'Awaiting verification';
} elseif ($paymentStatus === 'failed') {
    $paymentStatusLabel = 'Failed';
}

require __DIR__ . '/../../layouts/header.php';
?>

<?php require __DIR__ . '/../../layouts/nav.php'; ?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(APP_URL) ?>/admin/dashboard">Admin</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(APP_URL) ?>/admin/orders">Orders</a>
    <span aria-hidden="true">/</span>
    <span><?= e((string) $adminOrder['order_number']) ?></span>
</nav>

<section class="admin-panel order-detail">
    <h1>Order <?= e((string) $adminOrder['order_number']) ?></h1>

    <?php if ($csrfError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= e($csrfError) ?></div>
    <?php endif; ?>

    <?php if ($statusError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= e($statusError) ?></div>
    <?php endif; ?>

    <?php if ($paymentError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= e($paymentError) ?></div>
    <?php endif; ?>

    <?php if ($refundError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= e($refundError) ?></div>
    <?php endif; ?>

    <?php if ($isCancelled): ?>
        <div class="alert alert-notice" role="status">
            This order was cancelled by the store. No payment is required.
        </div>
    <?php endif; ?>

    <?php if ($isPaidUnfulfillable): ?>
        <div class="alert alert-notice" role="status">
            This order was paid for, but it could not be supplied because the
            product was no longer available. The customer is being refunded.
        </div>
    <?php endif; ?>

    <div class="order-detail-layout">
        <div class="order-detail-main">
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
                    <?php foreach ($adminOrderItems as $item): ?>
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
                        <td><strong><?= e(format_money($adminOrder['total_amount'])) ?></strong></td>
                    </tr>
                </tfoot>
            </table>

            <?php // FR-6.10: the wallet payment details and the actions. ?>
            <?php if ($adminOrderWallet !== null): ?>
                <h2><?= e(WalletPayment::methodLabel((string) $adminOrderWallet['method'])) ?> payment</h2>

                <dl class="order-summary-facts">
                    <div>
                        <dt>Sender number</dt>
                        <dd class="order-number"><?= e((string) $adminOrderWallet['sender_number']) ?></dd>
                    </div>
                    <div>
                        <dt>Transaction ID</dt>
                        <dd class="order-number"><?= e((string) $adminOrderWallet['transaction_id']) ?></dd>
                    </div>
                    <div>
                        <dt>Amount sent</dt>
                        <dd><?= e(format_money($adminOrderWallet['amount'])) ?></dd>
                    </div>
                    <div>
                        <dt>Order reference</dt>
                        <dd class="order-number"><?= e((string) $adminOrderWallet['reference_code']) ?></dd>
                    </div>
                    <div>
                        <dt>Verification status</dt>
                        <dd>
                            <span class="payment-status payment-status-<?= e((string) $adminOrderWallet['status']) ?>">
                                <?= e(ucfirst(str_replace('_', ' ', (string) $adminOrderWallet['status']))) ?>
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt>Submitted on</dt>
                        <dd><?= e(date('j F Y, H:i', strtotime((string) $adminOrderWallet['created_at']))) ?></dd>
                    </div>
                    <?php if (!empty($adminOrderWallet['verified_at'])): ?>
                        <div>
                            <dt>Verified on</dt>
                            <dd><?= e(date('j F Y, H:i', strtotime((string) $adminOrderWallet['verified_at']))) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($adminOrderWallet['verified_by'])): ?>
                        <div>
                            <dt>Verified by</dt>
                            <dd>Admin #<?= e((string) $adminOrderWallet['verified_by']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($adminOrderWallet['rejection_reason'])): ?>
                        <div>
                            <dt>Rejection reason</dt>
                            <dd><?= e((string) $adminOrderWallet['rejection_reason']) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>

                <?php if ($adminOrderCanVerifyWallet): ?>
                    <p class="panel-intro">
                        Check the store's <?= e(WalletPayment::methodLabel((string) $adminOrderWallet['method'])) ?>
                        statement against the sender number, the transaction id and
                        the amount above. Approving marks the payment as paid.
                        Rejecting refuses it and records your reason.
                    </p>

                    <form class="admin-action-form" method="post" action="<?= e(APP_URL) ?>/admin/orders/<?= e((string) $adminOrder['id']) ?>/verify-payment">
                        <?= csrf_field() ?>
                        <button type="submit" class="button">Approve this payment</button>
                    </form>

                    <form class="admin-action-form" method="post" action="<?= e(APP_URL) ?>/admin/orders/<?= e((string) $adminOrder['id']) ?>/reject-payment" novalidate>
                        <?= csrf_field() ?>
                        <div class="form-row">
                            <label for="rejection_reason">Reason for rejecting</label>
                            <input
                                type="text"
                                id="rejection_reason"
                                name="rejection_reason"
                                value=""
                                maxlength="255"
                            >
                        </div>
                        <button type="submit" class="button button-danger">Reject this payment</button>
                    </form>
                <?php elseif ((string) $adminOrderWallet['status'] === WalletPayment::STATUS_VERIFIED): ?>
                    <p class="panel-intro">
                        This payment was verified. It cannot be approved or rejected again.
                    </p>
                <?php else: ?>
                    <p class="panel-intro">
                        This payment was rejected. It cannot be approved or rejected again.
                    </p>
                <?php endif; ?>

                <?php // FR-4.14: the paid-but-unfulfillable case. ?>
                <?php if (
                    $paymentStatus === 'success'
                    && !$isPaidUnfulfillable
                    && !$isCancelled
                    && WalletPayment::isWalletMethod($paymentMethod)
                ): ?>
                    <h3>Cannot supply this order</h3>
                    <p class="panel-intro">
                        If the product is no longer available, mark the order as
                        paid but unfulfillable and open a full refund. The refund
                        stays pending until the money has actually been returned.
                    </p>
                    <form class="admin-action-form" method="post" action="<?= e(APP_URL) ?>/admin/orders/<?= e((string) $adminOrder['id']) ?>/refund-unfulfillable">
                        <?= csrf_field() ?>
                        <button type="submit" class="button button-danger">Refund in full and mark unfulfillable</button>
                    </form>
                <?php endif; ?>

                <?php // FR-4.15 / FR-6.12: the refund history. ?>
                <?php if ($adminOrderRefunds !== array()): ?>
                    <h3>Refunds</h3>

                    <table class="order-items-table">
                        <caption class="visually-hidden">Refunds for this order</caption>
                        <thead>
                            <tr>
                                <th scope="col">Amount</th>
                                <th scope="col">Status</th>
                                <th scope="col">Reason</th>
                                <th scope="col">Confirmed on</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adminOrderRefunds as $refund): ?>
                                <tr>
                                    <td><?= e(format_money($refund['amount'])) ?></td>
                                    <td>
                                        <span class="payment-status payment-status-<?= e((string) $refund['status']) ?>">
                                            <?= e(Refund::label((string) $refund['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= e((string) $refund['reason']) ?>
                                        <?php if (!empty($refund['failure_detail'])): ?>
                                            <span class="order-flag">
                                                <?= e((string) $refund['failure_detail']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($refund['confirmed_at'])): ?>
                                            <?= e(date('j F Y, H:i', strtotime((string) $refund['confirmed_at']))) ?>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php foreach ($adminOrderRefunds as $refund): ?>
                        <?php if ((string) $refund['status'] !== Refund::STATUS_REFUNDED): ?>
                            <form class="admin-action-form" method="post" action="<?= e(APP_URL) ?>/admin/orders/<?= e((string) $adminOrder['id']) ?>/confirm-refund" novalidate>
                                <?= csrf_field() ?>
                                <input type="hidden" name="refund_id" value="<?= e((string) $refund['id']) ?>">
                                <div class="form-row">
                                    <label for="gateway_reference_<?= e((string) $refund['id']) ?>">
                                        Provider reference for this refund
                                    </label>
                                    <input
                                        type="text"
                                        id="gateway_reference_<?= e((string) $refund['id']) ?>"
                                        name="gateway_reference"
                                        value=""
                                        maxlength="190"
                                    >
                                    <p class="form-hint">
                                        A refund is only marked as returned once you
                                        enter the reference the payment provider gave.
                                    </p>
                                </div>
                                <button type="submit" class="button">Confirm the refund was returned</button>
                            </form>

                            <form class="admin-action-form" method="post" action="<?= e(APP_URL) ?>/admin/orders/<?= e((string) $adminOrder['id']) ?>/fail-refund" novalidate>
                                <?= csrf_field() ?>
                                <input type="hidden" name="refund_id" value="<?= e((string) $refund['id']) ?>">
                                <div class="form-row">
                                    <label for="failure_detail_<?= e((string) $refund['id']) ?>">
                                        What went wrong with the refund?
                                    </label>
                                    <input
                                        type="text"
                                        id="failure_detail_<?= e((string) $refund['id']) ?>"
                                        name="failure_detail"
                                        value=""
                                        maxlength="255"
                                    >
                                </div>
                                <button type="submit" class="button button-danger">Flag this refund as failed</button>
                            </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <aside class="order-detail-sidebar" aria-label="Order details">
            <h2>Order details</h2>

            <dl class="order-summary-facts">
                <div>
                    <dt>Customer</dt>
                    <dd>
                        <?= e((string) ($adminOrder['customer_name'] ?? '')) ?>
                        <?php if (!empty($adminOrder['customer_email'])): ?>
                            <span class="order-fact-secondary">&lt;<?= e((string) $adminOrder['customer_email']) ?>&gt;</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Placed on</dt>
                    <dd><?= e(date('j F Y, H:i', strtotime((string) $adminOrder['created_at']))) ?></dd>
                </div>
                <div>
                    <dt>Fulfilment status</dt>
                    <dd>
                        <span class="order-status order-status-<?= e($orderStatus) ?>">
                            <?= e(ucfirst($orderStatus)) ?>
                        </span>
                    </dd>
                </div>
                <div>
                    <dt>Payment method</dt>
                    <dd><?= e($paymentMethodLabel) ?></dd>
                </div>
                <div>
                    <dt>Payment status</dt>
                    <dd>
                        <span class="payment-status payment-status-<?= e($paymentStatus !== '' ? $paymentStatus : 'unknown') ?>">
                            <?= e($paymentStatusLabel) ?>
                        </span>
                    </dd>
                </div>
                <div>
                    <dt>Total</dt>
                    <dd><strong><?= e(format_money($adminOrder['total_amount'])) ?></strong></dd>
                </div>
            </dl>

            <h3>Delivery address</h3>
            <?php // Show the immutable snapshot taken when the order was placed. ?>
            <address class="order-address">
                <?= e((string) $adminOrder['delivery_name']) ?><br>
                <?= e((string) $adminOrder['delivery_address']) ?><br>
                <?= e((string) $adminOrder['delivery_city']) ?><br>
                Phone: <?= e((string) $adminOrder['delivery_phone']) ?>
            </address>

            <h3>Update the fulfilment status</h3>
            <?php if ($adminOrderTargets === array()): ?>
                <p class="panel-intro">
                    This order is cancelled, and a cancelled order cannot change status.
                </p>
            <?php else: ?>
                <form method="post" action="<?= e(APP_URL) ?>/admin/orders/<?= e((string) $adminOrder['id']) ?>/status" novalidate>
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="status">New status</label>
                        <select id="status" name="status" required>
                            <?php foreach ($adminOrderTargets as $target): ?>
                                <option
                                    value="<?= e($target) ?>"
                                    <?= $adminOrderRequestedStatus === $target ? 'selected' : '' ?>
                                >
                                    <?= e(ucfirst($target)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($statusError !== ''): ?>
                            <p class="field-error" role="alert"><?= e($statusError) ?></p>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="button">Update status</button>
                </form>
            <?php endif; ?>

            <h3>Cash on delivery</h3>
            <?php if ($adminOrderCanConfirmCod): ?>
                <p class="panel-intro">
                    The cash for this order has been collected, and the payment moves
                    from pending to paid.
                </p>
                <form method="post" action="<?= e(APP_URL) ?>/admin/orders/<?= e((string) $adminOrder['id']) ?>/confirm-cod" novalidate>
                    <?= csrf_field() ?>
                    <button type="submit" class="button">Confirm cash collected</button>
                </form>
            <?php elseif ($paymentMethod === 'cod' && $paymentStatus === 'success'): ?>
                <p class="panel-intro">The cash for this order has already been confirmed.</p>
            <?php elseif ($paymentMethod === 'cod'): ?>
                <p class="panel-intro">
                    The cash for this order has not been confirmed yet. Its payment is
                    currently <?= e($paymentStatus !== '' ? $paymentStatus : 'unknown') ?>.
                </p>
            <?php else: ?>
                <p class="panel-intro">
                    This order was not paid by cash on delivery, so there is no cash
                    collection to confirm.
                </p>
            <?php endif; ?>
        </aside>
    </div>

    <div class="order-confirmation-actions">
        <a class="button button-secondary" href="<?= e(APP_URL) ?>/admin/orders">Back to all orders</a>
    </div>
</section>

<?php require __DIR__ . '/../../layouts/footer.php'; ?>
