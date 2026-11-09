<?php
/**
 * GreenCorner — Front Controller
 *
 * The single entry point for every request. All requests are routed to a
 * controller action, which performs the authorisation check before any state
 * change.
 *
 * Architecture (SRS §4.1):
 *   Browser -> public/index.php (router) -> controllers -> models -> MySQLi
 *
 * Every page is served through this file, so the .htaccess file in this
 * directory rewrites all requests to it.
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Errors are never printed into the response body: a stray warning would
// send the headers early and corrupt the status code and the redirect that
// the browser should receive. In development the details are written to the
// error log instead, so they can still be diagnosed.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../helpers/session_handler.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/authorization.php';
require_once __DIR__ . '/../helpers/security_headers.php';
require_once __DIR__ . '/../controllers/ErrorController.php';

// The security headers are sent before anything can produce output, so that
// every response carries them — the normal pages, the 404, the 403, the 500
// and the JSON endpoints alike (decision D-018).
send_security_headers();

// Start the database-backed session before anything else touches it.
start_secure_session();

// Route tables. Each entry maps a method and path to a controller action.
// The authorisation requirement for each route lives in the controller, via
// the central capability matrix (FR-7.1).

/** Public routes — no authentication required. */
$getRoutes = array(
    '/'                    => array('HomeController', 'index'),
    '/auth/register'       => array('AuthController', 'showRegister'),
    '/auth/login'          => array('AuthController', 'showLogin'),
    '/auth/change-password' => array('AuthController', 'showChangePassword'),
    '/dashboard'           => array('HomeController', 'userDashboard'),

    // The public storefront (FR-2.1, FR-2.7). A visitor, a User and an
    // Admin may all browse the catalogue (SRS §14.3).
    '/products'            => array('CatalogController', 'listing'),

    // The Admin panel (P-14, P-15, P-16).
    '/admin/dashboard'     => array('AdminController', 'dashboard'),
    '/admin/products'      => array('AdminController', 'products'),
    '/admin/products/new'  => array('AdminController', 'createProductForm'),
    '/admin/categories'    => array('AdminController', 'categories'),

    // The Admin order management (P-17, P-18).
    '/admin/orders'        => array('AdminOrderController', 'index'),

    // The Admin user management (P-19, P-20).
    '/admin/users'         => array('AdminUserController', 'index'),

    // The Profile page (P-13). It is a User-only resource: an Admin is
    // refused with HTTP 403 (FR-7.4, the RTM page row for P-13).
    '/profile'             => array('ProfileController', 'show'),

    // Shopping (P-08, P-09, P-10, P-11, P-12). These are User-only
    // resources: an Admin may browse the storefront but may not shop
    // (FR-7.5).
    '/cart'                => array('CartController', 'index'),
    '/checkout/payment'    => array('CheckoutController', 'payment'),
    '/checkout/confirmation' => array('CheckoutController', 'confirmation'),
    '/orders'              => array('OrderController', 'index'),

    // Phase 6 test support: deliberate faults, so that the error-handling
    // paths can be verified over HTTP. The controller refuses to act in
    // production and for any account that is not an Admin, so the route
    // behaves as a 404 everywhere else (decision D-018).
    '/_fault/error'        => array('FaultInjectionController', 'error'),
    '/_fault/database-outage' => array('FaultInjectionController', 'databaseOutage'),
    '/_fault/render-failure' => array('FaultInjectionController', 'renderFailure'),
);

$postRoutes = array(
    '/auth/register'       => array('AuthController', 'register'),
    '/auth/login'          => array('AuthController', 'login'),
    '/auth/logout'         => array('AuthController', 'logout'),
    '/auth/change-password' => array('AuthController', 'changePassword'),

    // The Profile page (P-13). Both forms change the signed-in account
    // alone: no identifier is read from the request.
    '/profile/name'         => array('ProfileController', 'updateName'),
    '/profile/password'     => array('ProfileController', 'changePassword'),

    // The Admin panel (P-15, P-16).
    '/admin/products'      => array('AdminController', 'createProduct'),
    '/admin/categories'    => array('AdminController', 'createCategory'),

    // Shopping (P-08, P-09). The background cart endpoints (FR-3.1,
    // FR-3.5, FR-3.6) answer with JSON.
    '/cart/add'            => array('CartController', 'add'),
    '/cart/update'        => array('CartController', 'updateQuantity'),
    '/cart/remove'        => array('CartController', 'remove'),
    '/cart/clear'         => array('CartController', 'clear'),
    '/cart/summary'       => array('CartController', 'summary'),
    '/checkout/place-order' => array('CheckoutController', 'placeOrder'),
    '/checkout/confirm-price' => array('CheckoutController', 'confirmPrice'),
);

