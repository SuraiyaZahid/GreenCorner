<?php
/**
 * GreenCorner — Admin Order Controller
 *
 * The Admin order management: the Admin Orders List (P-17) and the Admin
 * Order Detail page (P-18).
 *
 * Implements:
 *   FR-6.7  the list shows every order with the order number, the customer,
 *           the date, the fulfilment status, the total and the payment
 *           status; the payment status is read from `payments.status` and
 *           is shown distinctly; cancelled orders remain listed
 *   FR-6.8  the detail page shows the items, quantities, unit prices, line
 *           totals, total, customer, delivery address snapshot, fulfilment
 *           status and payment status, and allows the Admin to update the
 *           fulfilment status; an illegal transition is refused
 *   FR-6.9  the Admin confirms that the cash for a COD order has been
 *           collected
 *   FR-4.16 the payment status comes from the `payments` table alone
 *   FR-4.8  the delivery address is the immutable snapshot on the order
 *   FR-7.4  a User is refused with HTTP 403 before any state change
 *   FR-7.7  every state-changing request carries a CSRF token
 *
 * The rules themselves live in `OrderStatus`, `Payment` and
 * `OrderFulfilment`. This controller only gathers the request, calls the
 * service and renders the result.
 */

require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/authorization.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/OrderStatus.php';
require_once __DIR__ . '/../models/Payment.php';
require_once __DIR__ . '/../models/OrderFulfilment.php';
require_once __DIR__ . '/../models/WalletPayment.php';
require_once __DIR__ . '/../models/Refund.php';

class AdminOrderController
{
    /**
     * The Admin Orders List (P-17).
     *
     * Every order is listed, most recent first, 20 to a page. The list
     * carries no filters, because the approved requirements ask for none.
     *
     * @param array<string, mixed> $parameters The URL parameters (unused).
     */
    public function index(array $parameters = array()): void
    {
        require_capability('admin.orders');

        $totalResults = Order::countAll();
        $lastPage = Order::adminPageCount($totalResults, Order::ADMIN_PER_PAGE);
        $page = Order::normalisePage(get_string('page', '1'), $lastPage);

        $orders = Order::listAll(Order::ADMIN_PER_PAGE, ($page - 1) * Order::ADMIN_PER_PAGE);

        $query = array();

        require __DIR__ . '/../views/admin/orders/index.php';
    }

