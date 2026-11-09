<?php
/**
 * GreenCorner — General Helper Functions
 *
 * Small, readable utilities shared across the application:
 *   - output escaping (XSS prevention, NFR-S-03 / SEC-2)
 *   - JSON responses for the AJAX endpoints (SRS §9.4)
 *   - redirect and flash-message helpers
 *   - input validation and trimming
 *   - currency formatting (BR-1)
 */

/**
 * Whether the shared layout should fail while it renders.
 *
 * This exists so that the LAST line of defence of the error handling — the
 * static fallback that public/index.php emits when the branded 500 page
 * cannot be rendered at all — can be tested over HTTP. Without a way to
 * break the layout itself, that fallback would be unreachable code that no
 * test ever exercises.
 *
 * The flag is set only by the Phase 6 fault-injection controller, which
 * refuses to act in production and for any account that is not an Admin
 * (decision D-018). It is exactly the same shape as
 * simulate_database_outage() in helpers/db.php.
 *
 * @var bool
 */
$GLOBALS['gc_layout_render_fault'] = false;

/**
 * Make the shared layout fail while it renders.
 *
 * The layout is what every page includes, so making it fail makes the
 * rendering of the 500 page fail as well — which is the situation the static
 * fallback in public/index.php exists for.
 *
 * @return void
 */
function simulate_layout_render_fault(): void
{
    $GLOBALS['gc_layout_render_fault'] = true;
}

/**
 * Whether the shared layout should fail while it renders.
 *
 * @return bool
 */
function layout_render_fault_active(): bool
{
    return !empty($GLOBALS['gc_layout_render_fault']);
}

/**
 * Escape a value for safe output in HTML.
 *
 * Applied to every piece of user-supplied data before it is rendered
 * (NFR-S-03, SEC-2). NULL and booleans are handled safely.
 *
 * @param mixed $value
 * @return string
 */
function e($value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Send a JSON response and stop.
 *
 * The shape is consistent across every endpoint: an `ok` flag, a
 * machine-readable `code`, a human-readable `message`, and any `data` the
 * caller needs (SRS §9.4, ER-22).
 *
 * @param array<string, mixed> $payload
 * @param int                  $status HTTP status code.
 * @return void
 */
function json_response(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Redirect to a path within the application.
 *
 * @param string $path A path relative to APP_URL, e.g. '/auth/login'.
 * @return void
 */
function redirect(string $path): void
{
    if (!headers_sent()) {
        // The status code is passed to header() as its third argument so that
        // the redirect status is never lost to PHP's default behaviour.
        header('Location: ' . APP_URL . $path, true, 302);
    }
    exit;
}

/**
 * Send a 404 response for an unknown URL (FR-2.13).
 *
 * @param string $message Optional wording to show.
 * @return void
 */
function not_found(string $message = 'We could not find the page you were looking for.'): void
{
    http_response_code(404);
    require_once __DIR__ . '/../controllers/ErrorController.php';
    (new ErrorController())->notFound($message);
    exit;
}

/**
 * Store a one-time message to show on the next page (flash message).
 *
 * @param string $type    'success' or 'error'.
 * @param string $message
 * @return void
 */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = array('type' => $type, 'message' => $message);
}

/**
 * Read and clear the flash message.
 *
 * @return array{type: string, message: string}|null
 */
function get_flash(): ?array
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

/**
 * Read a POST field as a trimmed string.
 *
 * @param string $key
 * @param string $default
 * @return string
 */
function post_string(string $key, string $default = ''): string
{
    if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
        return $default;
    }

    return trim((string) $_POST[$key]);
}

/**
 * Read a POST field as an integer.
 *
 * @param string $key
 * @param int    $default
 * @return int
 */
function post_int(string $key, int $default = 0): int
{
    if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
        return $default;
    }

    $value = trim((string) $_POST[$key]);

    return is_numeric($value) ? (int) $value : $default;
}

/**
 * Read a submitted field as a trimmed string, from either a form post or a
 * JSON request body.
 *
 * PHP does not populate `$_POST` when the request body is JSON, so the
 * background endpoints read their fields through this helper rather than
 * through `post_string()`. The form post is checked first, so a form
 * submission behaves exactly as before.
 *
 * @param string $key
 * @param string $default
 * @return string
 */
function request_string(string $key, string $default = ''): string
{
    if (isset($_POST[$key]) && is_scalar($_POST[$key])) {
        return trim((string) $_POST[$key]);
    }

    $body = json_body();

    if (isset($body[$key]) && is_scalar($body[$key])) {
        return trim((string) $body[$key]);
    }

    return $default;
}

/**
 * Read a submitted field as an integer, from either a form post or a JSON
 * request body.
 *
 * @param string $key
 * @param int    $default
 * @return int
 */
