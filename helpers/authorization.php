<?php
/**
 * GreenCorner — Centralised Authorisation
 *
 * This file is the ONLY place where role capabilities are defined
 * (FR-7.1, NFR-M-03, SC-5). The capability matrix below is the normative
 * matrix from SRS §5.1 and the request-to-role matrix from SRS §14.3.
 *
 * Every protected server-side request routes through this module. Hiding a
 * button in a view is never sufficient (FR-7.1, RBAC-2).
 *
 * Implements:
 *   FR-7.1  one central mechanism, defined in exactly one place
 *   FR-7.2  unauthenticated access -> 401 and a redirect to login
 *   FR-7.3  the current role is re-read from the database on every request
 *   FR-7.4  a User reaching an Admin resource -> 403
 *   FR-7.5  an Admin reaching a shopping resource -> 403
 *   FR-7.6  session identity and status validation
 *   FR-7.7  CSRF protection on every state-changing request
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/session_handler.php';

// ---------------------------------------------------------------------------
// The capability matrix — the single source of truth (SRS §5.1)
// ---------------------------------------------------------------------------

/**
 * Actions that a protected route may require.
 *
 * 'shopping' covers the cart, payment, checkout, order confirmation and the
 * user dashboard — everything an Admin is forbidden from (FR-7.5).
 * 'admin' covers the admin dashboard and all management features.
 */
const ROLE_USER = 'User';
const ROLE_ADMIN = 'Admin';

/**
 * The capability matrix.
 *
 * Key   = the action a route requires.
 * Value = the set of roles permitted to perform that action.
 *
 * A role that is absent from a list is denied that action.
 */
function capability_matrix(): array
{
    return array(
        // Public storefront — available to everyone, including Admins
        // (FR-7.5: "An Admin may browse the catalogue and the storefront").
        'browse' => array(ROLE_USER, ROLE_ADMIN),

        // Authentication
        'register' => array(),
        'login'    => array(),

        // Customer shopping — Users only (FR-7.5, SRS §14.3)
        'shopping' => array(ROLE_USER),

        // Admin management — Admins only (FR-7.4, SRS §14.3)
        'admin' => array(ROLE_ADMIN),
    );
}

/**
 * Resolve the action group for a capability name.
 *
 * Keeps the matrix readable while allowing controllers to ask for a
 * capability such as 'admin.dashboard' or 'shopping.cart'.
 *
 * @param string $capability
 * @return string|null The action group, or null when the capability is unknown.
 */
function capability_group(string $capability): ?string
{
    $parts = explode('.', $capability);
    $group = $parts[0];

    // Guard against a capability name that does not exist in the matrix.
    $matrix = capability_matrix();
    if (!array_key_exists($group, $matrix)) {
        return null;
    }

    return $group;
}

/**
 * Check whether the given role is permitted to perform the given capability.
 *
 * @param string $role       'User' or 'Admin'.
 * @param string $capability e.g. 'admin.dashboard', 'shopping.cart'.
 * @return bool
 */
function role_can(string $role, string $capability): bool
{
    $group = capability_group($capability);
    if ($group === null) {
        // An unknown capability is denied by default — a new protected
        // endpoint cannot be added without a corresponding matrix entry
        // (FR-7.1).
        return false;
    }

    $matrix = capability_matrix();

    return in_array($role, $matrix[$group], true);
}

// ---------------------------------------------------------------------------
// The current account — re-read from the database on every request
// ---------------------------------------------------------------------------

/**
 * The authenticated account for this request, or null for a visitor.
 *
 * @var array<string, mixed>|null
 */
$GLOBALS['gc_current_user'] = null;

/**
 * Whether the account has already been looked up for this request.
 *
 * A separate flag is needed because the account itself may legitimately be
 * null (a visitor), so the global alone cannot tell "not looked up yet" from
 * "looked up and found nothing".
 *
 * @var bool
 */
$GLOBALS['gc_current_user_loaded'] = false;

/**
 * Load the authenticated account, re-reading its current role and status
 * from the database on every request (FR-7.3, RBAC-6, SEC-14).
 *
 * The role used for any authorisation decision is therefore always the
 * current database value, never a value cached at login time.
 *
 * @return array<string, mixed>|null The account, or null for a visitor.
 */
