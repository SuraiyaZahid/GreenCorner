<?php
/**
 * GreenCorner — Test HTTP Client
 *
 * Drives the running application over HTTP so that status codes, redirect
 * behaviour, cookies and CSRF handling are genuinely exercised — not
 * simulated inside the PHP process.
 *
 * The client keeps a cookie jar per instance, so one instance behaves like
 * one browser.
 */

class HttpClient
{
    /** @var string The base URL of the running application. */
    private $baseUrl;

    /** @var array<string, string> The cookie jar. */
    private $cookies = array();

    /** @var int The status code of the last response. */
    public $status = 0;

    /** @var string The body of the last response. */
    public $body = '';

    /** @var array<string, string> The headers of the last response. */
    public $headers = array();

    /**
     * @param string $baseUrl e.g. 'http://127.0.0.1:8000'
     */
    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Perform a request and capture the response.
     *
     * @param string $method  'GET' or 'POST'.
     * @param string $path    The path, including any query string.
     * @param array  $fields  POST fields.
     * @param bool   $follow  Whether to follow a redirect.
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function request(string $method, string $path, array $fields = array(), bool $follow = false): array
    {
        $url = $this->baseUrl . $path;
        $headerLines = array();

        $options = array(
            'method'         => $method,
            'timeout'        => 20,
            'ignore_errors'  => true, // keep 4xx/5xx bodies for inspection
            // Redirects are NOT followed automatically: the test needs to see
            // the 302 status and the Location header itself. When $follow is
            // requested, this client follows the chain itself.
            'follow_location' => 0,
            'max_redirects'   => 0,
        );

        if ($method === 'POST') {
            $options['content'] = http_build_query($fields);
            $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        if ($this->cookies !== array()) {
            $pairs = array();
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headerLines[] = 'Cookie: ' . implode('; ', $pairs);
        }

        // The header lines are passed through the 'header' option rather
        // than the 'headers' option: PHP's HTTP stream wrapper drops a
        // Cookie header given in 'headers', so the cookie would never
        // reach the application.
        if ($headerLines !== array()) {
            $options['header'] = $headerLines;
        }

        $context = stream_context_create(array('http' => $options));

        $responseBody = @file_get_contents($url, false, $context);
        $responseHeaders = isset($http_response_header) ? $http_response_header : array();

        $status = 0;
        $parsedHeaders = array();

        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                // A new status line resets the header set (redirect chains).
                $status = (int) $m[1];
                $parsedHeaders = array();
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                if ($name === 'set-cookie') {
                    $this->storeCookie($value);
                }
                $parsedHeaders[$name] = $value;
            }
        }

        $this->status = $status;
        $this->body = $responseBody === false ? '' : $responseBody;
        $this->headers = $parsedHeaders;

        if ($follow && $status >= 300 && $status < 400 && isset($parsedHeaders['location'])) {
            $location = $parsedHeaders['location'];
            $path = $this->relativePath($location);
            return $this->request('GET', $path, array(), $follow);
        }

