<?php
/**
 * GreenCorner — CSRF Protection
 *
 * Implements FR-7.7 and NFR-S-04:
 *   Every state-changing request carries a CSRF token that is validated
 *   server-side and tied to the session. A missing, malformed or mismatched
 *   token causes the request to be rejected with no state change.
 *
 * This applies to the background cart endpoints as well as to full-page form
 * submissions.
 */

require_once __DIR__ . '/session_handler.php';
require_once __DIR__ . '/functions.php';

/**
 * The key under which the CSRF token is stored in the session.
 */
const CSRF_SESSION_KEY = 'csrf_token';

/**
 * The name of the hidden form field and the JSON request field.
 */
const CSRF_FIELD_NAME = 'csrf_token';

/**
 * Return the current session's CSRF token, creating it if necessary.
 *
 * The token is tied to the session: a token from another session will not
 * match (FR-7.7, acceptance criterion 2).
 */
function csrf_token(): string
{
    if (!isset($_SESSION[CSRF_SESSION_KEY]) || !is_string($_SESSION[CSRF_SESSION_KEY])) {
        $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }

    return $_SESSION[CSRF_SESSION_KEY];
}

/**
 * Return a hidden input field containing the CSRF token.
 *
 * Used in every form that changes state.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_FIELD_NAME . '" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Validate the CSRF token on the current request.
 *
 * Reads the token from the POST body or the JSON request body, and compares
 * it with the session token using a constant-time comparison.
 *
 * @return bool True when the token is present and matches.
 */
function csrf_is_valid(): bool
{
    $expected = isset($_SESSION[CSRF_SESSION_KEY]) && is_string($_SESSION[CSRF_SESSION_KEY])
        ? $_SESSION[CSRF_SESSION_KEY]
        : '';

    if ($expected === '') {
        return false;
    }

    $provided = csrf_read_token_from_request();

    if (!is_string($provided) || $provided === '') {
        return false;
    }

    return hash_equals($expected, $provided);
}

/**
 * Read the submitted CSRF token from the request, whether it is a form post
 * or a JSON body.
 *
 * @return string|null
 */
function csrf_read_token_from_request(): ?string
{
    // A JSON request body is not parsed into $_POST by PHP, so read it
    // directly from the raw input when the content type is JSON.
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded[CSRF_FIELD_NAME]) && is_string($decoded[CSRF_FIELD_NAME])) {
                return $decoded[CSRF_FIELD_NAME];
            }
        }
    }

    if (isset($_POST[CSRF_FIELD_NAME]) && is_string($_POST[CSRF_FIELD_NAME])) {
        return $_POST[CSRF_FIELD_NAME];
    }

    // Some clients send the token in a request header.
    if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return $_SERVER['HTTP_X_CSRF_TOKEN'];
    }

    return null;
}

/**
 * The message shown when a CSRF token is missing or invalid.
 */
const CSRF_ERROR_MESSAGE = 'Your session expired or the form was tampered with. Please try again.';

/**
 * Reject the current request when the CSRF token is missing or invalid.
 *
 * A JSON endpoint receives a 422 with a machine-readable error code. A form
 * post is re-rendered with HTTP 422 and the error shown on the form itself
 * (decision D-004), so the visitor keeps their input and sees what went
 * wrong on the page they submitted.
 *
 * In both cases no state change occurs, because the check runs before any
 * write.
 *
 * @param bool   $isJsonRequest True for JSON endpoints.
 * @param string $form          The form being submitted: 'login',
 *                              'register', 'change_password',
 *                              'admin_product', 'admin_category',
 *                              'profile_name', 'profile_password',
 *                              'admin_order_status', 'admin_cod_confirm',
 *                              'admin_wallet_verify', 'admin_wallet_reject',
 *                              'admin_wallet_refund', 'admin_refund_confirm',
 *                              'admin_refund_fail'
 *                              or 'admin_user_reset'. It decides which view
 *                              is re-rendered on rejection.
 * @param array<string,mixed> $context Extra context for the re-rendered
 *                              form, for example the id of the product that
 *                              was being edited.
 * @return void
 */
