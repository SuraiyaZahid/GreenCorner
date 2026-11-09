<?php
/**
 * GreenCorner — Admin User Controller
 *
 * The Admin user management: the Admin User List (P-19) and the Admin User
 * Detail page (P-20).
 *
 * Implements:
 *   FR-6.10 the list shows every account with its name, email and
 *           registration date; the Admin can set a temporary password that
 *           forces the User to change it at the next login; the Admin can
 *           never view an existing password or hash
 *   FR-1.9   the forced-change flag sends the User to the change-password
 *            page before any other protected page
 *   FR-6.12  the Admin interface offers no way to set a role
 *   FR-7.4   a User is refused with HTTP 403 before any state change
 *   FR-7.7   every state-changing request carries a CSRF token
 *
 * Decision D-D: a successful reset invalidates every other session of the
 * target account, so an old session cannot keep using the account after its
 * password has been reset. The temporary password is never written to a log
 * and never displayed again; the Admin hands it over out of band.
 */

require_once dirname(__DIR__) . '/helpers/functions.php';
require_once dirname(__DIR__) . '/helpers/csrf.php';
require_once dirname(__DIR__) . '/helpers/authorization.php';
require_once dirname(__DIR__) . '/helpers/logging.php';
require_once dirname(__DIR__) . '/helpers/session_handler.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/UserAdmin.php';
require_once dirname(__DIR__) . '/models/Order.php';

class AdminUserController
{
    /**
     * The Admin User List (P-19).
     *
     * Every registered account is listed with its name, email and
     * registration date, 20 to a page (FR-6.10 acceptance criterion 1).
     *
     * @param array<string, mixed> $parameters The URL parameters (unused).
     */
    public function index(array $parameters = array()): void
    {
        require_capability('admin.users');

        $totalResults = UserAdmin::countAll();
        $lastPage = UserAdmin::pageCount($totalResults, UserAdmin::PER_PAGE);
        $page = Order::normalisePage(get_string('page', '1'), $lastPage);

        $adminUsers = UserAdmin::listAll(UserAdmin::PER_PAGE, ($page - 1) * UserAdmin::PER_PAGE);

        $query = array();

        require __DIR__ . '/../views/admin/users/index.php';
    }

    /**
     * The Admin User Detail page (P-20).
     *
     * The page shows the account's details and offers the password-reset
     * form. It shows **no** password and **no** hash, and it offers **no**
     * role control (FR-6.10 acceptance criterion 3, RBAC-9).
     *
     * @param array<string, mixed> $parameters 'id' holds the user id.
     */
    public function show(array $parameters = array()): void
    {
        require_capability('admin.users');

        $id = isset($parameters['id']) ? (int) $parameters['id'] : 0;

        if ($id <= 0) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        $adminUser = UserAdmin::find($id);

        if ($adminUser === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        $adminUserOrderCount = UserAdmin::orderCount($id);
        $adminUserErrors = array();

        require __DIR__ . '/../views/admin/users/show.php';
    }

    /**
     * Set a temporary password on an account (FR-6.10).
     *
     * The account's forced-change flag is set, so the User must change the
     * password before reaching any other protected page (FR-1.9). Every
     * other session of that account is invalidated, so a session established
     * with the old password stops working (decision D-D).
     *
     * @param array<string, mixed> $parameters 'id' holds the user id.
     */
    public function resetPassword(array $parameters = array()): void
    {
        require_capability('admin.users');

        $id = isset($parameters['id']) ? (int) $parameters['id'] : 0;

        if ($id <= 0) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        csrf_require(false, 'admin_user_reset', array('id' => $id));

        $admin = current_user();
        $adminId = (int) ($admin['id'] ?? 0);

        $target = UserAdmin::find($id);

        if ($target === null) {
            // The audit record is written even for a failure, so the trail
            // records the attempt as well as the outcome.
            gc_log(LOG_CHANNEL_ADMIN_ACTIONS, 'password_reset', array(
                'admin_user_id'  => $adminId,
                'target_user_id' => $id,
                'outcome'        => 'failed',
                'reason'         => 'not_found',
            ));

            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        $temporaryPassword = request_string('temporary_password');
        $confirmation = request_string('temporary_password_confirmation');

        $errors = array();

        if ($temporaryPassword === '') {
            $errors['temporary_password'] = 'Please enter a temporary password.';
        } elseif (strlen($temporaryPassword) < User::MIN_PASSWORD_LENGTH) {
            $errors['temporary_password'] = 'The temporary password must be at least '
                . User::MIN_PASSWORD_LENGTH . ' characters long.';
        } elseif ($temporaryPassword !== $confirmation) {
            $errors['temporary_password_confirmation'] = 'The two temporary passwords do not match.';
        }

        if ($errors !== array()) {
            gc_log(LOG_CHANNEL_ADMIN_ACTIONS, 'password_reset', array(
                'admin_user_id'  => $adminId,
                'target_user_id' => $id,
                'outcome'        => 'failed',
                'reason'         => 'validation',
            ));

            $adminUser = $target;
            $adminUserOrderCount = UserAdmin::orderCount($id);
            $adminUserErrors = $errors;

            require __DIR__ . '/../views/admin/users/show.php';
            exit;
        }

        // The write. The temporary password is hashed here and never stored
        // in plaintext (FR-6.10 acceptance criterion 4).
        UserAdmin::setTemporaryPassword($id, $temporaryPassword);

        // Decision D-D: the old sessions stop working. The Admin's own
        // session is not affected, because the target is a different
        // account.
        invalidate_other_sessions($id);

        // The audit record. It carries no password and no temporary
        // credential of any kind.
        gc_log(LOG_CHANNEL_ADMIN_ACTIONS, 'password_reset', array(
            'admin_user_id'  => $adminId,
            'target_user_id' => $id,
            'outcome'        => 'success',
        ));

        set_flash(
            'success',
            'The temporary password is in force. The account must change it before it can be used, and its other sessions have been signed out.'
        );

        redirect('/admin/users/' . $id);
    }
}
