<?php
/**
 * GreenCorner — Cart Controller
 *
 * Serves the Cart page (P-08) and the background cart endpoints.
 *
 * Implements:
 *   FR-3.1  Add to Cart as a background (AJAX/JSON) request that updates
 *           the navigation count without a page reload
 *   FR-3.2  the Cart page lists the line items and the grand total
 *   FR-3.4  a visitor's Add to Cart is deferred until after login
 *   FR-3.5  remove an item (AJAX/JSON)
 *   FR-3.6  change a quantity (AJAX/JSON)
 *   FR-3.7  the current price is displayed; a changed price is announced
 *   FR-3.8  an unavailable item stays in the cart and blocks checkout
 *   FR-3.9  the empty-cart state
 *   FR-3.10 the cart is keyed by user_id, not by the session
 *   FR-3.11 the Proceed to Payment action
 *   FR-3.12 Add to Cart from the product detail page navigates to the cart
 *   FR-7.7  every state-changing request carries a CSRF token
 *
 * The cart is a User-only feature: an Admin may browse the storefront but
 * may not shop (FR-7.5).
 */

require_once dirname(__DIR__) . '/helpers/functions.php';
require_once dirname(__DIR__) . '/helpers/csrf.php';
require_once dirname(__DIR__) . '/helpers/authorization.php';
require_once dirname(__DIR__) . '/models/Cart.php';
require_once dirname(__DIR__) . '/models/Product.php';

class CartController
{
    /**
     * The Cart page (P-08).
     */
    public function index(array $parameters = array()): void
    {
        require_capability('shopping.cart');

        $user = current_user();
        $rows = Cart::items((int) $user['id']);

        $items = $this->buildItems($rows);
        $total = $this->totalOf($items);
        $cartCount = Cart::countItems((int) $user['id']);

        // FR-3.8: the Proceed to Payment action is blocked when an item is
        // unavailable (FR-3.11).
        $blocked = array();

        foreach ($items as $item) {
            if ($item['unavailable']) {
                $blocked[] = $item;
            }
        }

        require __DIR__ . '/../views/cart/index.php';
    }

    /**
     * Add a product to the cart (FR-3.1, FR-3.3, FR-3.4).
     *
     * The endpoint answers with JSON. It is also the endpoint the
     * progressive-enhancement JavaScript uses; without JavaScript the same
     * behaviour is reached through the form on the product detail page.
     */
    public function add(array $parameters = array()): void
    {
        $isJson = true;

        // FR-3.4: a visitor is sent to the login page, with the requested
        // product kept as a pending action.
        if (!is_logged_in()) {
            $productId = request_int('product_id', (int) ($_GET['product_id'] ?? 0));

            if ($productId > 0) {
                $_SESSION['pending_cart_product_id'] = $productId;
            }

            if ($this->wantsJson()) {
                json_response(array(
                    'ok'     => false,
                    'code'   => 'unauthenticated',
                    'error'  => 'Please sign in to add items to your cart.',
                    'login'  => APP_URL . '/auth/login',
                ), 401);
            }

            require_login(false);
        }

        require_capability('shopping.cart', $isJson);

        $user = current_user();

        // FR-7.7: the CSRF token is checked before anything is written.
        csrf_require($this->wantsJson(), 'cart_add');

        $productId = request_int('product_id');

        if ($productId <= 0) {
            $this->fail('Which product would you like to add?', 422);
        }

        $product = Product::findActiveById($productId);

        // FR-3.1: a soft-deleted or missing product fails with a clear
        // message, and the cart is unchanged.
        if ($product === null) {
            $this->fail('That product is no longer available.', 422);
        }

        $stock = (int) $product['stock'];

        if ($stock < 1) {
            $this->fail('That product is out of stock.', 422);
        }

        $existing = Cart::quantityOf((int) $user['id'], $productId);

        // FR-3.6: the quantity is capped at the available stock.
        if ($existing >= $stock) {
            $this->fail('Only ' . $stock . ' of that product are available.', 422);
        }

        Cart::addItem((int) $user['id'], $productId, $stock);

        $quantity = Cart::quantityOf((int) $user['id'], $productId);

        json_response(array(
            'ok'         => true,
            'code'       => 'added_to_cart',
            'message'    => 'Added to your cart.',
            'cart_count' => Cart::countItems((int) $user['id']),
            'quantity'   => $quantity,
            'product_id' => $productId,
            'cart_url'   => APP_URL . '/cart',
            // FR-3.1: the success toast carries a "View Cart" action.
            'toast_action'     => 'View Cart',
            'toast_action_url' => APP_URL . '/cart',
        ), 200);
    }

    /**
     * Change the quantity of a cart item (FR-3.6).
     */
    public function updateQuantity(array $parameters = array()): void
    {
        require_capability('shopping.cart', $this->wantsJson());

        $user = current_user();

        csrf_require($this->wantsJson(), 'cart_quantity');

        $productId = request_int('product_id');
        $quantity = request_int('quantity');

        if ($productId <= 0) {
            $this->fail('Which item would you like to change?', 422);
        }

        $product = Product::findActiveById($productId);

        if ($product === null) {
            // FR-3.8: a soft-deleted product stays in the cart, but its
            // quantity cannot be changed.
            $this->fail('That product is no longer available. Please remove it from your cart.', 422);
        }

        $result = Cart::setQuantity(
            (int) $user['id'],
            $productId,
            $quantity,
            (int) $product['stock']
        );

        if (!$result['ok']) {
            $this->fail($result['message'], 422);
        }

        $rows = Cart::items((int) $user['id']);
        $items = $this->buildItems($rows);

        json_response(array(
            'ok'         => true,
            'code'       => 'quantity_updated',
            'message'    => $result['message'],
            'clamped'    => $result['clamped'],
            'quantity'   => $result['quantity'],
            'cart_count' => Cart::countItems((int) $user['id']),
            'items'      => $items,
            'total'      => number_format($this->totalOf($items), 2, '.', ''),
            'total_display' => format_money($this->totalOf($items)),
            'cart_url'   => APP_URL . '/cart',
        ), 200);
    }