function request_int(string $key, int $default = 0): int
{
    $value = request_string($key, '');

    return is_numeric($value) ? (int) $value : $default;
}

/**
 * Read a GET field as a trimmed string.
 *
 * @param string $key
 * @param string $default
 * @return string
 */
function get_string(string $key, string $default = ''): string
{
    if (!isset($_GET[$key]) || !is_scalar($_GET[$key])) {
        return $default;
    }

    return trim((string) $_GET[$key]);
}

/**
 * Read the JSON body of the request as an array.
 *
 * @return array<string, mixed>
 */
function json_body(): array
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        $cached = array();
        return $cached;
    }

    $decoded = json_decode($raw, true);
    $cached = is_array($decoded) ? $decoded : array();

    return $cached;
}

/**
 * Format a monetary amount for display.
 *
 * Money is always stored and shown with two decimal places (BR-1).
 *
 * @param mixed $amount
 * @return string
 */
function format_money($amount): string
{
    return CURRENCY_SYMBOL . number_format((float) $amount, 2, '.', ',');
}

/**
 * The URL of the current request path, without the query string.
 */
function current_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $position = strpos($uri, '?');

    return $position === false ? $uri : substr($uri, 0, $position);
}

/**
 * The query string of the current request.
 */
function current_query_string(): string
{
    return $_SERVER['QUERY_STRING'] ?? '';
}

/**
 * The size of PHP's `post_max_size` directive, in bytes.
 *
 * The directive is written with a unit suffix, such as "8M" or "1G", so the
 * value is parsed rather than cast: casting "8M" to an integer would give
 * 8 and the limit would be wrong by a factor of a million.
 *
 * @return int The limit in bytes, or 0 when the directive is not set.
 */
function post_max_size_bytes(): int
{
    $configured = (string) ini_get('post_max_size');

    if (!preg_match('/^\s*(\d+)\s*([KMG]?)\s*$/i', $configured, $matches)) {
        return 0;
    }

    $multiplier = 1;

    switch (strtoupper($matches[2])) {
        case 'G':
            $multiplier = 1024 * 1024 * 1024;
            break;
        case 'M':
            $multiplier = 1024 * 1024;
            break;
        case 'K':
            $multiplier = 1024;
            break;
    }

    return (int) $matches[1] * $multiplier;
}

/**
 * Whether PHP discarded the request body because it exceeded post_max_size.
 *
 * When the body is larger than the `post_max_size` directive, PHP empties
 * `$_POST` and `$_FILES` without raising an error the application can see.
 * The request then looks like an empty form, which would be reported to the
 * visitor as missing fields rather than as an oversized upload.
 *
 * The `Content-Length` header survives the discard, so it is compared with
 * the configured limit. When the limit cannot be determined, the second
 * check applies: a multipart POST that reached the application with no
 * fields and no files at all is an empty body, which is what a discarded
 * body looks like.
 *
 * @return bool
 */
function request_body_was_discarded(): bool
{
    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? '';

    if (is_scalar($contentLength) && ctype_digit((string) $contentLength)) {
        $limit = post_max_size_bytes();

        if ($limit > 0 && (int) $contentLength > $limit) {
            return true;
        }
    }

    // The fallback: a multipart POST with a body that PHP threw away
    // arrives with neither fields nor files. A legitimate multipart POST
    // always carries at least the fields of its form.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return false;
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (!is_string($contentType) || stripos($contentType, 'multipart/form-data') === false) {
        return false;
    }

    return $_POST === array() && $_FILES === array();
}

/**
 * The requested path, relative to the application root, without the query
 * string.
 *
 * The front controller uses this to route the request, and the
 * authorisation helpers use it to decide whether the requested page is a
 * public storefront page. Both therefore agree on exactly one path.
 *
 * The application may live in a subdirectory as well as at the domain root.
 * The prefix to strip is taken from the directory the front controller was
 * reached through, which is the DOCUMENT_ROOT of the public/ directory in a
 * normal deployment.
 *
 * @return string e.g. '/', '/auth/login', '/product/12'.
 */
function current_request_path(): string
{
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

    // The directory the application is served from. DOCUMENT_ROOT points at
    // the public/ directory, so its parent is the application's own root.
    $scriptDir = '';

    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (is_string($documentRoot) && $documentRoot !== '') {
        $scriptDir = rtrim(str_replace('\\', '/', dirname(rtrim($documentRoot, '/\\'))), '/');
    }

    if ($scriptDir === '' || $scriptDir === '/') {
        $scriptDir = '';
    }

    // Remove the application directory prefix so the app can live in a
    // subdirectory as well as at the domain root.
    if ($scriptDir !== '' && str_starts_with($path, $scriptDir)) {
        $path = substr($path, strlen($scriptDir));
    }

    $path = '/' . ltrim($path, '/');

    if ($path !== '/') {
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }
    }

    return $path;
}
