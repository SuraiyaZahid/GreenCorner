<?php
/**
 * GreenCorner — Uploaded Image Controller
 *
 * Serves the uploaded product images (FR-2.12, SEC-6).
 *
 * The files live in storage/uploads, which is outside the web root, so a
 * request for an image arrives here and is answered by this controller
 * rather than by the web server. That has two benefits:
 *
 *   - An uploaded file can never be executed as code, whatever it contains
 *     (SEC-6). The response is always sent with an image content type and
 *     the Content-Disposition header asks the browser to treat it as a
 *     download rather than a script.
 *   - The response can carry a conservative cache policy, because a product
 *     image never changes once it has been published.
 *
 * Only files with a known image extension and a known image MIME type are
 * served. Anything else is refused with 404.
 */

require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/image.php';

class UploadController
{
    /**
     * The image types that may be served.
     *
     * @var array<string, string>
     */
    private const SERVABLE_TYPES = array(
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    );

    /**
     * Serve an uploaded image.
     *
     * @param array<string, mixed> $parameters The URL parameters. 'name'
     *                                         holds the requested file name.
     * @return void
     */
    public function show(array $parameters = array()): void
    {
        $requested = isset($parameters['name']) ? (string) $parameters['name'] : '';

        // Only a plain file name is accepted. This stops a request such as
        // "../../config/config.local.php" from reaching outside the upload
        // directory (SEC-7).
        $name = basename($requested);

        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!array_key_exists($extension, self::SERVABLE_TYPES)) {
            not_found('The image you asked for could not be found.');
        }

        $path = UPLOAD_DIR . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            not_found('The image you asked for could not be found.');
        }

        // The file's real type is checked as well, so a file that does not
        // actually hold an image is never served as one.
        $actualMime = detect_image_mime($path);
        if ($actualMime === null || $actualMime !== self::SERVABLE_TYPES[$extension]) {
            not_found('The image you asked for could not be found.');
        }

        $size = @filesize($path);
        if ($size === false || $size <= 0) {
            not_found('The image you asked for could not be found.');
        }

        header('Content-Type: ' . $actualMime);
        header('Content-Length: ' . $size);
        header('Content-Disposition: inline; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=604800');

        readfile($path);
        exit;
    }
}
