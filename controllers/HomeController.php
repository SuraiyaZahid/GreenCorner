<?php
/**
 * GreenCorner — Home Controller
 *
 * Serves the landing page (P-01) and the temporary Phase 1 dashboard
 * placeholders.
 */

require_once dirname(__DIR__) . '/helpers/functions.php';
require_once dirname(__DIR__) . '/helpers/authorization.php';
require_once dirname(__DIR__) . '/models/Product.php';
require_once dirname(__DIR__) . '/models/Cart.php';
require_once dirname(__DIR__) . '/models/Order.php';

class HomeController
{
    /**
     * The landing page (P-01).
     *
     * @param array<string, mixed> $parameters The URL parameters (unused).
     */
    public function index(array $parameters = array()): void
    {
        $products = Product::latest(8);
        $categories = Product::categories();

        require __DIR__ . '/../views/home.php';
    }

    /**
     * The User Dashboard (P-11).
     *
     * A User only. An Admin receives HTTP 403 (FR-7.5).
     *
     * @param array<string, mixed> $parameters The URL parameters (unused).
     */
    public function userDashboard(array $parameters = array()): void
    {
        require_capability('shopping.dashboard');

        $user = current_user();
        $cartCount = Cart::countItems((int) $user['id']);

        // FR-5.1: the recent orders, most recent first.
        $orders = Order::forUser((int) $user['id'], 5, 0);

        require __DIR__ . '/../views/dashboard/user.php';
    }

    /**
     * The Admin Dashboard (P-14).
     *
     * An Admin only. A User receives HTTP 403 (FR-7.4).
     *
     * @param array<string, mixed> $parameters The URL parameters (unused).
     */
    public function adminDashboard(array $parameters = array()): void
    {
        require_capability('admin.dashboard');

        require __DIR__ . '/../views/dashboard/admin.php';
    }
}