function current_user(): ?array
{
    if ($GLOBALS['gc_current_user_loaded']) {
        return $GLOBALS['gc_current_user'];
    }

    $GLOBALS['gc_current_user'] = null;

    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($userId <= 0) {
        // No account: mark the lookup as done so the database is not asked
        // again on this request.
        $GLOBALS['gc_current_user_loaded'] = true;

        return null;
    }

    // The flag is deliberately NOT set before the database call below. If
    // the lookup throws, the flag stays false, so a later call retries and
    // the failure is not silently converted into "no account" (decision
    // D-018). It is set once the answer is known.
    $user = db_select_one(
        "SELECT u.`id`, u.`full_name`, u.`email`, u.`password_hash`, u.`role_id`,
                u.`is_active`, u.`must_change_password`, u.`created_at`,
                r.`name` AS `role_name`
           FROM `users` u
           JOIN `roles` r ON r.`id` = u.`role_id`
          WHERE u.`id` = ?",
        'i',
        array($userId)
    );

    $GLOBALS['gc_current_user_loaded'] = true;

    if ($user === null) {
        // The account has been removed from the database: invalidate.
        $GLOBALS['gc_current_user'] = null;
        unset($_SESSION['user_id']);
        return null;
    }

    // FR-7.3 / BR-14: a deactivated account's session becomes invalid on the
    // next authenticated request.
    if ((int) $user['is_active'] !== 1) {
        $GLOBALS['gc_current_user'] = null;
        unset($_SESSION['user_id']);
        return null;
    }

    $GLOBALS['gc_current_user'] = $user;

    return $GLOBALS['gc_current_user'];
}

/**
 * Forget the account cached for this request.
 *
 * Called after a login, because the account is established part-way through
 * the request: the front controller has already asked for the current account
 * (and cached the answer — "not logged in") before the login succeeded.
 */
function forget_cached_user(): void
{
    $GLOBALS['gc_current_user'] = null;
    $GLOBALS['gc_current_user_loaded'] = false;
}

/**
 * The authenticated account for this request, or null if the database cannot
 * be reached.
 *
 * This is the resilient counterpart of current_user(). The shared layout uses
 * it so that a database outage degrades the navigation instead of blanking
 * every page (decision D-018).
 *
 * Two properties matter here:
 *
 *   1. It NEVER throws. current_user() propagates a RuntimeException when the
 *      database is unreachable, which would abort the rendering of the page
 *      that includes the shared layout — including the 500 page itself.
 *   2. It RE-LOGS the failure. A caught exception is never silently discarded:
 *      the detail is written to the server-side error log and is never
 *      rendered to the client (SRS §15.3, NFR-R-05, decision D-011).
 *
 * Returning null means "no account could be confirmed", which the layout
 * renders as the public, signed-out navigation. That is a deliberate
 * degradation: the page still renders, and the request still completes.
 *
 * @return array<string, mixed>|null The account, or null for a visitor or an
 *                                   unavailable database.
 */
function safe_current_user(): ?array
{
    try {
        return current_user();
    } catch (Throwable $e) {
        // Log the detail server-side; never render it (NFR-R-05, D-011).
        error_log('[GreenCorner] current_user() failed: ' . $e->getMessage());

        // The account is unknown for this request. The "loaded" flag is NOT
        // set, so a later call retries the lookup and logs again rather than
        // silently reporting "no account" (decision D-018).
        $GLOBALS['gc_current_user'] = null;

        return null;
    }
}

/**
 * The role of the current account, or null for a visitor.
 */
function current_role(): ?string
{
    $user = current_user();

    return $user === null ? null : (string) $user['role_name'];
}

/**
 * Whether the current request is authenticated.
 */
function is_logged_in(): bool
{
    return current_user() !== null;
}

/**
 * Whether the current account must change its password before continuing
 * (FR-1.9).
 */
function must_change_password(): bool
{
    $user = current_user();

    return $user !== null && (int) $user['must_change_password'] === 1;
}

// ---------------------------------------------------------------------------
// Enforcement
// ---------------------------------------------------------------------------

/**
 * Require that the request is authenticated.
 *
 * Unauthenticated access to a protected page redirects to login with HTTP
 * status 401 (FR-7.2, RBAC-5). The originally requested destination is kept
 * so the User is returned to it after login.
 *
 * @param bool $isJsonRequest True for JSON endpoints, which get a JSON error
 *                            body instead of a redirect.
 * @return void
 */
