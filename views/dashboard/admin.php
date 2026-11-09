<?php
/**
 * GreenCorner — Admin Dashboard (P-14)
 *
 * Phase 1 placeholder. The counters for products, orders and users (FR-6.1)
 * are built in Phase 6.
 */

require_once __DIR__ . '/../../helpers/functions.php';

$pageTitle = 'Admin dashboard';

require __DIR__ . '/../layouts/header.php';
?>

<section class="dashboard">
    <h1>Admin dashboard</h1>
    <p class="panel-intro">
        Manage the catalogue, categories, orders and users from here.
    </p>

    <div class="empty-state">
        <h2>Management tools arrive in Phase 6</h2>
        <p>
            Product management, category management, order management and the
            user list will appear in this area.
        </p>
    </div>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
