<?php
/**
 * GreenCorner — Checkout Controller
 *
 * Serves the Payment page (P-09), performs the checkout, and serves the
 * Order Confirmation page (P-10).
 *
 * Implements:
 *   FR-4.1  the Payment page shows the order summary, the delivery address
 *           form and the payment form
 *   FR-4.2  Card and Cash on Delivery are mutually exclusive radio buttons;
 *           Card is the default
 *   FR-4.3  the card fields are validated server-side; a validation failure
 *           creates no payment record and re-renders the form with inline
 *           errors
 *   FR-4.4  a successful card payment creates the order, its items, the
 *           stock reduction, the payment row and the cleared cart
 *   FR-4.5  a declined card attempt creates no order and leaves the cart
 *           unchanged
 *   FR-4.7  Cash on Delivery creates the order immediately, with a pending
 *           payment
 *   FR-4.8  the delivery address is captured and stored as an immutable
 *           snapshot
 *   FR-4.9  no card data is stored, logged or echoed
 *   FR-4.10 exactly one payment method applies to an order
 *   FR-4.11 the cart is re-validated inside the checkout transaction
 *   FR-4.12 a changed price produces an updated total for confirmation
 *   FR-4.13 the Order Confirmation page
 *   FR-4.15 the whole checkout is one transaction
 *   ER-7    the delivery address fields are required
 */

require_once dirname(__DIR__) . '/helpers/functions.php';
require_once dirname(__DIR__) . '/helpers/csrf.php';
require_once dirname(__DIR__) . '/helpers/authorization.php';
require_once dirname(__DIR__) . '/helpers/checkout_snapshot.php';
require_once dirname(__DIR__) . '/models/Cart.php';
require_once dirname(__DIR__) . '/models/Checkout.php';
require_once dirname(__DIR__) . '/models/Order.php';
require_once dirname(__DIR__) . '/models/Payment.php';
require_once dirname(__DIR__) . '/models/WalletPayment.php';
require_once dirname(__DIR__) . '/models/Refund.php';

class CheckoutController
{
    /**
     * The Payment page (P-09).
     *
     * The page is not reachable with an empty cart (FR-4.1 acceptance
     * criterion 3), nor with an unavailable item in the cart (FR-3.11).
     */
    public function payment(array $parameters = array()): void
    {
        require_capability('shopping.checkout');

        $user = current_user();
        $rows = Cart::items((int) $user['id']);

        if ($rows === array()) {
            set_flash('error', 'Your cart is empty, so there is nothing to pay for yet.');
            redirect('/cart');
        }

        // FR-3.8: an unavailable item blocks the checkout.
        foreach ($rows as $row) {
            if ((int) ($row['is_deleted'] ?? 0) === 1) {
                set_flash('error', 'One of the items in your cart is no longer available. Please remove it to continue.');
                redirect('/cart');
            }

            if ((int) ($row['current_stock'] ?? 0) < 1) {
                set_flash('error', 'One of the items in your cart is out of stock. Please remove it to continue.');
                redirect('/cart');
            }
        }

        $items = $this->buildItems($rows);
        $total = $this->totalOf($items);

        // The snapshot records the totals that are displayed here, so the
        // checkout can detect a price that moves in the meantime
        // (FR-4.12).
        checkout_snapshot_create($rows, $total);

        $method = Payment::METHOD_CARD;
        $errors = array();
        $address = $this->emptyAddress();

        // FR-4.3: when the previous attempt was refused, the field errors
        // and the address the customer typed are shown again, so they do
        // not have to type everything a second time. The card fields are
        // never restored: they are sensitive and are always empty.
        $storedErrors = $_SESSION['checkout_errors'] ?? null;

        if (is_array($storedErrors) && $storedErrors !== array()) {
            $errors = $storedErrors;
        }

        $storedAddress = $_SESSION['checkout_address'] ?? null;

        if (is_array($storedAddress) && $storedAddress !== array()) {
            $address = array_merge($address, $storedAddress);
        }

        $storedMethod = $_SESSION['checkout_method'] ?? null;

        if (
            $storedMethod === Payment::METHOD_COD
            || $storedMethod === Payment::METHOD_CARD
            || WalletPayment::isWalletMethod($storedMethod)
        ) {
            $method = Checkout::normaliseMethod($storedMethod);
        }

        unset($_SESSION['checkout_errors'], $_SESSION['checkout_address'], $_SESSION['checkout_method']);

        // FR-4.13: the wallet methods are only offered when that wallet is
        // configured with a merchant number, so a customer is never told to
        // send money to a number the store does not watch.
        $walletMethods = WalletPayment::availableMethods();

        // The reference the customer is shown, and the merchant number and
        // dial code of each available wallet.
        $walletContext = array();

        foreach ($walletMethods as $walletMethod) {
            $walletContext[$walletMethod] = array(
                'merchant_number' => WalletPayment::merchantNumber($walletMethod),
                'dial_code'       => WalletPayment::dialCode($walletMethod),
                'label'           => WalletPayment::methodLabel($walletMethod),
            );
        }

        $referenceCode = WalletPayment::referenceCode((int) $user['id'], $items);

        require __DIR__ . '/../views/checkout/payment.php';
    }

