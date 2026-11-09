<?php
/**
 * GreenCorner — Product Image Handling
 *
 * Implements FR-2.12 and FR-6.4:
 *   Each product has an image. The system stores the original file and a
 *   generated thumbnail, using the thumbnail on listing cards and the original
 *   on the detail page.
 *
 * Validation is server-side on BOTH the declared file type and the actual
 * image content, so a text file renamed to ".jpg" is rejected (SRS §15.4,
 * SEC-3 … SEC-6):
 *
 *   - Allowed formats: JPG, PNG, WebP.
 *   - Maximum size:    2 MB.
 *   - The file must decode as a real image.
 *
 * Persistence is atomic: if the thumbnail cannot be generated, or the
 * database write fails, the files already written are deleted again and the
 * product's previous image continues to work (FR-6.4).
 */

require_once __DIR__ . '/../config/bootstrap.php';

/**
 * The width and height of the generated thumbnail, in pixels.
 *
 * The thumbnail is square and the original is cropped to the same aspect
 * ratio, so every listing card has a predictable shape.
 */
const THUMBNAIL_SIZE = 400;

/**
 * The largest pixel dimension kept for the stored original.
 *
 * A very large original is scaled down before it is stored, so the store
 * does not fill up with very large files. The result is still the
 * "original" for the detail page: it is the full image, not a thumbnail.
 */
const MAX_ORIGINAL_DIMENSION = 1600;

/**
 * The file extensions that may be stored, mapped to their MIME type.
 *
 * @var array<string, string>
 */
const IMAGE_EXTENSION_MIME = array(
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
);

/**
 * The result of an image upload attempt.
 *
 * @var array{ok: bool, error: string|null, original: string|null, thumbnail: string|null, mime: string|null}
 */
function image_result(bool $ok, ?string $error = null, ?string $original = null, ?string $thumbnail = null, ?string $mime = null): array
{
    return array(
        'ok'        => $ok,
        'error'     => $error,
        'original'  => $original,
        'thumbnail' => $thumbnail,
        'mime'      => $mime,
    );
}

/**
 * Validate an uploaded file as a product image.
 *
 * The checks are, in order:
 *   1. The upload succeeded (no PHP upload error).
 *   2. The size is within the limit.
 *   3. The extension is on the allow-list.
 *   4. The declared MIME type is on the allow-list.
 *   5. The actual file content decodes as a real image of an allowed type.
 *
 * The last check is what stops a ".txt" file renamed to ".jpg" (FR-2.12
 * acceptance criterion 2).
 *
 * @param array<string, mixed> $file One entry of the $_FILES array.
 * @return array{ok: bool, error: string|null, mime: string|null}
 */
function validate_image_upload(array $file): array
{
    // A missing file is not an upload error when the field was left empty;
    // the caller decides whether the image was mandatory.
    $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_NO_FILE) {
        return image_result(false, 'Please choose an image file.', null, null, null);
    }

    if ($error !== UPLOAD_ERR_OK) {
        return image_result(false, image_upload_error_message($error), null, null, null);
    }

    // The size check comes before anything else, so an oversized file is
    // never read into memory.
    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($size <= 0) {
        return image_result(false, 'The image file is empty.', null, null, null);
    }

    if ($size > MAX_UPLOAD_BYTES) {
        return image_result(false, 'The image is larger than the 2 MB limit.', null, null, null);
    }

    $temporaryPath = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        return image_result(false, 'The image upload could not be read.', null, null, null);
    }

    // The declared extension must be on the allow-list.
    $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!array_key_exists($extension, IMAGE_EXTENSION_MIME)) {
        return image_result(false, 'Only JPG, PNG and WebP images are accepted.', null, null, null);
    }

    // The declared MIME type must be on the allow-list.
    $declaredMime = isset($file['type']) ? strtolower((string) $file['type']) : '';
    if (!in_array($declaredMime, ALLOWED_IMAGE_MIME, true)) {
        return image_result(false, 'Only JPG, PNG and WebP images are accepted.', null, null, null);
    }

    // The ACTUAL content must decode as a real image (SEC-5). This is the
    // check that a renamed text file fails.
    $actualMime = detect_image_mime($temporaryPath);
    if ($actualMime === null || !in_array($actualMime, ALLOWED_IMAGE_MIME, true)) {
        return image_result(false, 'The file is not a valid JPG, PNG or WebP image.', null, null, null);
    }

    // The decoded type and the extension must agree, so a WebP file named
    // ".jpg" is refused rather than stored under the wrong extension.
    if (IMAGE_EXTENSION_MIME[$extension] !== $actualMime) {
        return image_result(false, 'The image type does not match its file extension.', null, null, null);
    }

    return image_result(true, null, null, null, $actualMime);
}

