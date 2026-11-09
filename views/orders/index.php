<?php
/**
 * GreenCorner — Customer order list (P-11)
 *
 * Implements:
 *   FR-5.1  the customer's orders, most recent first, with their number,
 *            date, total and status
 *   FR-5.3  quick links to the product listing and the cart
 *   FR-5.4  the empty-order-history state
 *   FR-5.7  a cancelled order stays visible with its explanation
 *
 * Expects:
 *   $orders    array<int, array<string, mixed>>  The customer's orders.
 *   $page      int   The current page.
 *   $lastPage  int   The last page.
 *   $cartCount int   The number of items in the cart.
 *   $user      array<string, mixed>  The signed-in account.
 */

require_once dirname(__DIR__, 2) . '/helpers/functions.php';
require_once dirname(__DIR__, 2) . '/models/WalletPayment.php';

$pageTitle = 'Your orders';

require __DIR__ . '/../layouts/header.php';
?>

<section class="dashboard">
    <h1>Your orders</h1>

    <?php if ($orders === array()): ?>
        <?php // FR-5.4: the empty-order-history state. ?>
        <div class="empty-state">
            <h2>You have no orders yet</h2>
            <p>
                Once you place an order it will appear here with its number,
                date, total and current status.
            </p>
            <a class="button" href="<?= e(APP_URL) ?>/products">Browse products</a>
        </div>
    <?php else: ?>
        <table class="orders-table">
            <caption class="visually-hidden">Your orders</caption>
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
                    $method = (string) ($order['payment_method'] ?? '');

                    // FR-4.17: a wallet payment awaiting verification reads
                    // differently from a paid one. It is never labelled as
                    // paid before the store has checked it.
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
                                <?php // FR-5.7: the explanation is shown with the cancelled status. ?>
                                <span class="order-flag">
                                    This order was cancelled by the store. No payment is required.
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="payment-status payment-status-<?= e($paymentStatus !== '' ? $paymentStatus : 'unknown') ?>">
                                <?= e($statusLabel) ?>
                            </span>
                            <?php if ($method === 'cod'): ?>
                                <span class="order-flag">Cash on delivery</span>
                            <?php elseif (WalletPayment::isWalletMethod($method)): ?>
                                <span class="order-flag"><?= e(WalletPayment::methodLabel($method)) ?></span>
                            <?php endif; ?>
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

        <?php if ($lastPage > 1): ?>
            <nav class="pagination" aria-label="Order pages">
                <?php if ($page > 1): ?>
                    <a class="pagination-previous" href="<?= e(APP_URL) ?>/orders?page=<?= e((string) ($page - 1)) ?>">
                        Previous
                    </a>
                <?php endif; ?>

                <span class="pagination-status">Page <?= e((string) $page) ?> of <?= e((string) $lastPage) ?></span>

                <?php if ($page < $lastPage): ?>
                    <a class="pagination-next" href="<?= e(APP_URL) ?>/orders?page=<?= e((string) ($page + 1)) ?>">
                        Next
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

    <?php // FR-5.3: the quick links. ?>
    <div class="quick-links">
        <a class="button" href="<?= e(APP_URL) ?>/products">Browse products</a>
        <a class="button button-secondary" href="<?= e(APP_URL) ?>/cart">
            Open cart (<?= e((string) $cartCount) ?>)
        </a>
    </div>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