    /**
     * Perform the checkout.
     */
    public function placeOrder(array $parameters = array()): void
    {
        require_capability('shopping.checkout');

        $user = current_user();

        csrf_require(false, 'checkout');

        $rows = Cart::items((int) $user['id']);

        if ($rows === array()) {
            set_flash('error', 'Your cart is empty, so there is nothing to pay for yet.');
            redirect('/cart');
        }

        // The total the customer was shown on the Payment page.
        $shownTotal = checkout_snapshot_total();

        if ($shownTotal === null) {
            $shownTotal = $this->totalOf($this->buildItems($rows));
        }

        // FR-4.2 / FR-4.10: exactly one method applies. The requested method
        // comes from the form, so it is reduced to one the store accepts: a
        // wallet method is honoured only when that wallet is configured with
        // a merchant number.
        $method = Checkout::normaliseMethod(request_string('payment_method'));

        // ER-7: the delivery address fields are required.
        $address = array(
            'delivery_name'    => request_string('delivery_name'),
            'delivery_phone'   => request_string('delivery_phone'),
            'delivery_address' => request_string('delivery_address'),
            'delivery_city'    => request_string('delivery_city'),
        );

        $addressErrors = $this->validateAddress($address);

        if ($addressErrors !== array()) {
            $_SESSION['checkout_errors'] = $addressErrors;
            $_SESSION['checkout_address'] = $address;
            $_SESSION['checkout_method'] = $method;
            set_flash('error', 'Please correct the delivery address and try again.');
            redirect('/checkout/payment');
        }

        // The card details are read here and handed straight to the
        // checkout, which validates them and then discards them. They are
        // never stored, logged or echoed (FR-4.9, SEC-15).
        //
        // The wallet fields are read in the same place. Unlike the card
        // data, they ARE stored: the sender number and the transaction id
        // are exactly what the Admin needs in order to check the payment.
        //
        // Each wallet has its own field names, because both instruction
        // panels are in the page at once and a hidden field is still
        // submitted by the browser.
        $senderNumber = $method === WalletPayment::METHOD_NAGAD
            ? request_string('nagad_sender_number')
            : request_string('sender_number');

        $transactionId = $method === WalletPayment::METHOD_NAGAD
            ? request_string('nagad_transaction_id')
            : request_string('transaction_id');

        $card = array(
            'cardholder_name' => request_string('cardholder_name'),
            'card_number'     => request_string('card_number'),
            'expiry'          => request_string('expiry'),
            'cvv'             => request_string('cvv'),
            'sender_number'   => $senderNumber,
            'transaction_id'  => $transactionId,
            'reference_code'  => request_string('reference_code'),
            'shown_total'     => $shownTotal,
        );

        $result = Checkout::place((int) $user['id'], $address, $method, $card);

        // The snapshot is single-use, whatever the outcome.
        checkout_snapshot_consume();

        if ($result['status'] === 'success') {
            unset($_SESSION['checkout_errors'], $_SESSION['checkout_address'], $_SESSION['checkout_method']);

            // FR-4.13: the confirmation page is a distinct destination.
            $_SESSION['last_order_id'] = (int) $result['order']['id'];

            redirect('/checkout/confirmation?order=' . urlencode((string) $result['order']['order_number']));
        }

        if ($result['status'] === 'price_changed') {
            // FR-4.12: the customer must confirm the updated total before
            // the order is committed.
            $_SESSION['checkout_price_change'] = array(
                'total'          => (float) $result['total'],
                'previous_total' => (float) $result['previous_total'],
                'items'          => $result['items'],
                'method'         => $method,
            );

            set_flash('error', 'The price of one or more items has changed. Please review the updated total and confirm to continue.');
            redirect('/checkout/payment');
        }

        if ($result['status'] === 'invalid') {
            // FR-4.3: no payment record was created. The form is
            // re-rendered with the field errors.
            $_SESSION['checkout_errors'] = $result['card_errors'];
            $_SESSION['checkout_address'] = $address;
            $_SESSION['checkout_method'] = $method;
            set_flash('error', $result['message']);
            redirect('/checkout/payment');
        }

        if ($result['status'] === 'declined') {
            // FR-4.5: the cart is unchanged and the customer may try again.
            $_SESSION['checkout_address'] = $address;
            $_SESSION['checkout_method'] = $method;
            set_flash('error', $result['message']);
            redirect('/checkout/payment');
        }

        if ($result['status'] === 'unavailable') {
            // FR-4.11 / ER-23: the item at fault is named.
            $name = (string) ($result['product_name'] ?? '');

            if ($name !== '') {
                set_flash('error', $result['message'] . ' (' . $name . ')');
            } else {
                set_flash('error', $result['message']);
            }

            redirect('/cart');
        }

        set_flash('error', 'Your cart is empty, so there is nothing to pay for yet.');
        redirect('/cart');
    }

