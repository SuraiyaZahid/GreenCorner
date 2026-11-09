<?php
/**
 * GreenCorner — Admin Orders List (P-17)
 *
 * Implements:
 *   FR-6.7  every order is listed with the order number, the customer, the
 *           date, the fulfilment status, the total and the payment status;
 *           the payment status is shown distinctly, so a COD payment that
 *           is still pending is distinguishable from a card payment that
 *           has succeeded; cancelled orders remain listed
 *   FR-4.16 the payment status is read from the `payments` table
 *
 * Expects:
 *   $orders    array<int, array<string, mixed>>  The orders on this page.
 *   $page      int                               The current page.
 *   $lastPage  int                               The last valid page.
 *   $query     array<string, string>              The query parameters to keep.
 */

require_once dirname(__DIR__, 3) . '/helpers/functions.php';
require_once dirname(__DIR__, 3) . '/models/WalletPayment.php';

$pageTitle = 'Orders';
$adminSection = 'orders';

require __DIR__ . '/../../layouts/header.php';
?>

<?php require __DIR__ . '/../../layouts/nav.php'; ?>

<section class="admin-panel">
    <div class="admin-panel-header">
        <div>
            <h1>Orders</h1>
            <p class="panel-intro">
                <?= e((string) $totalResults) ?>
                <?= $totalResults === 1 ? 'order' : 'orders' ?> in total
                &middot; page <?= e((string) $page) ?> of <?= e((string) $lastPage) ?>
            </p>
        </div>
    </div>

    <?php if ($orders === array()): ?>
        <div class="empty-state">
            <h2>No orders yet</h2>
            <p>Orders appear here as soon as a customer places one.</p>
        </div>
    <?php else: ?>
        <table class="orders-table admin-orders-table">
            <caption class="visually-hidden">All orders</caption>
            <thead>
                <tr>
                    <th scope="col">Order number</th>
                    <th scope="col">Customer</th>
                    <th scope="col">Date</th>
                    <th scope="col">Fulfilment status</th>
                    <th scope="col">Total</th>
                    <?php // FR-6.7: the payment status is a separate column, so
                          // the two statuses are never confused. ?>
                    <th scope="col">Payment status</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <?php
                    $orderStatus = (string) $order['status'];
                    $paymentStatus = (string) ($order['payment_status'] ?? '');
                    $paymentMethod = (string) ($order['payment_method'] ?? '');

                    // FR-6.7: the method and the status are both shown, so a
                    // wallet payment awaiting verification reads differently
                    // from a card payment that has succeeded.
                    $methodLabel = '';

                    if ($paymentMethod === 'cod') {
                        $methodLabel = 'Cash on delivery';
                    } elseif ($paymentMethod === 'card') {
                        $methodLabel = 'Card';
                    } elseif (WalletPayment::isWalletMethod($paymentMethod)) {
                        $methodLabel = WalletPayment::methodLabel($paymentMethod);
                    }

                    $statusLabel = 'None';

                    if ($paymentStatus === 'success') {
                        $statusLabel = 'Paid';
                    } elseif ($paymentStatus === 'pending') {
                        $statusLabel = 'Pending';
                    } elseif ($paymentStatus === 'pending_verification') {
                        $statusLabel = 'Awaiting verification';
                    } elseif ($paymentStatus === 'failed') {
                        $statusLabel = 'Failed';
                    }
                    ?>
                    <tr class="order-row<?= $orderStatus === 'cancelled' ? ' order-row-cancelled' : '' ?>">
                        <td>
                            <a href="<?= e(APP_URL) ?>/admin/orders/<?= e((string) $order['id']) ?>">
                                <?= e((string) $order['order_number']) ?>
                            </a>
                        </td>
                        <td><?= e((string) ($order['customer_name'] ?? '')) ?></td>
                        <td><?= e(date('j M Y', strtotime((string) $order['created_at']))) ?></td>
                        <td>
                            <span class="order-status order-status-<?= e($orderStatus) ?>">
                                <?= e(ucfirst(str_replace('_', ' ', $orderStatus))) ?>
                            </span>
                        </td>
                        <td><?= e(format_money($order['total_amount'])) ?></td>
                        <td>
                            <?php // FR-6.7 acceptance criterion 2: a COD
                                  // payment that is pending must read
                                  // differently from a card payment that has
                                  // succeeded. The method is shown next to the
                                  // status for the same reason. ?>
                            <span class="payment-status payment-status-<?= e($paymentStatus !== '' ? $paymentStatus : 'unknown') ?>">
                                <?= e($statusLabel) ?>
                            </span>
                            <?php if ($methodLabel !== ''): ?>
                                <span class="payment-method"><?= e($methodLabel) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="button button-small button-secondary" href="<?= e(APP_URL) ?>/admin/orders/<?= e((string) $order['id']) ?>">
                                View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // The pagination partial builds the URLs from this base path.
        $basePath = '/admin/orders';
        require __DIR__ . '/../../partials/admin_pagination.php';
        ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../layouts/footer.php'; ?>