function csrf_require(bool $isJsonRequest = false, string $form = '', array $context = array()): void
{
    if (csrf_is_valid()) {
        return;
    }

    if ($isJsonRequest) {
        json_response(array(
            'ok'    => false,
            'code'  => 'invalid_csrf_token',
            'error' => CSRF_ERROR_MESSAGE,
        ), 422);
    }

    // The status code is set before the view is rendered. It is deliberately
    // NOT a redirect: a redirect would replace the 422 with a 302 and the
    // visitor would lose the error message (decision D-004).
    http_response_code(422);

    csrf_render_rejected_form($form, $context);
}

/**
 * Re-render the form that was submitted, with the CSRF error shown.
 *
 * Only safe, non-sensitive inputs are carried over: the visitor's name and
 * email address on the registration form, the email address on the login
 * form, and the account's name on the profile name form. Passwords are
 * never preserved — the password fields are always empty (decision D-004,
 * SEC-13).
 *
 * @param string $form The form being submitted.
 * @param array<string,mixed> $context Extra context for the re-rendered form.
 * @return void
 */
function csrf_render_rejected_form(string $form, array $context = array()): void
{
    $_SESSION['csrf_error'] = CSRF_ERROR_MESSAGE;

    switch ($form) {
        case 'login':
            // The email address is preserved; the password never is.
            $_SESSION['login_email'] = post_string('email');
            require __DIR__ . '/../views/auth/login.php';
            break;

        case 'register':
            $_SESSION['register_old'] = array(
                'full_name' => post_string('full_name'),
                'email'     => post_string('email'),
            );
            require __DIR__ . '/../views/auth/register.php';
            break;

        case 'change_password':
            // Nothing is preserved: all three fields are passwords.
            require __DIR__ . '/../views/auth/change_password.php';
            break;

        case 'admin_product':
            // The Admin product form (P-15). The safe, non-sensitive
            // inputs are preserved so the Admin does not have to retype
            // them. The file input is always cleared: an uploaded file
            // cannot be preserved across a request.
            require_once __DIR__ . '/../models/ProductAdmin.php';
            require_once __DIR__ . '/../models/Category.php';

            $categories = Category::all();
            $errors = array('_csrf' => CSRF_ERROR_MESSAGE);
            $product = array(
                'name'        => post_string('name'),
                'description' => post_string('description'),
                'price'       => post_string('price'),
                'stock'       => post_string('stock'),
                'category_id' => post_int('category_id'),
            );

            // An edit re-renders the form for the product being edited.
            $productId = (int) ($context['id'] ?? 0);
            if ($productId > 0) {
                $existing = ProductAdmin::find($productId);
                if ($existing !== null) {
                    $product = array_merge($existing, $product);
                }
            }

            require __DIR__ . '/../views/admin/product_form.php';
            break;

        case 'admin_category':
            // The Admin category form (P-16).
            require_once __DIR__ . '/../models/Category.php';

            $categories = Category::all();
            $errors = array('_csrf' => CSRF_ERROR_MESSAGE);
            $blockedProducts = array();

            require __DIR__ . '/../views/admin/categories.php';
            break;

        case 'cart_add':
        case 'cart_quantity':
        case 'cart_remove':
        case 'cart_clear':
            // The background cart endpoints. They answer with JSON, so the
            // rejection is a JSON body with the machine-readable code.
            json_response(array(
                'ok'    => false,
                'code'  => 'invalid_csrf_token',
                'error' => CSRF_ERROR_MESSAGE,
            ), 422);
            break;

        case 'checkout':
        case 'checkout_confirm':            // The checkout form (P-09). The delivery address is safe to
            // preserve; the card fields are not, because they are sensitive
            // and the password field must never be repopulated.
            require_once __DIR__ . '/../models/Cart.php';
            require_once __DIR__ . '/../models/Payment.php';

            $user = current_user();
            $rows = $user === null ? array() : Cart::items((int) $user['id']);

            $items = array();
            $total = 0.0;

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

                $total += round($currentPrice * $quantity, 2);
            }

            $total = round($total, 2);

            $method = request_string('payment_method') === Payment::METHOD_COD
                ? Payment::METHOD_COD
                : Payment::METHOD_CARD;

            $errors = array('_csrf' => CSRF_ERROR_MESSAGE);

            $address = array(
                'delivery_name'    => request_string('delivery_name'),
                'delivery_phone'   => request_string('delivery_phone'),
                'delivery_address' => request_string('delivery_address'),
                'delivery_city'    => request_string('delivery_city'),
            );

            require __DIR__ . '/../views/checkout/payment.php';
            break;

        case 'profile_name':
            // The profile name form (P-13). The name is safe to preserve,
            // because it is not sensitive: it is displayed back to the
            // account that typed it.
            $_SESSION['profile_name'] = post_string('full_name');

            require_once __DIR__ . '/../models/User.php';

            $profileUser = current_user();
            $profileErrors = array('_csrf' => CSRF_ERROR_MESSAGE);

            require __DIR__ . '/../views/profile/index.php';
            break;

        case 'profile_password':
            // The profile password form (P-13). Nothing is preserved: all
            // three fields are passwords, and a password must never be
            // written back into the response (SEC-13).
            require_once __DIR__ . '/../models/User.php';

            $profileUser = current_user();
            $profileErrors = array('_csrf' => CSRF_ERROR_MESSAGE);

            require __DIR__ . '/../views/profile/index.php';
            break;

        case 'admin_order_status':
            // The Admin fulfilment-status form (P-18). The requested status
            // is safe to preserve, because it is a value from a fixed set.
            require_once __DIR__ . '/../models/Order.php';
            require_once __DIR__ . '/../models/Payment.php';
            require_once __DIR__ . '/../models/OrderStatus.php';
            require_once __DIR__ . '/../models/WalletPayment.php';
            require_once __DIR__ . '/../models/Refund.php';

            $adminOrderId = (int) ($context['id'] ?? 0);
            $adminOrder = $adminOrderId > 0 ? Order::find($adminOrderId) : null;

            if ($adminOrder === null) {
                http_response_code(404);
                require __DIR__ . '/../views/errors/404.php';
                break;
            }

            $adminOrderItems = Order::items($adminOrderId);
            $adminOrderPayment = Payment::forOrder($adminOrderId);

            // The view expects these two, so the form can be rendered again
            // with the error on it. The permitted statuses and the cash
            // confirmation are computed exactly as the controller does.
            $adminOrderTargets = OrderStatus::allowedTargets((string) $adminOrder['status']);
            $adminOrderCanConfirmCod = $adminOrderPayment !== null
                && (string) $adminOrderPayment['method'] === Payment::METHOD_COD
                && (string) $adminOrderPayment['status'] === 'pending';

            $adminOrderWallet = $adminOrderPayment !== null
                && WalletPayment::isWalletMethod((string) $adminOrderPayment['method'])
                ? WalletPayment::forPayment((int) $adminOrderPayment['id'])
                : null;
            $adminOrderRefunds = $adminOrderPayment !== null
                ? Refund::forPayment((int) $adminOrderPayment['id'])
                : array();
            $adminOrderCanVerifyWallet = $adminOrderWallet !== null
                && (string) $adminOrderWallet['status'] === WalletPayment::STATUS_PENDING;

            $adminOrderErrors = array('_csrf' => CSRF_ERROR_MESSAGE);
            $adminOrderRequestedStatus = post_string('status');

            require __DIR__ . '/../views/admin/orders/show.php';
            break;

        case 'admin_cod_confirm':
            // The Admin COD cash-collection form (P-18). Nothing is
            // preserved: the form carries no free-text input.
            require_once __DIR__ . '/../models/Order.php';
            require_once __DIR__ . '/../models/Payment.php';
            require_once __DIR__ . '/../models/OrderStatus.php';

            $adminOrderId = (int) ($context['id'] ?? 0);
            $adminOrder = $adminOrderId > 0 ? Order::find($adminOrderId) : null;

            if ($adminOrder === null) {
                http_response_code(404);
                require __DIR__ . '/../views/errors/404.php';
                break;
            }

            $adminOrderItems = Order::items($adminOrderId);
            $adminOrderPayment = Payment::forOrder($adminOrderId);

            // The view expects these two, so the form can be rendered again
            // with the error on it. The permitted statuses and the cash
            // confirmation are computed exactly as the controller does.
            $adminOrderTargets = OrderStatus::allowedTargets((string) $adminOrder['status']);
            $adminOrderCanConfirmCod = $adminOrderPayment !== null
                && (string) $adminOrderPayment['method'] === Payment::METHOD_COD
                && (string) $adminOrderPayment['status'] === 'pending';

            $adminOrderErrors = array('_csrf' => CSRF_ERROR_MESSAGE);
            $adminOrderRequestedStatus = '';

            require __DIR__ . '/../views/admin/orders/show.php';
            break;

        case 'admin_wallet_verify':
        case 'admin_wallet_reject':
        case 'admin_wallet_refund':
        case 'admin_refund_confirm':
        case 'admin_refund_fail':
            // The Admin wallet-payment forms (P-18). The rejection reason
            // and the refund details are preserved, because they are safe
            // free-text inputs the Admin would otherwise have to retype.
            require_once __DIR__ . '/../models/Order.php';
            require_once __DIR__ . '/../models/Payment.php';
            require_once __DIR__ . '/../models/OrderStatus.php';
            require_once __DIR__ . '/../models/WalletPayment.php';
            require_once __DIR__ . '/../models/Refund.php';

            $adminOrderId = (int) ($context['id'] ?? 0);
            $adminOrder = $adminOrderId > 0 ? Order::find($adminOrderId) : null;

            if ($adminOrder === null) {
                http_response_code(404);
                require __DIR__ . '/../views/errors/404.php';
                break;
            }

            $adminOrderItems = Order::items($adminOrderId);
            $adminOrderPayment = Payment::forOrder($adminOrderId);
            $adminOrderTargets = OrderStatus::allowedTargets((string) $adminOrder['status']);
            $adminOrderCanConfirmCod = $adminOrderPayment !== null
                && (string) $adminOrderPayment['method'] === Payment::METHOD_COD
                && (string) $adminOrderPayment['status'] === 'pending';

            $adminOrderWallet = $adminOrderPayment !== null
                && WalletPayment::isWalletMethod((string) $adminOrderPayment['method'])
                ? WalletPayment::forPayment((int) $adminOrderPayment['id'])
                : null;
            $adminOrderRefunds = $adminOrderPayment !== null
                ? Refund::forPayment((int) $adminOrderPayment['id'])
                : array();
            $adminOrderCanVerifyWallet = $adminOrderWallet !== null
                && (string) $adminOrderWallet['status'] === WalletPayment::STATUS_PENDING;

            $adminOrderErrors = array('_csrf' => CSRF_ERROR_MESSAGE);
            $adminOrderRequestedStatus = '';

            require __DIR__ . '/../views/admin/orders/show.php';
            break;

        case 'admin_user_reset':
            // The Admin password-reset form (P-20). Nothing is preserved:
            // the only field is a temporary password, which must never be
            // written back into the response (SEC-18).
            require_once __DIR__ . '/../models/UserAdmin.php';

            $adminUserId = (int) ($context['id'] ?? 0);
            $adminUser = $adminUserId > 0 ? UserAdmin::find($adminUserId) : null;

            if ($adminUser === null) {
                http_response_code(404);
                require __DIR__ . '/../views/errors/404.php';
                break;
            }

            $adminUserErrors = array('_csrf' => CSRF_ERROR_MESSAGE);
            $adminUserOrderCount = $adminUserId > 0 ? UserAdmin::orderCount($adminUserId) : 0;

            require __DIR__ . '/../views/admin/users/show.php';
            break;

        default:
            // No known form: show the access-denied page rather than
            // rendering an unrelated form.
            http_response_code(403);
            require_once __DIR__ . '/../controllers/ErrorController.php';
            (new ErrorController())->accessDenied(null);
            break;
    }

    exit;
}

/**
 * Rotate the CSRF token. Used after a successful login to prevent session
 * fixation of the token itself.
 */
function csrf_rotate(): void
{
    $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
}
