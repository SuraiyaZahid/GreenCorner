<?php
/**
 * GreenCorner — Admin page navigation
 *
 * The shared secondary navigation shown on every Admin page (P-14, P-15,
 * P-16, P-17, P-18, P-19, P-20).
 *
 * Expects:
 *   $adminSection  string  The active section: 'dashboard', 'products',
 *                           'categories', 'orders' or 'users'.
 */

$adminSection = $adminSection ?? 'dashboard';
?>

<aside class="admin-sidebar">
    <p class="admin-sidebar-title">Manage store</p>
    <nav class="admin-nav" aria-label="Admin sections">
        <a
            class="admin-nav-link<?= $adminSection === 'dashboard' ? ' is-active' : '' ?>"
            href="<?= e(APP_URL) ?>/admin/dashboard"
        >
            Dashboard
        </a>
        <a
            class="admin-nav-link<?= $adminSection === 'products' ? ' is-active' : '' ?>"
            href="<?= e(APP_URL) ?>/admin/products"
        >
            Products
        </a>
        <a
            class="admin-nav-link<?= $adminSection === 'categories' ? ' is-active' : '' ?>"
            href="<?= e(APP_URL) ?>/admin/categories"
        >
            Categories
        </a>
        <a
            class="admin-nav-link<?= $adminSection === 'orders' ? ' is-active' : '' ?>"
            href="<?= e(APP_URL) ?>/admin/orders"
        >
            Orders
        </a>
        <a
            class="admin-nav-link<?= $adminSection === 'users' ? ' is-active' : '' ?>"
            href="<?= e(APP_URL) ?>/admin/users"
        >
            Users
        </a>
    </nav>
</aside>
