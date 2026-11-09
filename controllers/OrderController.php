<?php
/**
 * GreenCorner — Order Controller
 *
 * Serves the customer's order history (P-11) and the order detail page
 * (P-12).
 *
 * Implements:
 *   FR-5.1  the customer's orders, most recent first, with their status
 *   FR-5.2  the order detail page; another customer's order is refused with
 *           HTTP 403
 *   FR-5.3  quick links to the product listing and the cart
 *   FR-5.4  the empty-order-history state
 *   FR-5.7  a cancelled order stays visible, with its items preserved
 *   RBAC-7  an ownership check is applied in addition to the role check
 *   ER-10   an order that does not exist is a 404; an order belonging to
 *           another customer is a 403
 *
 * The Admin order list and the Admin order detail (P-17, P-18) are NOT
 * part of Phase 4: they remain in Phase 5 (decision E).
 */

require_once dirname(__DIR__) . '/helpers/functions.php';
require_once dirname(__DIR__) . '/helpers/authorization.php';
require_once dirname(__DIR__) . '/models/Order.php';
require_once dirname(__DIR__) . '/models/Cart.php';
require_once dirname(__DIR__) . '/models/Payment.php';
require_once dirname(__DIR__) . '/models/WalletPayment.php';
require_once dirname(__DIR__) . '/models/Refund.php';

class OrderController
{
    /**
     * The customer's order list (P-11).
     */
    public function index(array $parameters = array()): void
    {
        require_capability('shopping.orders');

        $user = current_user();

        $totalResults = Order::countForUser((int) $user['id']);
        $lastPage = Order::pageCount($totalResults);
        $page = Order::normalisePage($_GET['page'] ?? '', $lastPage);

        $orders = Order::forUser((int) $user['id'], Order::PER_PAGE, ($page - 1) * Order::PER_PAGE);

        $cartCount = Cart::countItems((int) $user['id']);

        require __DIR__ . '/../views/orders/index.php';
    }

    /**
     * The order detail page (P-12).
     *
     * @param array<string, mixed> $parameters 'id' holds the order id.
     */
    public function show(array $parameters = array()): void
    {
        require_capability('shopping.orders');

        $user = current_user();

        $id = isset($parameters['id']) ? (int) $parameters['id'] : 0;

        if ($id <= 0) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        $order = Order::find($id);

        // ER-10: an order that does not exist is a 404.
        if ($order === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        // RBAC-7 / FR-5.2: an order belonging to another customer is
        // refused with 403, and no data leaks through a guessed identifier.
        if ((int) $order['user_id'] !== (int) $user['id']) {
            http_response_code(403);
            require_once __DIR__ . '/ErrorController.php';
            (new ErrorController())->accessDenied(current_role());
            exit;
        }

        $items = Order::items($id);

        // FR-4.17: the wallet details and the refunds, so the customer can
        // see where their payment is and whether it has been returned.
        $orderWallet = null;
        $orderRefunds = array();

        if (WalletPayment::isWalletMethod((string) ($order['payment_method'] ?? ''))) {
            $payment = Payment::forOrder($id);

            if ($payment !== null) {
                $orderWallet = WalletPayment::forPayment((int) $payment['id']);
                $orderRefunds = Refund::forPayment((int) $payment['id']);
            }
        }

        require __DIR__ . '/../views/orders/show.php';
    }
}