    /**
     * Confirm a changed price and complete the checkout (FR-4.12).
     *
     * The customer is shown the updated total and has to confirm it before
     * the order is committed.
     */
    public function confirmPrice(array $parameters = array()): void
    {
        require_capability('shopping.checkout');

        $user = current_user();

        csrf_require(false, 'checkout_confirm');

        $change = $_SESSION['checkout_price_change'] ?? null;

        if (!is_array($change)) {
            set_flash('error', 'There is no pending price change to confirm.');
            redirect('/checkout/payment');
        }

        // The snapshot is rebuilt from the new total, so the second attempt
        // compares against the total the customer has just confirmed.
        $rows = Cart::items((int) $user['id']);

        if ($rows === array()) {
            unset($_SESSION['checkout_price_change']);
            set_flash('error', 'Your cart is empty, so there is nothing to pay for yet.');
            redirect('/cart');
        }

        $address = $this->addressFromSession();

        $method = (string) ($change['method'] ?? Payment::METHOD_CARD);

        $card = array(
            'cardholder_name' => request_string('cardholder_name'),
            'card_number'     => request_string('card_number'),
            'expiry'          => request_string('expiry'),
            'cvv'             => request_string('cvv'),
            'sender_number'   => $method === WalletPayment::METHOD_NAGAD
                ? request_string('nagad_sender_number')
                : request_string('sender_number'),
            'transaction_id'  => $method === WalletPayment::METHOD_NAGAD
                ? request_string('nagad_transaction_id')
                : request_string('transaction_id'),
            'reference_code'  => request_string('reference_code'),
            // The confirmed total is the new total, so the price-change
            // check passes and the order is committed.
            'shown_total'     => (float) $change['total'],
        );

        $result = Checkout::place((int) $user['id'], $address, $method, $card);

        checkout_snapshot_consume();
        unset($_SESSION['checkout_price_change']);

        if ($result['status'] === 'success') {
            unset($_SESSION['checkout_errors'], $_SESSION['checkout_address'], $_SESSION['checkout_method']);
            $_SESSION['last_order_id'] = (int) $result['order']['id'];

            redirect('/checkout/confirmation?order=' . urlencode((string) $result['order']['order_number']));
        }

        if ($result['status'] === 'invalid') {
            $_SESSION['checkout_errors'] = $result['card_errors'];
            $_SESSION['checkout_address'] = $address;
            $_SESSION['checkout_method'] = $method;
            set_flash('error', $result['message']);
            redirect('/checkout/payment');
        }

        if ($result['status'] === 'declined') {
            $_SESSION['checkout_address'] = $address;
            set_flash('error', $result['message']);
            redirect('/checkout/payment');
        }

        if ($result['status'] === 'price_changed') {
            $_SESSION['checkout_price_change'] = array(
                'total'          => (float) $result['total'],
                'previous_total' => (float) $result['previous_total'],
                'items'          => $result['items'],
                'method'         => $method,
            );

            set_flash('error', 'The price has changed again. Please review the updated total and confirm to continue.');
            redirect('/checkout/payment');
        }

        if ($result['status'] === 'unavailable') {
            $name = (string) ($result['product_name'] ?? '');

            if ($name !== '') {
                set_flash('error', $result['message'] . ' (' . $name . ')');
            } else {
                set_flash('error', $result['message']);
            }

            redirect('/cart');
        }

        set_flash('error', 'Your cart is empty, so there is nothing to pay for yet.');
        redirect('/cart');
    }