/**
 * Routes whose path carries a parameter, for example '/products/12'.
 *
 * Each entry is a regular expression that matches the whole path, with the
 * named groups the controller needs.
 */
$getRoutesWithParameters = array(
    // FR-2.7: the product detail page.
    '#^/products/(?P<id>[0-9]+)$#' => array('CatalogController', 'detail'),

    // FR-2.12: the uploaded product images.
    '#^/uploads/(?P<name>[^/]+)$#' => array('UploadController', 'show'),

    // FR-6.3: the Admin product edit form.
    '#^/admin/products/(?P<id>[0-9]+)/edit$#' => array('AdminController', 'editProductForm'),

    // FR-5.2: the customer order detail page (P-12).
    '#^/orders/(?P<id>[0-9]+)$#' => array('OrderController', 'show'),

    // FR-6.8: the Admin order detail page (P-18).
    '#^/admin/orders/(?P<id>[0-9]+)$#' => array('AdminOrderController', 'show'),

    // FR-6.10: the Admin user detail page (P-20).
    '#^/admin/users/(?P<id>[0-9]+)$#' => array('AdminUserController', 'show'),
);

/**
 * The POST routes whose path carries a parameter.
 *
 * These are the Admin state-changing routes that act on one record.
 */
$postRoutesWithParameters = array(
    // FR-6.3: save a product edit.
    '#^/admin/products/(?P<id>[0-9]+)/update$#' => array('AdminController', 'updateProduct'),

    // FR-6.5: soft-delete a product.
    '#^/admin/products/(?P<id>[0-9]+)/delete$#' => array('AdminController', 'deleteProduct'),

    // FR-6.5: restore a soft-deleted product.
    '#^/admin/products/(?P<id>[0-9]+)/restore$#' => array('AdminController', 'restoreProduct'),

    // FR-6.6: rename a category.
    '#^/admin/categories/(?P<id>[0-9]+)/update$#' => array('AdminController', 'updateCategory'),

    // FR-6.6: remove a category.
    '#^/admin/categories/(?P<id>[0-9]+)/delete$#' => array('AdminController', 'deleteCategory'),

    // FR-6.8: update the fulfilment status of an order.
    '#^/admin/orders/(?P<id>[0-9]+)/status$#' => array('AdminOrderController', 'updateStatus'),

    // FR-6.9: confirm the cash collection of a COD order.
    '#^/admin/orders/(?P<id>[0-9]+)/confirm-cod$#' => array('AdminOrderController', 'confirmCod'),

    // FR-6.10: approve a bKash or Nagad payment.
    '#^/admin/orders/(?P<id>[0-9]+)/verify-payment$#' => array('AdminOrderController', 'verifyWalletPayment'),

    // FR-6.10: reject a bKash or Nagad payment.
    '#^/admin/orders/(?P<id>[0-9]+)/reject-payment$#' => array('AdminOrderController', 'rejectWalletPayment'),

    // FR-4.14: mark a verified wallet order as paid but unfulfillable and
    // open a full refund.
    '#^/admin/orders/(?P<id>[0-9]+)/refund-unfulfillable$#' => array('AdminOrderController', 'refundUnfulfillable'),

    // FR-4.15: confirm that a refund was actually returned.
    '#^/admin/orders/(?P<id>[0-9]+)/confirm-refund$#' => array('AdminOrderController', 'confirmRefund'),

    // FR-4.15: record that a refund attempt failed.
    '#^/admin/orders/(?P<id>[0-9]+)/fail-refund$#' => array('AdminOrderController', 'failRefund'),

    // FR-6.10: set a temporary password on an account.
    '#^/admin/users/(?P<id>[0-9]+)/reset-password$#' => array('AdminUserController', 'resetPassword'),
);

