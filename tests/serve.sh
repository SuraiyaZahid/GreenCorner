#!/usr/bin/env bash
#
# GreenCorner — Local development server
#
# Starts PHP's built-in web server with the document root set to public/,
# so that config/, controllers/, models/, views/, helpers/, database/ and
# storage/ are never reachable from the browser (SEC-6).
#
# Usage:
#   ./tests/serve.sh [port]
#
# On Windows with Git Bash, run:
#   ./.tools/php/php.exe -c .tools/php/php.ini -S 127.0.0.1:8000 -t public public/index.php

set -e

PORT="${1:-8000}"
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$PROJECT_ROOT"

PHP_BIN="${PHP_BIN:-$PROJECT_ROOT/.tools/php/php.exe}"
PHP_INI="${PHP_INI:-$PROJECT_ROOT/.tools/php/php.ini}"

if [ ! -f "$PHP_BIN" ]; then
    echo "PHP binary not found at: $PHP_BIN" >&2
    echo "Set PHP_BIN to point at your PHP executable." >&2
    exit 1
fi

echo "GreenCorner development server"
echo "  URL:    http://127.0.0.1:${PORT}"
echo "  Root:   ${PROJECT_ROOT}/public"
echo "  Stop:   Ctrl+C"
echo

# The router script reproduces the rewrite rules of public/.htaccess, which
# PHP's built-in web server does not read.
exec "$PHP_BIN" -c "$PHP_INI" -S "127.0.0.1:${PORT}" -t "$PROJECT_ROOT/public" "$PROJECT_ROOT/public/router.php"
