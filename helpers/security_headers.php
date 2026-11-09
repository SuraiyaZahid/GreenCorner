<?php
/**
 * GreenCorner — Security Headers
 *
 * Emits the Content Security Policy and the related response headers.
 *
 * These headers are sent from ONE place, so that every response carries
 * them: the normal pages, the 404, the 403, the 500, the JSON endpoints and
 * the uploaded images. A header that is added to a single view would leave
 * every other response unprotected.
 *
 * Implements decision D-018 (see config/DECISIONS.md).
 *
 * THE CONTENT SECURITY POLICY
 *
 * The policy is strict: it contains neither 'unsafe-inline' nor
 * 'unsafe-eval'. That is possible because the application has exactly ONE
 * inline script — the small snippet in views/layouts/header.php that
 * publishes the application's base URL to the front end. Every other script
 * and every stylesheet is an external file, and the CSS references no
 * external origin.
 *
 * The one inline script is authorised by a per-request NONCE: a random value
 * that is generated fresh for every response, put in the header, and copied
 * onto the script tag. A nonce is only valid for the response that carries
 * it, so an injected script — which cannot know the nonce — is refused by
 * the browser.
 *
 * The nonce is generated before any output is produced. If it were
 * generated after the first byte had been written, the header could no
 * longer be sent and the policy would be meaningless.
 */

/**
 * The nonce for this request, or an empty string before it is generated.
 *
 * @var string
 */
$GLOBALS['gc_csp_nonce'] = '';

/**
 * Generate the per-request CSP nonce, if it has not been generated yet.
 *
 * @return string The nonce.
 */
function csp_nonce(): string
{
    if ($GLOBALS['gc_csp_nonce'] === '') {
        $GLOBALS['gc_csp_nonce'] = base64_encode(random_bytes(16));
    }

    return $GLOBALS['gc_csp_nonce'];
}

/**
 * Build the Content Security Policy for this request.
 *
 * @return string The header value.
 */
function csp_policy(): string
{
    $nonce = csp_nonce();

    $directives = array(
        "default-src 'self'",
        "script-src 'self' 'nonce-" . $nonce . "'",
        "style-src 'self'",
        "img-src 'self'",
        "connect-src 'self'",
        "font-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    );

    return implode('; ', $directives);
}

/**
 * Send the security headers for this request.
 *
 * Safe to call more than once: the headers are only sent if they have not
 * already been sent, so a second call is a no-op.
 *
 * @return void
 */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('Content-Security-Policy: ' . csp_policy());
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Permitted-Cross-Domain-Policies: none');

    // The Permissions-Policy header tells the browser that the powerful
    // features this application does not use are switched off.
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
}