        return array('status' => $status, 'body' => $this->body, 'headers' => $parsedHeaders);
    }

    /**
     * Perform a GET request.
     */
    public function get(string $path, bool $follow = false): array
    {
        return $this->request('GET', $path, array(), $follow);
    }

    /**
     * Perform a POST request.
     */
    public function post(string $path, array $fields, bool $follow = false): array
    {
        return $this->request('POST', $path, $fields, $follow);
    }

    /**
     * Perform a POST request with a multipart/form-data body.
     *
     * A file is uploaded as a real multipart part, so the server's own
     * upload handling (including is_uploaded_file()) is exercised, not
     * simulated.
     *
     * @param string $path
     * @param array  $fields      Ordinary form fields.
     * @param array  $files       Files, keyed by field name. Each entry has
     *                            'path', 'name' and 'type' keys.
     * @param bool   $follow
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function postMultipart(string $path, array $fields, array $files, bool $follow = false): array
    {
        $boundary = '----GreenCornerBoundary' . bin2hex(random_bytes(12));
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
            $body .= $value . "\r\n";
        }

        foreach ($files as $name => $file) {
            $contents = file_get_contents((string) $file['path']);

            if ($contents === false) {
                throw new RuntimeException('Could not read the file to upload: ' . $file['path']);
            }

            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"; filename="'
                . basename((string) $file['name']) . '"' . "\r\n";
            $body .= 'Content-Type: ' . $file['type'] . "\r\n\r\n";
            $body .= $contents . "\r\n";
        }

        $body .= '--' . $boundary . "--\r\n";

        return $this->requestRaw(
            'POST',
            $path,
            $body,
            'multipart/form-data; boundary=' . $boundary,
            $follow
        );
    }

    /**
     * Perform a request with a body that is already encoded.
     *
     * Public so the suites can post a JSON body (the background cart
     * endpoints) and so a test can run two genuinely concurrent requests.
     *
     * @param string $method
     * @param string $path
     * @param string $body
     * @param string $contentType
     * @param bool   $follow
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function requestRaw(string $method, string $path, string $body, string $contentType, bool $follow = false): array
    {
        $url = $this->baseUrl . $path;
        $headerLines = array('Content-Type: ' . $contentType);

        if ($this->cookies !== array()) {
            $pairs = array();
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headerLines[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $options = array(
            'method'          => $method,
            'content'         => $body,
            'timeout'         => 20,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'max_redirects'   => 0,
            'header'          => $headerLines,
        );

        $context = stream_context_create(array('http' => $options));

        $responseBody = @file_get_contents($url, false, $context);
        $responseHeaders = isset($http_response_header) ? $http_response_header : array();

        $status = 0;
        $parsedHeaders = array();

        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $parsedHeaders = array();
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                if ($name === 'set-cookie') {
                    $this->storeCookie($value);
                }
                $parsedHeaders[$name] = $value;
            }
        }

        $this->status = $status;
        $this->body = $responseBody === false ? '' : $responseBody;
        $this->headers = $parsedHeaders;

        if ($follow && $status >= 300 && $status < 400 && isset($parsedHeaders['location'])) {
            return $this->get($this->relativePath($parsedHeaders['location']), $follow);
        }

        return array('status' => $status, 'body' => $this->body, 'headers' => $parsedHeaders);
    }

    /**
     * The CSRF token currently in the cookie jar's session, read from a page.
     *
     * @param string $path A page containing a csrf_token hidden field.
     * @return string
     */
    public function csrfToken(string $path = '/auth/login'): string
    {
        $this->get($path);

        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $this->body, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * The value of a cookie in the jar.
     */
    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * Put a cookie into the jar, replacing any existing value.
     *
     * Used by tests that need to replay a known session identifier.
     */
    public function setCookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    /**
     * Forget every stored cookie (a fresh browser).
     */
    public function resetCookies(): void
    {
        $this->cookies = array();
    }

    /**
     * Store a Set-Cookie header value in the jar.
     */
    private function storeCookie(string $value): void
    {
        $parts = explode(';', $value);
        $nameValue = trim($parts[0]);
        $eq = strpos($nameValue, '=');

        if ($eq === false) {
            return;
        }

        $name = trim(substr($nameValue, 0, $eq));
        $cookieValue = trim(substr($nameValue, $eq + 1));

        // An empty value with an expiry in the past deletes the cookie.
        if ($cookieValue === '' && $this->isExpired($parts)) {
            unset($this->cookies[$name]);
            return;
        }

        $this->cookies[$name] = $cookieValue;
    }

    /**
     * Whether a Set-Cookie header deletes the cookie.
     */
    private function isExpired(array $parts): bool
    {
        foreach ($parts as $part) {
            $part = trim($part);
            if (stripos($part, 'expires=') === 0) {
                $timestamp = strtotime(substr($part, 8));
                if ($timestamp !== false && $timestamp < time()) {
                    return true;
                }
            }
            if (stripos($part, 'max-age=') === 0) {
                $maxAge = (int) substr($part, 8);
                if ($maxAge <= 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Convert an absolute Location header into a path relative to the base
     * URL.
     *
     * The application may redirect to a different host spelling (for example
     * "localhost" instead of "127.0.0.1"), so the path is taken from the
     * Location URL rather than discarded.
     */
    private function relativePath(string $location): string
    {
        if (str_starts_with($location, $this->baseUrl)) {
            return substr($location, strlen($this->baseUrl));
        }

        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            // Keep the path and query of the absolute URL.
            $path = parse_url($location, PHP_URL_PATH);
            $query = parse_url($location, PHP_URL_QUERY);

            $result = ($path === null || $path === '') ? '/' : $path;
            if ($query !== null && $query !== '') {
                $result .= '?' . $query;
            }

            return $result;
        }

        return $location;
    }
}
