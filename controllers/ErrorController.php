<?php
/**
 * GreenCorner — Error Controller
 *
 * Renders the three system pages:
 *   P-07  404 Not Found        (FR-2.13, §15.1)
 *   P-21  403 Access Denied    (FR-7.4, FR-7.5, §15.2)
 *   P-22  500 Server Error     (§15.3, NFR-R-05)
 *
 * None of these pages discloses a stack trace, an SQL fragment, a file path
 * or any other internal detail.
 */

require_once dirname(__DIR__) . '/helpers/functions.php';
require_once dirname(__DIR__) . '/helpers/authorization.php';

class ErrorController
{
    /**
     * Render the 404 Not Found page (P-07).
     *
     * @param string $message
     * @return void
     */
    public function notFound(string $message = 'We could not find the page you were looking for.'): void
    {
        $pageTitle = 'Page not found';
        $statusCode = 404;

        require __DIR__ . '/../views/errors/404.php';
    }

    /**
     * Render the 403 Access Denied page (P-21).
     *
     * The page states only that the request was refused and which resources
     * the account's role permits. No data from the attempted resource is
     * included (SRS §15.2).
     *
     * @param string|null $role The role that was refused.
     * @return void
     */
    public function accessDenied(?string $role = null): void
    {
        $pageTitle = 'Access denied';
        $statusCode = 403;

        // Describe what the account's role may do, without revealing
        // anything about the resource that was refused.
        $allowed = array();
        if ($role !== null) {
            $matrix = capability_matrix();
            foreach ($matrix as $group => $roles) {
                if (in_array($role, $roles, true)) {
                    $allowed[] = $group;
                }
            }
        }

        require __DIR__ . '/../views/errors/403.php';
    }

    /**
     * Render the 500 Server Error page (P-22).
     *
     * @param string|null $detail Internal detail — logged, never rendered.
     * @return void
     */
    public function serverError(?string $detail = null): void
    {
        if ($detail !== null) {
            // The underlying exception is logged server-side and is never
            // rendered to the client (SRS §15.3, NFR-R-05).
            error_log('[GreenCorner] Unhandled error: ' . $detail);
        }

        $pageTitle = 'Something went wrong';
        $statusCode = 500;

        require __DIR__ . '/../views/errors/500.php';
    }
}
