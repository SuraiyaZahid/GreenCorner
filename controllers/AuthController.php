<?php
/**
 * GreenCorner — Authentication Controller
 *
 * Handles registration, login, logout and the forced password change.
 *
 * Implements:
 *   FR-1.1  registration (User role, active, hashed, NOT auto-authenticated)
 *   FR-1.2  unique email
 *   FR-1.3  password rules and hashing
 *   FR-1.4  role assignment at registration
 *   FR-1.5  login with a generic failure message
 *   FR-1.6  logout
 *   FR-1.7  post-login landing (User -> User Dashboard, Admin -> Admin Dashboard)
 *   FR-1.9  forced password change
 *   FR-1.10 session lifetime (enforced by the session handler)
 *   FR-3.4  a visitor's pending Add-to-Cart is resumed after login
 */

require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/authorization.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/LoginAttempt.php';

class AuthController
{
    /**
     * Show the registration form (P-02).
     */
    public function showRegister(array $parameters = array()): void
    {
        require __DIR__ . '/../views/auth/register.php';
    }

    /**
     * Handle a registration submission (FR-1.1 … FR-1.4).
     */
    public function register(array $parameters = array()): void
    {
        csrf_require(false, 'register');

        $fullName = post_string('full_name');
        $email = post_string('email');
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['password_confirmation'] ?? '');

        $errors = User::validateRegistration($fullName, $email, $password, $confirmPassword);

        // FR-1.2: the email must be unique. The check happens here and the
        // database UNIQUE index enforces it independently.
        if ($errors === array() && User::emailExists($email)) {
            $errors['email'] = 'This email is already registered.';
        }

        if ($errors !== array()) {
            $_SESSION['register_errors'] = $errors;
            $_SESSION['register_old'] = array('full_name' => $fullName, 'email' => $email);
            set_flash('error', 'Please correct the errors below and try again.');
            redirect('/auth/register');
        }

        // FR-1.4: the role always comes from a server-side lookup. Any role
        // field in the request body is discarded.
        $userRoleId = User::roleId('User');
        if ($userRoleId === null) {
            throw new RuntimeException('The User role is missing from the database. Run database/schema.sql first.');
        }

        try {
            User::create($fullName, $email, $password, $userRoleId);
        } catch (RuntimeException $e) {
            // ER-17: a duplicate key means the email was registered between
            // the check above and the insert.
            if (db_error_code($e->getCode()) === 'duplicate_entry') {
                $_SESSION['register_errors'] = array('email' => 'This email is already registered.');
                $_SESSION['register_old'] = array('full_name' => $fullName, 'email' => $email);
                set_flash('error', 'Please correct the errors below and try again.');
                redirect('/auth/register');
            }
            throw $e;
        }

        unset($_SESSION['register_errors'], $_SESSION['register_old']);