    /**
     * The Admin Order Detail page (P-18).
     *
     * @param array<string, mixed> $parameters 'id' holds the order id.
     */
    public function show(array $parameters = array()): void
    {
        require_capability('admin.orders');

        $id = isset($parameters['id']) ? (int) $parameters['id'] : 0;

        if ($id <= 0) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        $adminOrder = Order::find($id);

        if ($adminOrder === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        $adminOrderItems = Order::items($id);
        $adminOrderPayment = Payment::forOrder($id);

        // The statuses the Admin may choose from. A status that is refused
        // is never offered (FR-6.8 acceptance criterion 3).
        $adminOrderTargets = OrderStatus::allowedTargets((string) $adminOrder['status']);

        // The COD cash can only be confirmed while the payment is still
        // pending (FR-6.9).
        $adminOrderCanConfirmCod = $adminOrderPayment !== null
            && (string) $adminOrderPayment['method'] === Payment::METHOD_COD
            && (string) $adminOrderPayment['status'] === 'pending';

        // FR-6.10: the wallet payment details, and the refunds.
        $adminOrderWallet = $this->walletFor($adminOrderPayment);
        $adminOrderRefunds = $this->refundsFor($adminOrderPayment);

        // The wallet payment can be approved or rejected only while it is
        // awaiting verification.
        $adminOrderCanVerifyWallet = $adminOrderWallet !== null
            && (string) $adminOrderWallet['status'] === WalletPayment::STATUS_PENDING;

        $adminOrderErrors = array();
        $adminOrderRequestedStatus = '';

        require __DIR__ . '/../views/admin/orders/show.php';
    }

    /**
     * Update the fulfilment status of an order (FR-6.8).
     *
     * An illegal transition is refused with HTTP 409, which is the status
     * the traceability matrix requires for that case.
     *
     * @param array<string, mixed> $parameters 'id' holds the order id.
     */
    public function updateStatus(array $parameters = array()): void
    {
        require_capability('admin.orders');

        $id = isset($parameters['id']) ? (int) $parameters['id'] : 0;

        if ($id <= 0) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        csrf_require(false, 'admin_order_status', array('id' => $id));

        $requestedStatus = request_string('status');

        $result = OrderFulfilment::transition($id, $requestedStatus);

        if ($result['status'] === 'not_found') {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        if ($result['status'] === 'refused') {
            // The transition is not permitted. The status code is 409, as
            // the traceability matrix requires for an illegal transition.
            http_response_code(409);

            $adminOrder = Order::find($id);
            $adminOrderItems = Order::items($id);
            $adminOrderPayment = Payment::forOrder($id);
            $adminOrderTargets = OrderStatus::allowedTargets((string) $adminOrder['status']);
            $adminOrderCanConfirmCod = $adminOrderPayment !== null
                && (string) $adminOrderPayment['method'] === Payment::METHOD_COD
                && (string) $adminOrderPayment['status'] === 'pending';
            $adminOrderWallet = $this->walletFor($adminOrderPayment);
            $adminOrderRefunds = $this->refundsFor($adminOrderPayment);
            $adminOrderCanVerifyWallet = $adminOrderWallet !== null
                && (string) $adminOrderWallet['status'] === WalletPayment::STATUS_PENDING;
            $adminOrderErrors = array('status' => $result['message']);
            $adminOrderRequestedStatus = $requestedStatus;

            require __DIR__ . '/../views/admin/orders/show.php';
            exit;
        }

        // A cancellation may have left cases that need manual handling.
        if (count($result['unrestored']) > 0) {
            set_flash(
                'error',
                'This order was cancelled, but the stock of '
                . count($result['unrestored'])
                . ' product(s) could not be restored automatically. The case has been logged for manual handling.'
            );
        } else {
            set_flash('success', 'The order status is now ' . $requestedStatus . '.');
        }

        redirect('/admin/orders/' . $id);
    }

    /**
     * Confirm that the cash for a Cash on Delivery order has been collected
     * (FR-6.9).
     *
     * @param array<string, mixed> $parameters 'id' holds the order id.
     */
    public function confirmCod(array $parameters = array()): void
    {
        require_capability('admin.orders');

        $id = isset($parameters['id']) ? (int) $parameters['id'] : 0;

        if ($id <= 0) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        csrf_require(false, 'admin_cod_confirm', array('id' => $id));

        $result = OrderFulfilment::confirmCod($id);

        if ($result['status'] === 'not_found') {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        if ($result['status'] === 'refused') {
            http_response_code(409);

            $adminOrder = Order::find($id);
            $adminOrderItems = Order::items($id);
            $adminOrderPayment = Payment::forOrder($id);
            $adminOrderTargets = OrderStatus::allowedTargets((string) $adminOrder['status']);
            $adminOrderCanConfirmCod = false;
            $adminOrderWallet = $this->walletFor($adminOrderPayment);
            $adminOrderRefunds = $this->refundsFor($adminOrderPayment);
            $adminOrderCanVerifyWallet = $adminOrderWallet !== null
                && (string) $adminOrderWallet['status'] === WalletPayment::STATUS_PENDING;
            $adminOrderErrors = array('payment' => $result['message']);
            $adminOrderRequestedStatus = '';

            require __DIR__ . '/../views/admin/orders/show.php';
            exit;
        }

        set_flash('success', 'The cash collection has been confirmed.');
        redirect('/admin/orders/' . $id);
    }

    /**
     * Approve a bKash or Nagad payment (FR-6.10).
     *
     * The Admin has checked the wallet statement and confirmed that the
     * money arrived. Only now does the payment become a success: a
     * submitted transaction id is never treated as proof on its own.
     *
     * @param array<string, mixed> $parameters 'id' holds the order id.
     */
    public function verifyWalletPayment(array $parameters = array()): void
    {
        require_capability('admin.orders');

        $id = isset($parameters['id']) ? (int) $parameters['id'] : 0;

        if ($id <= 0) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        csrf_require(false, 'admin_wallet_verify', array('id' => $id));

        $payment = Payment::forOrder($id);

        if ($payment === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        $admin = current_user();
        $adminId = isset($admin['id']) ? (int) $admin['id'] : 0;

        $result = WalletPayment::verify((int) $payment['id'], $adminId);

        if ($result['status'] !== 'success') {
            http_response_code(409);
            $this->renderWithPaymentError($id, 'payment', $result['message']);
            exit;
        }

        set_flash('success', 'The payment has been verified. The customer can now see that their order is paid.');
        redirect('/admin/orders/' . $id);
    }

    /**
     * Reject a bKash or Nagad payment (FR-6.10).
     *
     * @param array<string, mixed> $parameters 'id' holds the order id.
     */
    public function rejectWalletPayment(array $parameters = array()): void
    {
        require_capability('admin.orders');

        $id = isset($parameters['id']) ? (int) $parameters['id'] : 0;

        if ($id <= 0) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        csrf_require(false, 'admin_wallet_reject', array('id' => $id));

        $payment = Payment::forOrder($id);

        if ($payment === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        $reason = request_string('rejection_reason');

        $admin = current_user();
        $adminId = isset($admin['id']) ? (int) $admin['id'] : 0;

        $result = WalletPayment::reject((int) $payment['id'], $adminId, $reason);

        if ($result['status'] !== 'success') {
            http_response_code(409);
            $this->renderWithPaymentError($id, 'payment', $result['message']);
            exit;
        }

        set_flash('success', 'The payment has been rejected. The customer will see that the payment was refused.');
        redirect('/admin/orders/' . $id);
    }

    /**
     * Mark a verified wallet order as paid but unfulfillable and open a
     * full refund (FR-4.14).
     *
     * The refund is opened as `refund_pending`. It is only marked
     * `refunded` once the store confirms that the money was actually
     * returned (FR-4.15).
     *
     * @param array<string, mixed> $parameters 'id' holds the order id.
     */
    public function refundUnfulfillable(array $parameters = array()): void
    {
        require_capability('admin.orders');

        $id = isset($parameters['id']) ? (int) $parameters['id'] : 0;

        if ($id <= 0) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        csrf_require(false, 'admin_wallet_refund', array('id' => $id));

        $result = Refund::markPaidUnfulfillable($id);

        if ($result['status'] !== 'success') {
            http_response_code(409);
            $this->renderWithPaymentError($id, 'payment', $result['message']);
            exit;
        }

        set_flash(
            'success',
            'The order is now marked as paid but unfulfillable, and a refund has been opened. The refund stays pending until the money has actually been returned.'
        );
        redirect('/admin/orders/' . $id);
    }

    /**
     * Confirm that a refund was actually returned to the customer
     * (FR-4.15).
     *
     * A refund is only ever confirmed with the reference the payment
     * provider gave for it. There is no path that marks a refund
     * successful without one.
     *
     * @param array<string, mixed> $parameters 'id' holds the order id.
     */
    public function confirmRefund(array $parameters = array()): void
    {
        require_capability('admin.orders');

        $id = isset($parameters['id']) ? (int) $parameters['id'] : 0;

        if ($id <= 0) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        csrf_require(false, 'admin_refund_confirm', array('id' => $id));

        $refundId = request_int('refund_id');
        $gatewayReference = request_string('gateway_reference');

        if ($refundId <= 0) {
            http_response_code(409);
            $this->renderWithPaymentError($id, 'refund', 'We could not find that refund.');
            exit;
        }

        $result = Refund::confirm($refundId, $gatewayReference);

        if ($result['status'] !== 'success') {
            http_response_code(409);
            $this->renderWithPaymentError($id, 'refund', $result['message']);
            exit;
        }

        set_flash('success', 'The refund has been confirmed.');
        redirect('/admin/orders/' . $id);
    }

    /**
     * Record that a refund attempt failed, so it is flagged for review
     * (FR-4.15).
     *
     * @param array<string, mixed> $parameters 'id' holds the order id.
     */
    public function failRefund(array $parameters = array()): void
    {
        require_capability('admin.orders');

        $id = isset($parameters['id']) ? (int) $parameters['id'] : 0;

        if ($id <= 0) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        csrf_require(false, 'admin_refund_fail', array('id' => $id));

        $refundId = request_int('refund_id');
        $detail = request_string('failure_detail');

        if ($refundId <= 0) {
            http_response_code(409);
            $this->renderWithPaymentError($id, 'refund', 'We could not find that refund.');
            exit;
        }

        $result = Refund::fail($refundId, $detail);

        if ($result['status'] !== 'success') {
            http_response_code(409);
            $this->renderWithPaymentError($id, 'refund', $result['message']);
            exit;
        }

        set_flash('success', 'The failed refund has been flagged for review.');
        redirect('/admin/orders/' . $id);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * The wallet submission of a payment, if it has one.
     *
     * @param array<string, mixed>|null $payment
     * @return array<string, mixed>|null
     */
    private function walletFor(?array $payment): ?array
    {
        if ($payment === null) {
            return null;
        }

        if (!WalletPayment::isWalletMethod((string) ($payment['method'] ?? ''))) {
            return null;
        }

        return WalletPayment::forPayment((int) $payment['id']);
    }

    /**
     * The refunds of a payment.
     *
     * @param array<string, mixed>|null $payment
     * @return array<int, array<string, mixed>>
     */
    private function refundsFor(?array $payment): array
    {
        if ($payment === null) {
            return array();
        }

        return Refund::forPayment((int) $payment['id']);
    }

    /**
     * Re-render the Admin Order Detail page with an error on it.
     *
     * The status code is 409, because the action was refused rather than
     * because the page is missing.
     *
     * @param int    $orderId
     * @param string $field  The field the error belongs to.
     * @param string $message
     * @return void
     */
    private function renderWithPaymentError(int $orderId, string $field, string $message): void
    {
        $adminOrder = Order::find($orderId);

        if ($adminOrder === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        $adminOrderItems = Order::items($orderId);
        $adminOrderPayment = Payment::forOrder($orderId);
        $adminOrderTargets = OrderStatus::allowedTargets((string) $adminOrder['status']);
        $adminOrderCanConfirmCod = $adminOrderPayment !== null
            && (string) $adminOrderPayment['method'] === Payment::METHOD_COD
            && (string) $adminOrderPayment['status'] === 'pending';
        $adminOrderWallet = $this->walletFor($adminOrderPayment);
        $adminOrderRefunds = $this->refundsFor($adminOrderPayment);
        $adminOrderCanVerifyWallet = $adminOrderWallet !== null
            && (string) $adminOrderWallet['status'] === WalletPayment::STATUS_PENDING;
        $adminOrderErrors = array($field => $message);
        $adminOrderRequestedStatus = '';

        require __DIR__ . '/../views/admin/orders/show.php';
    }
}