    /**
     * The Order Confirmation page (P-10).
     *
     * The page shows one order, and only one that belongs to the signed-in
     * customer. The order number in the query string identifies the order;
     * an order belonging to somebody else is refused with HTTP 403.
     */
    public function confirmation(array $parameters = array()): void
    {
        require_capability('shopping.orders');

        $user = current_user();

        $orderNumber = get_string('order');

        if ($orderNumber === '') {
            $lastOrderId = (int) ($_SESSION['last_order_id'] ?? 0);

            if ($lastOrderId > 0) {
                $lastOrder = Order::find($lastOrderId);

                if ($lastOrder !== null && (int) $lastOrder['user_id'] === (int) $user['id']) {
                    redirect('/checkout/confirmation?order=' . urlencode((string) $lastOrder['order_number']));
                }
            }

            set_flash('error', 'We could not find that order confirmation.');
            redirect('/dashboard');
        }

        $order = Order::findByNumber($orderNumber);

        if ($order === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        // RBAC-7 / FR-5.2 / ER-10: an order belonging to another customer is
        // refused, and no data leaks through a guessed identifier.
        if ((int) $order['user_id'] !== (int) $user['id']) {
            http_response_code(403);
            require_once __DIR__ . '/ErrorController.php';
            (new ErrorController())->accessDenied(current_role());
            exit;
        }

        $items = Order::items((int) $order['id']);

        // FR-4.13 / FR-4.17: the wallet details, so the customer can see
        // the reference they were shown and where their payment is.
        $confirmationWallet = null;
        $confirmationRefunds = array();

        if (WalletPayment::isWalletMethod((string) ($order['payment_method'] ?? ''))) {
            $payment = Payment::forOrder((int) $order['id']);

            if ($payment !== null) {
                $confirmationWallet = WalletPayment::forPayment((int) $payment['id']);
                $confirmationRefunds = Refund::forPayment((int) $payment['id']);
            }
        }

        require __DIR__ . '/../views/checkout/confirmation.php';
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Validate the delivery address (ER-7).
     *
     * @param array<string, string> $address
     * @return array<string, string>
     */
    private function validateAddress(array $address): array
    {
        $errors = array();

        if ($address['delivery_name'] === '') {
            $errors['delivery_name'] = 'Please enter the recipient\'s name.';
        } elseif (mb_strlen($address['delivery_name']) > 120) {
            $errors['delivery_name'] = 'Please enter a name of 120 characters or fewer.';
        }

        if ($address['delivery_phone'] === '') {
            $errors['delivery_phone'] = 'Please enter a contact number.';
        } elseif (mb_strlen($address['delivery_phone']) > 40) {
            $errors['delivery_phone'] = 'Please enter a contact number of 40 characters or fewer.';
        }

        if ($address['delivery_address'] === '') {
            $errors['delivery_address'] = 'Please enter the street address.';
        } elseif (mb_strlen($address['delivery_address']) > 255) {
            $errors['delivery_address'] = 'Please enter an address of 255 characters or fewer.';
        }

        if ($address['delivery_city'] === '') {
            $errors['delivery_city'] = 'Please enter a city or area.';
        } elseif (mb_strlen($address['delivery_city']) > 100) {
            $errors['delivery_city'] = 'Please enter a city or area of 100 characters or fewer.';
        }

        return $errors;
    }

    /**
     * An empty address, used as the default value of the address form.
     *
     * @return array<string, string>
     */
    private function emptyAddress(): array
    {
        return array(
            'delivery_name'    => '',
            'delivery_phone'   => '',
            'delivery_address' => '',
            'delivery_city'    => '',
        );
    }

    /**
     * The address held in the session, or an empty address.
     *
     * @return array<string, string>
     */
    private function addressFromSession(): array
    {
        $stored = $_SESSION['checkout_address'] ?? null;

        if (!is_array($stored)) {
            return $this->emptyAddress();
        }

        return array(
            'delivery_name'    => (string) ($stored['delivery_name'] ?? ''),
            'delivery_phone'   => (string) ($stored['delivery_phone'] ?? ''),
            'delivery_address' => (string) ($stored['delivery_address'] ?? ''),
            'delivery_city'    => (string) ($stored['delivery_city'] ?? ''),
        );
    }

    /**
     * Build the display rows for the order summary.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(array $rows): array
    {
        $items = array();

        foreach ($rows as $row) {
            $quantity = (int) $row['quantity'];
            $currentPrice = (float) ($row['current_price'] ?? 0);

            $items[] = array(
                'product_id'    => (int) $row['product_id'],
                'name'          => (string) ($row['product_name'] ?? ''),
                'quantity'      => $quantity,
                'unit_price'    => $currentPrice,
                'line_total'    => round($currentPrice * $quantity, 2),
                'thumbnail_path' => (string) ($row['thumbnail_path'] ?? ''),
            );
        }

        return $items;
    }

    /**
     * The grand total of the order summary.
     *
     * @param array<int, array<string, mixed>> $items
     * @return float
     */
    private function totalOf(array $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            $total += (float) $item['line_total'];
        }

        return round($total, 2);
    }
}