        // FR-1.1: registration does NOT authenticate the account.
        set_flash('success', 'Your account has been created. Please sign in to continue.');
        redirect('/auth/login');
    }

    /**
     * Show the login form (P-03).
     */
    public function showLogin(array $parameters = array()): void
    {
        require __DIR__ . '/../views/auth/login.php';
    }

    /**
     * Handle a login submission (FR-1.5).
     *
     * A failed attempt re-renders the login form with the error shown
     * inline (D-003). The email the visitor typed is preserved; the password
     * field is always empty.
     */
    public function login(array $parameters = array()): void
    {
        csrf_require(false, 'login');

        $email = post_string('email');
        $password = (string) ($_POST['password'] ?? '');

        // FR-7.2: the login page is for visitors; an authenticated account
        // goes straight to its dashboard.
        if (is_logged_in()) {
            $this->redirectToDashboard();
        }

        // ER-2: both fields are required.
        if ($email === '' || $password === '') {
            $this->renderLoginWithError('Please enter your email address and password.', $email);
        }

        // NFR-S-06 / SEC-13: rate-limit by source address.
        $ip = LoginAttempt::client_ip();
        if (LoginAttempt::isLockedOut($ip)) {
            $this->renderLoginWithError(
                'Too many failed login attempts. Please try again in '
                . (LOGIN_LOCKOUT_SECONDS / 60) . ' minutes.',
                $email
            );
        }

        $user = User::findByEmail($email);

        // SEC-12 / FR-1.5: a wrong password and an unknown email produce the
        // SAME message, so account existence is never disclosed.
        $genericError = 'Those credentials do not match our records. Please try again.';

        if ($user === null || !User::verifyPassword($password, (string) $user['password_hash'])) {
            LoginAttempt::recordFailure($ip, $email);
            $this->renderLoginWithError($genericError, $email);
        }

        // FR-6.11 / BR-14: a deactivated account cannot log in.
        if ((int) $user['is_active'] !== 1) {
            LoginAttempt::recordFailure($ip, $email);
            $this->renderLoginWithError(
                'This account has been deactivated. Please contact the store.',
                $email
            );
        }

        LoginAttempt::clearFailures($ip);

        // SEC-9 / FR-1.5: regenerate the session identifier on login to
        // prevent session fixation.
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_role'] = (string) $user['role_name'];

        // The account is established part-way through this request, so the
        // value cached earlier (when nobody was logged in) must be dropped.
        forget_cached_user();

        // Link the session row to the account, for traceability. The handler
        // instance that PHP will use when the request ends is reused, so the
        // account id is stored in the row that PHP creates at shutdown.
        try {
            $handler = session_handler_instance();
            if ($handler !== null) {
                $handler->linkToUser(session_id(), (int) $user['id']);
            }
        } catch (RuntimeException $e) {
            error_log('[GreenCorner] Could not link the session to the user: ' . $e->getMessage());
        }

        // Rotate the CSRF token for the new session.
        csrf_rotate();

        // FR-3.4: resume a pending Add-to-Cart action from the visitor
        // session, if there was one.
        $pendingMessage = $this->resumePendingCartAction($user);

        if ($pendingMessage !== null) {
            set_flash('error', $pendingMessage);
            $this->redirectToDashboard();
        }

        // FR-1.9: an account that must change its password goes straight to
        // the change-password page instead of the dashboard.
        if ((int) $user['must_change_password'] === 1) {
            redirect('/auth/change-password');
        }

        // FR-1.7: land on the dashboard appropriate to the role.
        $this->redirectToDashboard();
    }

    /**
     * Handle a logout request (FR-1.6).
     *
     * The cart is not cleared: it lives in the database and is keyed by
     * user_id (FR-3.10).
     */
    public function logout(array $parameters = array()): void
    {
        // A logout is a state-changing request, so it carries a CSRF token.
        csrf_require(false, 'logout');

        // FR-1.6 / SEC-11: destroy the server-side session record and clear
        // the browser cookie.
        destroy_secure_session();

        redirect('/');
    }

    /**
     * Show the forced change password form (P-04).
     */
    public function showChangePassword(array $parameters = array()): void
    {
        require_login();

        require __DIR__ . '/../views/auth/change_password.php';
    }

    /**
     * Handle the forced password change (FR-1.9).
     */
    public function changePassword(array $parameters = array()): void
    {
        csrf_require(false, 'change_password');
        require_login();

        $user = current_user();
        if ($user === null) {
            redirect('/auth/login');
        }

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['new_password_confirmation'] ?? '');

        $errors = array();

        // FR-5.6: the current password must be provided. For a forced
        // change the user authenticated with a temporary password, so the
        // current password is verified before the new one is accepted.
        if (!User::verifyPassword($currentPassword, (string) $user['password_hash'])) {
            $errors['current_password'] = 'Your current password is not correct.';
        }

        if ($newPassword === '') {
            $errors['new_password'] = 'Please choose a new password.';
        } elseif (strlen($newPassword) < User::MIN_PASSWORD_LENGTH) {
            $errors['new_password'] = 'Your new password must be at least '
                . User::MIN_PASSWORD_LENGTH . ' characters long.';
        }

        if ($confirmPassword !== '' && $newPassword !== $confirmPassword) {
            $errors['new_password_confirmation'] = 'The two passwords do not match.';
        }

        if ($errors !== array()) {
            $_SESSION['password_errors'] = $errors;
            set_flash('error', 'Please correct the errors below and try again.');
            redirect('/auth/change-password');
        }

        unset($_SESSION['password_errors']);

        // FR-1.9: store the new password and clear the forced-change flag.
        User::setPassword((int) $user['id'], $newPassword, false);

        // The forced-change flag has changed, so the cached account is
        // refreshed before the redirect decides where to send the account.
        forget_cached_user();

        // SEC-9 / FR-5.6: rotate the session identifier after a password
        // change.
        session_regenerate_id(true);
        csrf_rotate();

        set_flash('success', 'Your password has been changed.');
        $this->redirectToDashboard();
    }

    /**
     * Redirect to the dashboard appropriate to the account's role (FR-1.7).
     */
    private function redirectToDashboard(): void
    {
        $role = current_role();

        if ($role === 'Admin') {
            redirect('/admin/dashboard');
        }

        redirect('/dashboard');
    }

    /**
     * Re-render the login form with an inline error (D-003).
     *
     * The email the visitor typed is preserved so they do not have to type it
     * again. The password is never preserved: the field is always empty.
     *
     * The error is stored in the session and the view reads it, because the
     * view is rendered by a different function than the one that detects the
     * failure.
     *
     * @param string $message The message to show.
     * @param string $email   The email the visitor typed.
     * @return void
     */
    private function renderLoginWithError(string $message, string $email): void
    {
        $_SESSION['login_error'] = $message;
        $_SESSION['login_email'] = $email;

        require __DIR__ . '/../views/auth/login.php';
        exit;
    }

    /**
     * Resume a visitor's pending Add-to-Cart action after login (FR-3.4).
     *
     * The pending action is single-shot: it is cleared whether it succeeds
     * or fails, so it can never execute twice.
     *
     * @param array<string, mixed> $user
     * @return string|null An error message when the product is unavailable,
     *                     otherwise null.
     */
    private function resumePendingCartAction(array $user): ?string
    {
        if (!isset($_SESSION['pending_cart_product_id'])) {
            return null;
        }

        $productId = (int) $_SESSION['pending_cart_product_id'];

        // FR-3.4: clear the pending action so it cannot execute again.
        unset($_SESSION['pending_cart_product_id']);

        if ($productId <= 0) {
            return null;
        }

        // The cart is a User-only feature (FR-7.5); an Admin may not shop.
        if ((string) $user['role_name'] !== 'User') {
            return null;
        }

        require_once __DIR__ . '/../models/Product.php';

        $product = Product::findActiveById($productId);
        if ($product === null) {
            return 'That product is no longer available, so it was not added to your cart.';
        }

        if ((int) $product['stock'] < 1) {
            return 'That product is out of stock, so it was not added to your cart.';
        }

        Cart::addItem((int) $user['id'], $productId, (int) $product['stock']);

        return null;
    }
}
