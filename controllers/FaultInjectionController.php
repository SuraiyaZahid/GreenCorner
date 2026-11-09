<?php
/**
 * GreenCorner — Fault Injection Controller (Phase 6 test support)
 *
 * Exposes deliberate faults so that the error-handling paths can be tested
 * over HTTP, the way every other test in this project is driven.
 *
 * The 500 page (P-22) and the 403 page (P-21) are only reachable when
 * something goes wrong, and AC-44 requires an unexpected error to be
 * answered with HTTP 500 and a generic message. Without a way to provoke an
 * error, that requirement cannot be verified by a test at all.
 *
 * SAFEGUARDS. This controller refuses to act unless BOTH of the following
 * hold:
 *
 *   1. APP_ENV is not 'production'. The route is therefore unreachable in a
 *      production installation (decision D-018). This mirrors the seed
 *      script, which refuses to run in production (SC-16).
 *   2. The caller is authenticated and holds the Admin role. A fault is a
 *      development tool, not a public one.
 *
 * If either safeguard fails, the request is answered with HTTP 404 and the
 * fault is not injected. The route then behaves exactly as if it did not
 * exist, which is what a production installation must do with it.
 *
 * The faults inject an exception only. They never write to the database and
 * never touch a row.
 */

require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/authorization.php';
require_once __DIR__ . '/../helpers/db.php';
require_once __DIR__ . '/../helpers/session_handler.php';

class FaultInjectionController
{
    /**
     * Whether fault injection is permitted in this environment.
     *
     * @return bool
     */
    private static function allowed(): bool
    {
        // Never in production (SC-16 uses the same rule for the seed).
        if (defined('APP_ENV') && APP_ENV === 'production') {
            return false;
        }

        // Never for a visitor or for a non-Admin account.
        return current_role() === ROLE_ADMIN;
    }

    /**
     * Provoke an unexpected error, which the front controller answers with
     * the generic 500 page (P-22, §15.3, NFR-R-05).
     *
     * GET /_fault/error
     *
     * @param array<string, mixed> $parameters
     * @return void
     */
    public function error(array $parameters = array()): void
    {
        if (!self::allowed()) {
            not_found();
            return;
        }

        throw new RuntimeException('Injected fault for the Phase 6 test suite.');
    }

    /**
     * Simulate a database outage and then provoke an unexpected error.
     *
     * The point of this fault is the SECOND exception: the original failure
     * is a database failure, and the rendering of the 500 page must also try
     * to reach the database. This route makes the database genuinely
     * unreachable for the remainder of the request, so the header's
     * current_user() lookup fails exactly as it would during a real outage.
     *
     * GET /_fault/database-outage
     *
     * @param array<string, mixed> $parameters
     * @return void
     */
    public function databaseOutage(array $parameters = array()): void
    {
        if (!self::allowed()) {
            not_found();
            return;
        }

        // Close the database handle so that every later call in this request
        // fails to connect, exactly as it would if the server were down.
        simulate_database_outage();

        // Now provoke the unexpected error. The front controller catches it
        // and renders the 500 page, which includes the shared layout, which
        // tries to read the account from the now-unreachable database.
        throw new RuntimeException('Injected database outage for the Phase 6 test suite.');
    }

    /**
     * Provoke an error whose 500 page ALSO fails to render.
     *
     * This is the last line of defence of the error handling. The branded
     * 500 page includes the shared layout, and the layout is what fails
     * here — so the front controller's own catch block fails in turn, and
     * the static, self-contained fallback in public/index.php is what
     * reaches the browser (decision D-018).
     *
     * The point is that the client must still receive a complete HTML
     * document with HTTP 500, and must still receive no internal detail:
     * no stack trace, no file path, no SQL fragment.
     *
     * GET /_fault/render-failure
     *
     * @param array<string, mixed> $parameters
     * @return void
     */
    public function renderFailure(array $parameters = array()): void
    {
        if (!self::allowed()) {
            not_found();
            return;
        }

        // The shared layout now fails on every page it renders, so the 500
        // page cannot be completed either.
        simulate_layout_render_fault();

        throw new RuntimeException('Injected render failure for the Phase 6 test suite.');
    }
}