// Work out the requested path relative to the application root. The same
// helper is used by the authorisation layer, so routing and access control
// always agree on what the path is.
$path = current_request_path();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$isJsonRequest = (($_SERVER['HTTP_ACCEPT'] ?? '') !== ''
    && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

/**
 * Load a controller class on demand.
 */
function load_controller(string $class): object
{
    $file = __DIR__ . '/../controllers/' . $class . '.php';
    if (!is_file($file)) {
        throw new RuntimeException('Controller not found: ' . $class);
    }
    require_once $file;

    return new $class();
}

// FR-7.3: the account's current role and status are re-read from the
// database on every request, so this call happens for all routes.
//
// The lookup is defensive: if the database is unreachable the request must
// still reach the router and be able to render a page (or the 500 page)
// rather than dying before the try block below (decision D-018). The failure
// is re-logged server-side and never rendered (NFR-R-05, D-011).
$currentUser = safe_current_user();

// FR-1.9: a forced password change blocks navigation to every other
// protected page.
enforce_password_change('auth/change-password');

try {
    // The parameters captured from the URL, if the route has any.
    $routeParameters = array();

    if ($method === 'POST') {
        if (isset($postRoutes[$path])) {
            [$controllerName, $action] = $postRoutes[$path];
        } else {
            // A POST route whose path carries a parameter.
            $matched = false;

            foreach ($postRoutesWithParameters as $pattern => $route) {
                if (preg_match($pattern, $path, $matches) !== 1) {
                    continue;
                }

                [$controllerName, $action] = $route;

                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $routeParameters[$key] = $value;
                    }
                }

                $matched = true;
                break;
            }

            if (!$matched) {
                // A POST to an unknown or GET-only path.
                not_found();
            }
        }
    } else {
        if (isset($getRoutes[$path])) {
            [$controllerName, $action] = $getRoutes[$path];
        } else {
            // A route whose path carries a parameter.
            $matched = false;

            foreach ($getRoutesWithParameters as $pattern => $route) {
                if (preg_match($pattern, $path, $matches) !== 1) {
                    continue;
                }

                [$controllerName, $action] = $route;

                // Only the named groups are passed on, so a controller never
                // receives a stray value.
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $routeParameters[$key] = $value;
                    }
                }

                $matched = true;
                break;
            }

            if (!$matched) {
                not_found();
            }
        }
    }

    $controller = load_controller($controllerName);
    $controller->{$action}($routeParameters);
} catch (Throwable $e) {
    // §15.3 / NFR-R-05: an unexpected error renders the generic 500 page and
    // never exposes internal detail to the client.
    http_response_code(500);

    // The 500 page includes the shared layout, which normally queries the
    // database. If the original failure WAS a database failure, rendering the
    // page can throw a second exception. The catch block that renders the 500
    // page is not covered by the try block above, so this nested guard is
    // what stops the response from being truncated (decision D-018).
    try {
        (new ErrorController())->serverError($e->getMessage());
    } catch (Throwable $inner) {
        // The branded page could not be rendered at all. Emit a static,
        // self-contained fallback: no database, no session, no include, so
        // nothing here can fail in turn. The detail of both exceptions is
        // logged server-side and is never rendered (NFR-R-05, D-011).
        error_log('[GreenCorner] 500 page failed to render: ' . $inner->getMessage());

        // The branded page may already have written part of its output
        // before it failed. That output is discarded, so the client receives
        // one complete document rather than a fragment followed by the
        // fallback. ob_get_clean() is safe to call here: it returns false
        // when no buffer is open, and the fallback is written either way.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        echo '<!DOCTYPE html>' . "\n"
            . '<html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Something went wrong &middot; GreenCorner</title>'
            . '</head><body>'
            . '<section><h1>Something went wrong</h1>'
            . '<p>Please try again. If the problem continues, contact the store.</p>'
            . '<p><a href="' . e(APP_URL) . '/">Go to the home page</a></p>'
            . '</section></body></html>';
    }
}