/**
 * Detect the real image type of a file from its content.
 *
 * The file is read with getimagesize(), which inspects the file's own
 * header rather than trusting its name or its declared type.
 *
 * @param string $path
 * @return string|null The MIME type, or null when the file is not an image.
 */
function detect_image_mime(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }

    $size = @getimagesize($path);
    if ($size === false) {
        return null;
    }

    $type = $size['mime'] ?? '';

    return is_string($type) && $type !== '' ? $type : null;
}

/**
 * A readable message for a PHP upload error code.
 *
 * @param int $error
 * @return string
 */
function image_upload_error_message(int $error): string
{
    switch ($error) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'The image is larger than the 2 MB limit.';

        case UPLOAD_ERR_PARTIAL:
            return 'The image was only partially uploaded. Please try again.';

        case UPLOAD_ERR_NO_TMP_DIR:
            return 'The server has no temporary folder for the upload.';

        case UPLOAD_ERR_CANT_WRITE:
            return 'The server could not write the uploaded image to disk.';

        case UPLOAD_ERR_EXTENSION:
            return 'The upload was stopped by a server extension.';

        default:
            return 'The image upload failed. Please try again.';
    }
}

/**
 * Store an uploaded image and its generated thumbnail.
 *
 * The two files are written into the upload directory. If anything goes
 * wrong, both files are removed again, so a failed upload never leaves
 * half-written files behind (FR-6.4).
 *
 * @param array<string, mixed> $file One entry of the $_FILES array.
 * @return array{ok: bool, error: string|null, original: string|null, thumbnail: string|null, mime: string|null}
 */
function store_image_upload(array $file): array
{
    $validation = validate_image_upload($file);
    if (!$validation['ok']) {
        return $validation;
    }

    $mime = (string) $validation['mime'];

    // A unique name avoids collisions between two products with the same
    // name. The extension is taken from the detected type, not the client's
    // file name.
    $extension = image_extension_for_mime($mime);
    $baseName = 'product-' . bin2hex(random_bytes(12));

    $originalName  = $baseName . '.' . $extension;
    $thumbnailName = $baseName . '-thumb.' . $extension;

    $directory = ensure_upload_dir();

    $originalPath  = $directory . DIRECTORY_SEPARATOR . $originalName;
    $thumbnailPath = $directory . DIRECTORY_SEPARATOR . $thumbnailName;

    $sourcePath = (string) ($file['tmp_name'] ?? '');

    // 1. Write the original, scaled down only if it is unreasonably large.
    if (!write_original_image($sourcePath, $originalPath, $mime)) {
        @unlink($originalPath);
        return image_result(false, 'The image could not be saved. Please try again.');
    }

    // 2. Generate the thumbnail from the original.
    if (!write_thumbnail_image($originalPath, $thumbnailPath, $mime)) {
        // A failure here removes both files: the product's previous image
        // must still work (FR-6.4).
        @unlink($originalPath);
        @unlink($thumbnailPath);
        return image_result(false, 'The thumbnail could not be generated. Please try again.');
    }

    return image_result(true, null, $originalName, $thumbnailName, $mime);
}

/**
 * The file extension used for a detected image MIME type.
 *
 * @param string $mime
 * @return string
 */
function image_extension_for_mime(string $mime): string
{
    switch ($mime) {
        case 'image/png':
            return 'png';

        case 'image/webp':
            return 'webp';

        default:
            return 'jpg';
    }
}

/**
 * Make sure the upload directory exists and is not web-executable.
 *
 * @return string The upload directory path.
 */
function ensure_upload_dir(): string
{
    $directory = UPLOAD_DIR;

    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }

    // The upload directory is outside the web root in this installation, but
    // a deployment may publish it. A small .htaccess makes sure that a file
    // placed there is never executed as code (SEC-6).
    $guard = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($guard)) {
        $contents = "# GreenCorner — uploaded product images.\n"
            . "# These files are images and must never be executed as code (SEC-6).\n"
            . "php_flag engine off\n"
            . "<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|asp|aspx|sh)$\">\n"
            . "    Require all denied\n"
            . "</FilesMatch>\n";
        @file_put_contents($guard, $contents);
    }

    return $directory;
}

/**
 * Copy the uploaded image to the upload directory, scaling it down only when
 * it exceeds MAX_ORIGINAL_DIMENSION.
 *
 * @param string $sourcePath
 * @param string $destinationPath
 * @param string $mime
 * @return bool
 */