function require_login(bool $isJsonRequest = false): void
{
    if (is_logged_in()) {
        return;
    }

    if ($isJsonRequest) {
        json_response(array(
            'ok'    => false,
            'code'  => 'unauthenticated',
            'error' => 'You must be logged in to perform this action.',
        ), 401);
    }

    // Keep the intended destination so login can return the User to it.
    $intended = $_SERVER['REQUEST_URI'] ?? '';
    if ($intended !== '' && !str_starts_with($intended, '/auth/login')) {
        $_SESSION['intended_url'] = $intended;
    }

    // FR-7.2: 401 with a redirect to the login page.
    // The status code is passed to header() as its third argument because a
    // Location header makes PHP default the status to 302, which would
    // replace an earlier http_response_code() call.
    header('Location: ' . APP_URL . '/auth/login', true, 401);
    exit;
}

/**
 * Require that the current account holds the given capability.
 *
 * - A visitor is redirected to login with 401 (FR-7.2).
 * - An account with the wrong role receives HTTP 403 and the 403 page
 *   (FR-7.4 for a User reaching an Admin resource, FR-7.5 for an Admin
 *   reaching a shopping resource).
 *
 * @param string $capability    e.g. 'admin.dashboard', 'shopping.cart'.
 * @param bool   $isJsonRequest True for JSON endpoints.
 * @return void
 */
function require_capability(string $capability, bool $isJsonRequest = false): void
{
    // FR-7.2: unauthenticated requests never reach a role check.
    require_login($isJsonRequest);

    $role = current_role();

    if ($role !== null && role_can($role, $capability)) {
        return;
    }

    if ($isJsonRequest) {
        json_response(array(
            'ok'    => false,
            'code'  => 'forbidden',
            'error' => 'Your account does not have permission to perform this action.',
        ), 403);
    }

    // FR-7.4 / FR-7.5: the wrong role receives 403 and the access-denied page.
    http_response_code(403);
    require_once dirname(__DIR__) . '/controllers/ErrorController.php';
    (new ErrorController())->accessDenied($role);
    exit;
}

/**
 * Require a specific role. A convenience wrapper over
 * require_capability() for the two roles in v1.0.
 *
 * @param string $role           'User' or 'Admin'.
 * @param bool   $isJsonRequest True for JSON endpoints.
 * @return void
 */
function require_role(string $role, bool $isJsonRequest = false): void
{
    require_login($isJsonRequest);

    if (current_role() === $role) {
        return;
    }

    if ($isJsonRequest) {
        json_response(array(
            'ok'    => false,
            'code'  => 'forbidden',
            'error' => 'Your account does not have permission to perform this action.',
        ), 403);
    }

    http_response_code(403);
    require_once dirname(__DIR__) . '/controllers/ErrorController.php';
    (new ErrorController())->accessDenied(current_role());
    exit;
}

/**
 * The routes that stay available while an account must change its password.
 *
 * Decision D-006: the public storefront (the landing page and the product
 * catalogue) remains browsable while the forced-change flag is set, so the
 * visitor is not locked out of the shop. Everything that touches the
 * account or performs a state change stays blocked until the password has
 * been changed.
 *
 * The restriction applies to Admins as well as Users.
 *
 * @var array<int, string>
 */
const PASSWORD_CHANGE_PUBLIC_ROUTES = array(
    '/',
    '/products',
    '/product',
    '/category',
    '/catalogue',
);

/**
 * Enforce the forced-password-change rule (FR-1.9).
 *
 * When the account carries the forced-change flag, every protected page
 * other than the forced change password page and the public storefront
 * redirects to that page, so the flag genuinely blocks navigation into the
 * account area (decision D-006).
 *
 * @param string $allowedRoute The route currently being requested.
 * @return void
 */
function enforce_password_change(string $allowedRoute = 'auth/change-password'): void
{
    if (!must_change_password()) {
        return;
    }

    $current = current_request_path();

    // Already on the forced change password page: let it through.
    if ($current !== '' && str_contains($current, $allowedRoute)) {
        return;
    }

    // Decision D-006: the public storefront stays browsable.
    if (is_public_storefront_route($current)) {
        return;
    }

    // The status code is passed to header() as its third argument because a
    // Location header makes PHP default the status to 302.
    header('Location: ' . APP_URL . '/auth/change-password', true, 403);
    exit;
}

/**
 * Whether the requested path is a public storefront page.
 *
 * The check is on the path prefix, so the product catalogue and its detail
 * and category pages are all included.
 *
 * @param string $path The requested path, relative to the application root.
 * @return bool
 */
function is_public_storefront_route(string $path): bool
{
    if ($path === '') {
        return false;
    }

    foreach (PASSWORD_CHANGE_PUBLIC_ROUTES as $route) {
        if ($path === $route) {
            return true;
        }

        // A route such as '/product' also covers '/product/12'.
        if ($route !== '/' && str_starts_with($path, $route . '/')) {
            return true;
        }
    }

    return false;
}
