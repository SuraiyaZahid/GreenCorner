#!/bin/sh
#
# GreenCorner — container entrypoint
#
# Prepares the writable directories and hands over to Apache. It never
# prints a credential and never puts one on the command line.

set -eu

echo "GreenCorner container starting."

# The storage directories are writable by the web user. They live OUTSIDE the
# document root, so nothing in them can be fetched over HTTP (SEC-6).
for directory in /var/www/storage/uploads /var/www/storage/logs; do
    if [ ! -d "$directory" ]; then
        mkdir -p "$directory"
    fi
done

# The database is reached through the environment. The credentials are
# supplied by the compose file from the host environment and are never baked
# into the image (decision D-001).
if [ -z "${DB_HOST:-}" ]; then
    echo "DB_HOST is not set. The container cannot reach the database." >&2
    exit 1
fi

# The application refuses to start without a password: a missing credential is
# a fatal error with a clear message, never a silent fallback (decision
# D-001). config/config.php carries no password of its own.
if [ -z "${DB_PASS:-}" ]; then
    echo "DB_PASS is not set. The application refuses to start without a" >&2
    echo "database password (decision D-001)." >&2
    exit 1
fi

# The application reads its database settings from config/config.local.php,
# which is deliberately NOT in the image (a credential must never be baked
# into a layer, decision D-001) and is excluded by .dockerignore. The file is
# therefore written here, at start-up, from the environment — so the password
# is never committed, never baked into the image, and never appears on a
# command line.
#
# The file is written with mode 640 and is owned by the web user, so only the
# web server's account can read it. It lives outside the document root and
# the config/ directory's own .htaccess denies it as well (SEC-6).
if [ ! -f /var/www/config/config.local.php ]; then
    echo "Writing the local configuration from the environment."

    umask 027

    cat > /var/www/config/config.local.php <<PHP
<?php
// Written by the container entrypoint from the environment.
// Never committed, and never part of the image (decision D-001).
if (!defined('GREENCORNER_APP')) {
    http_response_code(403);
    exit('Forbidden');
}
define('APP_ENV', '${APP_ENV:-production}');
define('APP_DEBUG', false);
define('APP_URL', '${GREEN_CORNER_URL:-http://127.0.0.1:8080}');
define('DB_HOST', '${DB_HOST:-db}');
define('DB_PORT', ${DB_PORT:-3306});
define('DB_NAME', '${DB_NAME:-greencorner}');
define('DB_USER', '${DB_USER:-greencorner}');
define('DB_PASS', '${DB_PASS}');
PHP

    chmod 640 /var/www/config/config.local.php
fi

# APP_ENV selects the production behaviour: the Secure session cookie flag
# is set and the sample-data seed refuses to run (SC-16).
if [ "${APP_ENV:-production}" = "production" ]; then
    echo "Running in production mode."
fi

exec "$@"