    /**
     * Remove an item from the cart (FR-3.5).
     */
    public function remove(array $parameters = array()): void
    {
        require_capability('shopping.cart', $this->wantsJson());

        $user = current_user();

        csrf_require($this->wantsJson(), 'cart_remove');

        $productId = request_int('product_id');

        if ($productId <= 0) {
            $this->fail('Which item would you like to remove?', 422);
        }

        Cart::removeItem((int) $user['id'], $productId);

        $rows = Cart::items((int) $user['id']);
        $items = $this->buildItems($rows);

        json_response(array(
            'ok'         => true,
            'code'       => 'item_removed',
            'message'    => 'Removed from your cart.',
            'cart_count' => Cart::countItems((int) $user['id']),
            'items'      => $items,
            'total'      => number_format($this->totalOf($items), 2, '.', ''),
            'total_display' => format_money($this->totalOf($items)),
            'cart_url'   => APP_URL . '/cart',
        ), 200);
    }

    /**
     * Empty the cart.
     */
    public function clear(array $parameters = array()): void
    {
        require_capability('shopping.cart', $this->wantsJson());

        $user = current_user();

        csrf_require($this->wantsJson(), 'cart_clear');

        Cart::clear((int) $user['id']);

        json_response(array(
            'ok'         => true,
            'code'       => 'cart_cleared',
            'message'    => 'Your cart is now empty.',
            'cart_count' => 0,
            'items'      => array(),
            'total'      => '0.00',
            'total_display' => format_money(0),
            'cart_url'   => APP_URL . '/cart',
        ), 200);
    }

    /**
     * The cart's contents, as JSON. Used by the page to re-render after a
     * background change.
     */
    public function summary(array $parameters = array()): void
    {
        require_capability('shopping.cart', $this->wantsJson());

        $user = current_user();
        $rows = Cart::items((int) $user['id']);
        $items = $this->buildItems($rows);

        json_response(array(
            'ok'         => true,
            'code'       => 'cart_summary',
            'cart_count' => Cart::countItems((int) $user['id']),
            'items'      => $items,
            'total'      => number_format($this->totalOf($items), 2, '.', ''),
            'total_display' => format_money($this->totalOf($items)),
        ), 200);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Whether the current request wants a JSON answer.
     *
     * @return bool
     */
    private function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        return (is_string($accept) && stripos($accept, 'application/json') !== false)
            || (is_string($contentType) && stripos($contentType, 'application/json') !== false);
    }

    /**
     * Answer with an error.
     *
     * The JSON body carries a machine-readable code and a human-readable
     * message, as the JSON contract requires (SRS §9.4).
     *
     * @param string $message
     * @param int    $status
     * @return void
     */
    private function fail(string $message, int $status = 422): void
    {
        json_response(array(
            'ok'    => false,
            'code'  => $status === 422 ? 'invalid_request' : 'request_failed',
            'error' => $message,
        ), $status);
    }

    /**
     * Build the display rows for the cart.
     *
     * Each row carries the current price, the price at add, the current
     * stock, and the flags the view needs: whether the price changed
     * (FR-3.7) and whether the item is unavailable (FR-3.8).
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
            $priceAtAdd = (float) $row['price_at_add'];
            $stock = (int) ($row['current_stock'] ?? 0);
            $deleted = (int) ($row['is_deleted'] ?? 0) === 1;

            // FR-3.8: a soft-deleted or zero-stock product is unavailable.
            // It is never removed automatically.
            $unavailable = $deleted || $stock < 1;

            $items[] = array(
                'cart_item_id'  => (int) $row['cart_item_id'],
                'product_id'    => (int) $row['product_id'],
                'name'          => (string) ($row['product_name'] ?? ''),
                'quantity'      => $quantity,
                'unit_price'    => $currentPrice,
                'price_at_add'  => $priceAtAdd,
                'line_total'    => round($currentPrice * $quantity, 2),
                'stock'         => $stock,
                'thumbnail_path' => (string) ($row['thumbnail_path'] ?? ''),
                'image_path'    => (string) ($row['image_path'] ?? ''),
                'deleted'       => $deleted,
                'unavailable'   => $unavailable,
                // FR-3.7: the price changed since the item was added.
                'price_changed' => !$deleted && abs($currentPrice - $priceAtAdd) >= 0.005,
                // FR-3.8: the stock fell below the cart quantity.
                'stock_reduced' => !$deleted && $stock > 0 && $stock < $quantity,
                'max_quantity'  => $stock,
            );
        }

        return $items;
    }

    /**
     * The grand total of the cart, computed from the CURRENT prices
     * (FR-3.2, FR-3.7).
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
