<?php
/**
 * GreenCorner — Admin User List (P-19)
 *
 * Implements:
 *   FR-6.10 the list shows, for every registered account, its name, email
 *           and registration date
 *
 * The list shows **no** password material of any kind: there is no column
 * for it and there is no control that reveals it (FR-6.10 acceptance
 * criterion 3, SEC-18).
 *
 * Expects:
 *   $adminUsers  array<int, array<string, mixed>>  The accounts on this page.
 *   $totalResults int                              The number of accounts.
 *   $page        int                               The current page.
 *   $lastPage    int                               The last valid page.
 *   $query       array<string, string>              The query parameters to keep.
 */

require_once __DIR__ . '/../../../helpers/functions.php';

$pageTitle = 'Users';
$adminSection = 'users';

require __DIR__ . '/../../layouts/header.php';
?>

<?php require __DIR__ . '/../../layouts/nav.php'; ?>

<section class="admin-panel">
    <div class="admin-panel-header">
        <div>
            <h1>Users</h1>
            <p class="panel-intro">
                <?= e((string) $totalResults) ?>
                <?= $totalResults === 1 ? 'account' : 'accounts' ?> in total
                &middot; page <?= e((string) $page) ?> of <?= e((string) $lastPage) ?>
            </p>
        </div>
    </div>

    <?php if ($adminUsers === array()): ?>
        <div class="empty-state">
            <h2>No accounts yet</h2>
            <p>Accounts appear here as soon as somebody registers.</p>
        </div>
    <?php else: ?>
        <table class="admin-users-table">
            <caption class="visually-hidden">Registered accounts</caption>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Registered</th>
                    <th scope="col">Role</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($adminUsers as $account): ?>
                    <?php $isActive = (int) $account['is_active'] === 1; ?>
                    <tr class="admin-user-row">
                        <td><?= e((string) $account['full_name']) ?></td>
                        <td><?= e((string) $account['email']) ?></td>
                        <td><?= e(date('j M Y', strtotime((string) $account['created_at']))) ?></td>
                        <td>
                            <span class="badge"><?= e((string) $account['role_name']) ?></span>
                            <?php if (!$isActive): ?>
                                <span class="badge badge-deleted">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a
                                class="button button-small button-secondary"
                                href="<?= e(APP_URL) ?>/admin/users/<?= e((string) $account['id']) ?>"
                            >
                                View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // The pagination partial builds the URLs from this base path.
        $basePath = '/admin/users';
        require __DIR__ . '/../../partials/admin_pagination.php';
        ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../layouts/footer.php'; ?>