function write_original_image(string $sourcePath, string $destinationPath, string $mime): bool
{
    $size = @getimagesize($sourcePath);
    if ($size === false) {
        return false;
    }

    $width  = (int) $size[0];
    $height = (int) $size[1];

    // Small enough to store as it is.
    if ($width <= MAX_ORIGINAL_DIMENSION && $height <= MAX_ORIGINAL_DIMENSION) {
        return @copy($sourcePath, $destinationPath);
    }

    $source = load_image($sourcePath, $mime);
    if ($source === null) {
        return false;
    }

    $scale = MAX_ORIGINAL_DIMENSION / max($width, $height);
    $newWidth  = max(1, (int) round($width * $scale));
    $newHeight = max(1, (int) round($height * $scale));

    $canvas = imagecreatetruecolor($newWidth, $newHeight);
    if ($canvas === false) {
        imagedestroy($source);
        return false;
    }

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $transparent);
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    $written = save_image($canvas, $destinationPath, $mime);

    imagedestroy($canvas);
    imagedestroy($source);

    return $written;
}

/**
 * Generate the square thumbnail from the stored original.
 *
 * The original is cropped to a square and scaled to THUMBNAIL_SIZE, so every
 * listing card has the same shape.
 *
 * @param string $originalPath
 * @param string $thumbnailPath
 * @param string $mime
 * @return bool
 */
function write_thumbnail_image(string $originalPath, string $thumbnailPath, string $mime): bool
{
    $size = @getimagesize($originalPath);
    if ($size === false) {
        return false;
    }

    $source = load_image($originalPath, $mime);
    if ($source === null) {
        return false;
    }

    $width  = (int) $size[0];
    $height = (int) $size[1];

    // Crop to the largest centred square.
    $side = min($width, $height);
    $sourceX = intdiv($width - $side, 2);
    $sourceY = intdiv($height - $side, 2);

    $canvas = imagecreatetruecolor(THUMBNAIL_SIZE, THUMBNAIL_SIZE);
    if ($canvas === false) {
        imagedestroy($source);
        return false;
    }

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, THUMBNAIL_SIZE, THUMBNAIL_SIZE, $transparent);
    imagecopyresampled(
        $canvas,
        $source,
        0, 0,
        $sourceX, $sourceY,
        THUMBNAIL_SIZE, THUMBNAIL_SIZE,
        $side, $side
    );

    $written = save_image($canvas, $thumbnailPath, $mime);

    imagedestroy($canvas);
    imagedestroy($source);

    return $written;
}

/**
 * Load an image file into a GD resource.
 *
 * @param string $path
 * @param string $mime
 * @return \GdImage|null
 */
function load_image(string $path, string $mime)
{
    $image = null;

    switch ($mime) {
        case 'image/jpeg':
            $image = @imagecreatefromjpeg($path);
            break;

        case 'image/png':
            $image = @imagecreatefrompng($path);
            break;

        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $image = @imagecreatefromwebp($path);
            }
            break;
    }

    return $image === false ? null : $image;
}

/**
 * Write a GD image to disk in the given format.
 *
 * @param \GdImage $image
 * @param string   $path
 * @param string   $mime
 * @return bool
 */
function save_image($image, string $path, string $mime): bool
{
    switch ($mime) {
        case 'image/png':
            return @imagepng($image, $path, 6);

        case 'image/webp':
            if (function_exists('imagewebp')) {
                return @imagewebp($image, $path, 82);
            }
            return false;

        default:
            return @imagejpeg($image, $path, 85);
    }
}

/**
 * Delete a stored original and its thumbnail.
 *
 * Used when a product's image is replaced, after the new files have been
 * stored successfully (FR-6.4, FR-6.3).
 *
 * @param string|null $originalName
 * @param string|null $thumbnailName
 * @return void
 */
function delete_stored_images(?string $originalName, ?string $thumbnailName): void
{
    foreach (array($originalName, $thumbnailName) as $name) {
        if ($name === null || $name === '') {
            continue;
        }

        // Only a plain file name is accepted, so a stored value can never
        // point outside the upload directory.
        if (basename($name) !== $name) {
            continue;
        }

        $path = UPLOAD_DIR . DIRECTORY_SEPARATOR . $name;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * The public URL of a stored image.
 *
 * @param string|null $name
 * @return string
 */
function upload_url(?string $name): string
{
    if ($name === null || $name === '') {
        return '';
    }

    return UPLOAD_URL . '/' . rawurlencode(basename($name));
}
