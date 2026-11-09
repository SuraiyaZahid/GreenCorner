<?php
/**
 * GreenCorner — Built-in server router
 *
 * PHP's built-in web server does not read .htaccess, so it needs a router
 * script to reproduce the rewrite rules of public/.htaccess.
 *
 * The rules are:
 *   - A request for an existing file under public/ is served directly
 *     (the CSS and the JavaScript).
 *   - Every other request goes to the front controller.
 *
 * The /uploads/ path is deliberately always routed to the front controller:
 * the uploaded product images live outside the web root, in storage/uploads,
 * and are served by UploadController as image content (SEC-6).
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicRoot = __DIR__;

// The upload path is always handled by the front controller.
if (str_starts_with($path, '/uploads/')) {
    require __DIR__ . '/index.php';
    return true;
}

// An existing file under public/ is served directly by the built-in server.
$candidate = realpath($publicRoot . $path);
if ($candidate !== false
    && is_file($candidate)
    && str_starts_with($candidate, realpath($publicRoot) . DIRECTORY_SEPARATOR)
) {
    // Tell the built-in server to serve the file itself.
    return false;
}

// Everything else goes to the front controller.
require __DIR__ . '/index.php';

return true;
