# GreenCorner — Production web image
#
# The web server is PHP 8.3 running as Apache's mod_php (decision D-020).
#
# WHY mod_php AND NOT PHP-FPM:
#
#   The project ships three `php_flag` directives:
#     - public/.htaccess    : php_flag expose_php off
#     - config/.htaccess    : php_flag engine off
#     - helpers/image.php   : writes php_flag engine off into the upload
#                            directory's .htaccess guard
#
#   `php_flag` is a mod_php directive. Under PHP-FPM the two .htaccess
#   directives would silently become no-ops, and the runtime-generated upload
#   guard would do nothing at all. mod_php honours all three unchanged, so
#   the security controls that were reviewed and accepted keep working.
#
#   PHP-FPM remains a valid alternative for a deployment that needs process
#   isolation or several app containers behind a load balancer. It is a
#   separate profile to validate, not the default.
#
# IMAGE CHOICES:
#   - php:8.3-apache  : the web server.
#   - MySQL 8.0.16+   : MANDATORY. CHECK constraints are only ENFORCED from
#                       8.0.16; before that they are parsed but ignored
#                       (SRS SC-2). The image tag is pinned to a patch
#                       release so the build is reproducible.
#
# The document root is public/. config/, controllers/, models/, helpers/,
# views/, database/ and storage/ are copied into the image but sit OUTSIDE
# the web root, so they can never be fetched over HTTP (SEC-6).

FROM php:8.3-apache

# ---------------------------------------------------------------------------
# System packages
# ---------------------------------------------------------------------------
# - mysqli   : the database driver (R-SQL-*).
# - gd       : the image pipeline (FR-6.1, FR-6.2).
# - mbstring : multibyte string handling. REQUIRED: models/User.php and the
#              controllers measure field lengths with mb_strlen(), so a build
#              without this extension raises a fatal "Call to undefined
#              function mb_strlen()" on registration, on checkout and on the
#              Admin product/category forms.
# - zip      : used by the backup tooling.
# - openssl  : needed by the backup encryption.
# - mysql-client : mysqldump, used by the backup job (NFR-R-03).
RUN set -eux; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        zlib1g-dev \
        unzip \
        default-mysql-client \
        ca-certificates \
        gosu; \
    docker-php-ext-configure gd --with-jpeg --with-freetype; \
    docker-php-ext-install -j"$(nproc)" mysqli gd mbstring zip; \
    rm -rf /var/lib/apt/lists/*

# ---------------------------------------------------------------------------
# PHP configuration
# ---------------------------------------------------------------------------
# The error-output setting is REQUIRED, not optional (decision D-011): a PHP
# warning printed into the response body sends the headers early, and the
# application can then no longer set its own status code. The details still
# reach the error log.
RUN { \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_log = /dev/stderr'; \
        echo 'expose_php = Off'; \
        echo 'memory_limit = 256M'; \
        echo 'upload_max_filesize = 4M'; \
        echo 'post_max_size = 8M'; \
        echo 'max_execution_time = 60'; \
        echo 'date.timezone = UTC'; \
        echo 'session.use_strict_mode = 1'; \
        echo 'session.use_only_cookies = 1'; \
        echo 'session.cookie_httponly = 1'; \
        echo 'session.cookie_samesite = Lax'; \
    } > /usr/local/etc/php/conf.d/green-corner.ini

# OPcache is enabled for production. It is a large part of why the same
# application code serves more requests per second with the container than
# with the bare PHP built-in server.
RUN { \
        echo 'opcache.enable = 1'; \
        echo 'opcache.memory_consumption = 128'; \
        echo 'opcache.max_accelerated_files = 10000'; \
        echo 'opcache.validate_timestamps = 1'; \
        echo 'opcache.revalidate_freq = 60'; \
    } > /usr/local/etc/php/conf.d/green-corner-opcache.ini

# ---------------------------------------------------------------------------
# Apache configuration
# ---------------------------------------------------------------------------
# The rewrite rules in public/.htaccess need mod_rewrite and mod_headers.
# The default document root is /var/www/html; it is moved to /var/www/public.
RUN set -eux; \
    a2enmod rewrite headers; \
    sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf; \
    echo 'ServerTokens Prod' >> /etc/apache2/conf-available/green-corner-security.conf; \
    echo 'ServerSignature Off' >> /etc/apache2/conf-available/green-corner-security.conf; \
    echo 'TraceEnable Off' >> /etc/apache2/conf-available/green-corner-security.conf; \
    a2enconf green-corner-security; \
    rm -f /var/www/html/index.html

ENV APACHE_DOCUMENT_ROOT=/var/www/public

# ---------------------------------------------------------------------------
# Application
# ---------------------------------------------------------------------------
WORKDIR /var/www

# The application code and the public directory are separate so that the
# document root can be set to public/ alone.
COPY . /var/www/

# The document root is /var/www/public. Everything else sits above it and is
# therefore unreachable over HTTP (SEC-6).
RUN set -eux; \
    mkdir -p /var/www/storage/uploads /var/www/storage/logs; \
    chown -R www-data:www-data /var/www/storage; \
    chmod 750 /var/www/storage/uploads /var/www/storage/logs; \
    chmod 644 /var/www/public/.htaccess; \
    chmod 644 /var/www/config/.htaccess; \
    find /var/www -type d -exec chmod 755 {} \; ; \
    find /var/www -type f -name '*.php' -exec chmod 644 {} \; ; \
    # The entrypoint writes config/config.local.php at start-up, from the
    # environment, because a credential must never be baked into an image
    # layer (decision D-001). The directory is owned by the web user so the
    # entrypoint can write it; the file itself is created with mode 640.
    chown www-data:www-data /var/www/config

# The uploaded-file guard is normally written by ensure_upload_dir() at
# runtime. It is created here as well so that the protection is in place
# from the very first request, before any upload has happened.
RUN set -eux; \
    printf '%s\n' \
        '# GreenCorner — uploaded product images.' \
        '# These files are images and must never be executed as code (SEC-6).' \
        'php_flag engine off' \
        '<FilesMatch "\.(php|phtml|phar|pl|py|cgi|asp|aspx|sh)$">' \
        '    Require all denied' \
        '</FilesMatch>' \
        > /var/www/storage/uploads/.htaccess; \
    chown www-data:www-data /var/www/storage/uploads/.htaccess

# ---------------------------------------------------------------------------
# Entrypoint
# ---------------------------------------------------------------------------
COPY docker/entrypoint.sh /usr/local/bin/green-corner-entrypoint
RUN chmod +x /usr/local/bin/green-corner-entrypoint

# The application never runs as root.
USER www-data

EXPOSE 80

ENTRYPOINT ["green-corner-entrypoint"]
CMD ["apache2-foreground"]
