<?php
/**
 * GreenCorner — User Dashboard (P-11)
 *
 * Implements:
 *   FR-5.1  the greeting by name, and the recent orders with their status
 *   FR-5.3  quick links to the product listing and the cart
 *   FR-5.4  the empty-order-history state
 *   FR-5.7  a cancelled order stays visible with its explanation
 *
 * Expects:
 *   $user      array<string, mixed>  The signed-in account.
 *   $cartCount int                   The number of items in the cart.
 *   $orders    array<int, array<string, mixed>>  The recent orders.
 *   $user      array<string, mixed>  The signed-in account.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

$pageTitle = 'Your dashboard';

require __DIR__ . '/../layouts/header.php';
?>

<section class="dashboard">
    <h1>Welcome, <?= e($user['full_name']) ?></h1>

    <div class="quick-links">
        <?php // FR-5.3: the quick links, with the current cart count. ?>
        <a class="button" href="<?= e(APP_URL) ?>/products">Browse products</a>
        <a class="button button-secondary" href="<?= e(APP_URL) ?>/cart">
            Open cart (<?= e((string) $cartCount) ?>)
        </a>
    </div>

    <h2>Recent orders</h2>

    <?php if ($orders === array()): ?>
        <?php // FR-5.4: the empty-order-history state. ?>
        <div class="empty-state">
            <h3>You have no orders yet</h3>
            <p>
                Once you place an order it will appear here with its current
                status.
            </p>
            <a class="button button-secondary" href="<?= e(APP_URL) ?>/products">
                Browse products
            </a>
        </div>
    <?php else: ?>
        <table class="orders-table">
            <caption class="visually-hidden">Your recent orders</caption>
            <thead>
                <tr>
                    <th scope="col">Order number</th>
                    <th scope="col">Date</th>
                    <th scope="col">Total</th>
                    <th scope="col">Order status</th>
                    <th scope="col">Payment status</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <?php
                    $isCancelled = (string) $order['status'] === 'cancelled';
                    $paymentStatus = (string) ($order['payment_status'] ?? '');

                    // FR-4.17: a wallet payment awaiting verification is
                    // never labelled as paid.
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
                    <tr class="order-row<?= $isCancelled ? ' order-row-cancelled' : '' ?>">
                        <td>
                            <a href="<?= e(APP_URL) ?>/orders/<?= e((string) $order['id']) ?>">
                                <?= e((string) $order['order_number']) ?>
                            </a>
                        </td>
                        <td><?= e(date('j M Y', strtotime((string) $order['created_at']))) ?></td>
                        <td><?= e(format_money($order['total_amount'])) ?></td>
                        <td>
                            <span class="order-status order-status-<?= e((string) $order['status']) ?>">
                                <?= e(ucfirst(str_replace('_', ' ', (string) $order['status']))) ?>
                            </span>
                            <?php if ($isCancelled): ?>
                                <span class="order-flag">
                                    This order was cancelled by the store. No payment is required.
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="payment-status payment-status-<?= e($paymentStatus !== '' ? $paymentStatus : 'unknown') ?>">
                                <?= e($statusLabel) ?>
                            </span>
                        </td>
                        <td>
                            <a class="button button-small button-secondary" href="<?= e(APP_URL) ?>/orders/<?= e((string) $order['id']) ?>">
                                View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="dashboard-orders-link">
            <a href="<?= e(APP_URL) ?>/orders">See all your orders</a>
        </p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
