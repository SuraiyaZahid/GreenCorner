<?php
/**
 * GreenCorner — Phase 6: Docker environment static checks
 *
 * Verifies the Docker deployment files WITHOUT a container runtime, so that
 * the configuration is checked even on a machine that has no Docker
 * installed.
 *
 * These are structural and correctness checks against the requirements:
 *   - the MySQL image is 8.0.16 or later (SC-2)
 *   - the web image is php:8.3-apache (decision D-020)
 *   - the document root is public/ and the application files sit outside it
 *   - no credential is baked into any tracked file (D-001)
 *   - the required PHP extensions are installed
 *   - the error-output setting is present (D-011)
 *
 * A live `docker compose build` check is separate and is reported as such
 * when no container runtime is available.
 *
 * Usage:
 *   php tests/phase6_docker_checks.php
 */

require_once __DIR__ . '/TestRunner.php';

$root = dirname(__DIR__);

$runner = new TestRunner();

/**
 * Read a file from the project root.
 */
function project_file(string $root, string $relative): string
{
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    if (!is_file($path)) {
        return '';
    }

    return (string) file_get_contents($path);
}

$runner->group('1. The Docker files exist');

$runner->test('The Dockerfile exists', function () use ($root) {
    assert_true(
        is_file($root . '/Dockerfile'),
        'The Dockerfile is missing'
    );
});

$runner->test('The compose file exists', function () use ($root) {
    assert_true(
        is_file($root . '/docker-compose.yml'),
        'The compose file is missing'
    );
});

$runner->test('The entrypoint script exists', function () use ($root) {
    assert_true(
        is_file($root . '/docker/entrypoint.sh'),
        'The entrypoint script is missing'
    );
});

$runner->test('The .dockerignore exists', function () use ($root) {
    assert_true(
        is_file($root . '/.dockerignore'),
        'The .dockerignore is missing'
    );
});

$runner->test('The .env.example exists and is not a real .env', function () use ($root) {
    assert_true(
        is_file($root . '/.env.example'),
        'The .env.example is missing'
    );
    assert_true(
        !is_file($root . '/.env'),
        'A real .env file is present. It must not be committed or shipped.'
    );
});

// ---------------------------------------------------------------------------
// 2. The image choices
// ---------------------------------------------------------------------------

$runner->group('2. The image choices');

$runner->test('The web image is php:8.3-apache (decision D-020)', function () use ($root) {
    $dockerfile = project_file($root, 'Dockerfile');

    assert_contains('FROM php:8.3-apache', $dockerfile);
    assert_not_contains('FROM php:8.3-fpm', $dockerfile, 'The approved image is php:8.3-apache');
});

$runner->test('The MySQL image is 8.0.16 or later (SC-2)', function () use ($root) {
    $compose = project_file($root, 'docker-compose.yml');

    if (preg_match('/image:\s*mysql:([0-9.]+)/', $compose, $m) !== 1) {
        throw new Exception('No MySQL image tag found in the compose file');
    }

    $version = $m[1];

    // Compare numerically: 8.0.16 is the mandatory floor.
    $parts = array_map('intval', explode('.', $version));
    $major = $parts[0] ?? 0;
    $minor = $parts[1] ?? 0;
    $patch = $parts[2] ?? 0;

    $ok = ($major > 8)
        || ($major === 8 && $minor > 0)
        || ($major === 8 && $minor === 0 && $patch >= 16);

    assert_true(
        $ok,
        'MySQL ' . $version . ' is below the mandatory 8.0.16 floor (SC-2)'
    );
});

$runner->test('The MySQL image is pinned to a patch release', function () use ($root) {
    $compose = project_file($root, 'docker-compose.yml');

    if (preg_match('/image:\s*mysql:([0-9.]+)/', $compose, $m) !== 1) {
        throw new Exception('No MySQL image tag found');
    }

    assert_true(
        substr_count($m[1], '.') >= 2,
        'The MySQL image should be pinned to a patch release, got ' . $m[1]
    );
});

$runner->test('The database runs with STRICT_TRANS_TABLES (AC-41)', function () use ($root) {
    $compose = project_file($root, 'docker-compose.yml');

    assert_contains('STRICT_TRANS_TABLES', $compose);
});

$runner->test('The schema is applied on first start', function () use ($root) {
    $compose = project_file($root, 'docker-compose.yml');

    assert_contains('docker-entrypoint-initdb.d', $compose);
    assert_contains('database/schema.sql', $compose);
});

// ---------------------------------------------------------------------------
// 3. The document root and the web-root boundary
// ---------------------------------------------------------------------------

$runner->group('3. The document root');

$runner->test('The Apache document root is public/', function () use ($root) {
    $dockerfile = project_file($root, 'Dockerfile');

    assert_contains('APACHE_DOCUMENT_ROOT=/var/www/public', $dockerfile);
});

$runner->test('The application files are copied above the document root', function () use ($root) {
    $dockerfile = project_file($root, 'Dockerfile');

    assert_contains('WORKDIR /var/www', $dockerfile);
    assert_contains('COPY . /var/www/', $dockerfile);
});

$runner->test('The required directories exist in the image', function () use ($root) {
    $dockerfile = project_file($root, 'Dockerfile');

    foreach (array('/var/www/storage/uploads', '/var/www/storage/logs') as $directory) {
        assert_contains($directory, $dockerfile, $directory . ' must be created in the image');
    }
});

$runner->test('The upload guard is created in the image', function () use ($root) {
    $dockerfile = project_file($root, 'Dockerfile');

    assert_contains('storage/uploads/.htaccess', $dockerfile);
    assert_contains('php_flag engine off', $dockerfile, 'The upload guard must disable PHP (SEC-6)');
});

$runner->test('The mod_php directives of the project are honoured', function () use ($root) {
    $dockerfile = project_file($root, 'Dockerfile');

    // The project's .htaccess files use php_flag, which only mod_php
    // understands. The image must therefore be the Apache one.
    assert_contains('php:8.3-apache', $dockerfile);
    assert_not_contains('php:8.3-fpm', $dockerfile);
});

$runner->test('The required Apache modules are enabled', function () use ($root) {
    $dockerfile = project_file($root, 'Dockerfile');

    assert_contains('a2enmod rewrite', $dockerfile, 'mod_rewrite is required by public/.htaccess');
    assert_contains('headers', $dockerfile, 'mod_headers is required by public/.htaccess');
});

$runner->test('The container does not run as root', function () use ($root) {
    $dockerfile = project_file($root, 'Dockerfile');

    assert_contains('USER www-data', $dockerfile);
});

// ---------------------------------------------------------------------------
// 4. The PHP configuration
// ---------------------------------------------------------------------------

$runner->group('4. The PHP configuration');

$runner->test('The required extensions are installed', function () use ($root) {
    $dockerfile = project_file($root, 'Dockerfile');

    assert_contains('mysqli', $dockerfile, 'The mysqli extension is required');
    assert_contains('gd', $dockerfile, 'The gd extension is required for the image pipeline');
    assert_contains('mbstring', $dockerfile, 'The mbstring extension is required for the character-length checks');
});

$runner->test('The CI workflow installs the required extensions', function () use ($root) {
    $workflow = project_file($root, '.github/workflows/ci.yml');

    // The suite measures field lengths with mb_strlen(), so a CI job without
    // mbstring fails with a fatal error instead of a test assertion.
    assert_contains('mbstring', $workflow, 'The CI workflow must install mbstring');
});

$runner->test('The bootstrap refuses to start without the required extensions', function () use ($root) {
    $bootstrap = project_file($root, 'config/bootstrap.php');

    // A missing extension must be reported as a configuration problem, not
    // as a fatal error raised from whichever controller touched the function
    // first.
    assert_contains('mbstring', $bootstrap, 'The bootstrap must check for mbstring');
    assert_contains('extension_loaded', $bootstrap, 'The bootstrap must check the loaded extensions');
});

$runner->test('Errors are kept out of the response body (D-011)', function () use ($root) {
    $dockerfile = project_file($root, 'Dockerfile');

    assert_contains('display_errors = Off', $dockerfile);
    assert_contains('log_errors = On', $dockerfile);
});

$runner->test('The PHP version is not advertised', function () use ($root) {
    $dockerfile = project_file($root, 'Dockerfile');

    assert_contains('expose_php = Off', $dockerfile);
});

$runner->test('OPcache is enabled', function () use ($root) {
    $dockerfile = project_file($root, 'Dockerfile');

    assert_contains('opcache.enable = 1', $dockerfile);
});

$runner->test('The session cookie flags are set in the image', function () use ($root) {
    $dockerfile = project_file($root, 'Dockerfile');

    assert_contains('session.use_strict_mode = 1', $dockerfile);
    assert_contains('session.cookie_httponly = 1', $dockerfile);
    assert_contains('session.cookie_samesite = Lax', $dockerfile);
});

// ---------------------------------------------------------------------------
// 5. Secrets
// ---------------------------------------------------------------------------

$runner->group('5. Secrets');

$runner->test('No password is written into the compose file', function () use ($root) {
    $compose = project_file($root, 'docker-compose.yml');

    // The password must come from the environment.
    assert_contains('${DB_PASS:', $compose, 'The password must come from the environment');

    // Every DB_PASS assignment must be an environment reference. The valid
    // forms are "${DB_PASS}", "${DB_PASS:?message}" and "${DB_PASS:-default}";
    // anything else is a literal password.
    //
    // The value is taken up to the end of the line, then trimmed, so that a
    // reference with a message containing spaces is matched correctly.
    if (preg_match_all('/^\s*(?:-\s+)?DB_PASS:(.*)$/m', $compose, $matches) > 0) {
        foreach ($matches[1] as $value) {
            $value = trim($value, " \t\r\n\"'");

            if (strpos($value, '${DB_PASS') !== 0) {
                throw new Exception('The compose file contains a literal DB_PASS value: ' . $value);
            }
        }
    }

    // The same rule applies to MYSQL_PASSWORD, which is how the database
    // container receives the password.
    if (preg_match_all('/^\s*MYSQL_PASSWORD:(.*)$/m', $compose, $matches) > 0) {
        foreach ($matches[1] as $value) {
            $value = trim($value, " \t\r\n\"'");

            if (strpos($value, '${DB_PASS') !== 0) {
                throw new Exception('The compose file contains a literal MYSQL_PASSWORD value: ' . $value);
            }
        }
    }
});

$runner->test('The local configuration file is excluded from the image', function () use ($root) {
    $dockerignore = project_file($root, '.dockerignore');

    assert_contains('config/config.local.php', $dockerignore);
    assert_contains('.env', $dockerignore);
});

$runner->test('The .env.example holds no real password', function () use ($root) {
    $example = project_file($root, '.env.example');

    assert_contains('DB_PASS=', $example);

    // The example value must be an obvious placeholder.
    if (preg_match('/^DB_PASS=(.+)$/m', $example, $m) === 1) {
        $value = trim($m[1]);

        assert_true(
            stripos($value, 'change-me') !== false
            || stripos($value, 'example') !== false
            || stripos($value, 'placeholder') !== false,
            'The .env.example password must be an obvious placeholder, got: ' . $value
        );
    }
});

$runner->test('The entrypoint refuses to start without a password (D-001)', function () use ($root) {
    $entrypoint = project_file($root, 'docker/entrypoint.sh');

    assert_contains('DB_PASS', $entrypoint);
    assert_contains('exit 1', $entrypoint, 'The entrypoint must fail loudly without credentials');
});

$runner->test('The entrypoint prints no credential', function () use ($root) {
    $entrypoint = project_file($root, 'docker/entrypoint.sh');

    // No line may echo the password itself.
    if (preg_match('/echo[^\n]*\$\{?DB_PASS/', $entrypoint, $m) === 1) {
        throw new Exception('The entrypoint prints the database password');
    }
});

$runner->test('The entrypoint writes the local configuration (D-001)', function () use ($root) {
    $entrypoint = project_file($root, 'docker/entrypoint.sh');

    // The application REQUIRES config/config.local.php, and the image
    // deliberately does not carry one (a credential must never be baked into
    // a layer). The entrypoint therefore writes it at start-up, from the
    // environment. Without this the container would refuse to start.
    assert_contains('config/config.local.php', $entrypoint, 'The entrypoint must write the local configuration');
    assert_contains('define(\'DB_PASS\'', $entrypoint, 'The generated configuration must define DB_PASS');
    assert_contains('define(\'DB_HOST\'', $entrypoint, 'The generated configuration must define DB_HOST');
    assert_contains('define(\'DB_NAME\'', $entrypoint, 'The generated configuration must define DB_NAME');
    assert_contains('define(\'DB_USER\'', $entrypoint, 'The generated configuration must define DB_USER');
});

$runner->test('The generated configuration carries the access guard', function () use ($root) {
    $entrypoint = project_file($root, 'docker/entrypoint.sh');

    // config/config.local.php must refuse to run when it is reached directly
    // over HTTP, exactly like config/config.php does.
    assert_contains('GREENCORNER_APP', $entrypoint, 'The generated configuration must carry the access guard');
    assert_contains('Forbidden', $entrypoint, 'The generated configuration must refuse direct access');
});

$runner->test('The generated configuration is not world-readable', function () use ($root) {
    $entrypoint = project_file($root, 'docker/entrypoint.sh');

    assert_contains('chmod 640', $entrypoint, 'The local configuration must not be readable by every account');
});

$runner->test('The generated configuration reads only the environment', function () use ($root) {
    $entrypoint = project_file($root, 'docker/entrypoint.sh');

    // Every setting must come from an environment variable, never from a
    // literal value baked into the script.
    foreach (array('APP_ENV', 'APP_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS') as $name) {
        $variable = 'GREEN_CORNER_URL';

        if ($name === 'APP_URL') {
            $variable = 'GREEN_CORNER_URL';
        } elseif ($name === 'APP_ENV') {
            $variable = 'APP_ENV';
        } elseif ($name === 'DB_HOST') {
            $variable = 'DB_HOST';
        } elseif ($name === 'DB_PORT') {
            $variable = 'DB_PORT';
        } elseif ($name === 'DB_NAME') {
            $variable = 'DB_NAME';
        } elseif ($name === 'DB_USER') {
            $variable = 'DB_USER';
        } elseif ($name === 'DB_PASS') {
            $variable = 'DB_PASS';
        }

        assert_contains(
            '${' . $variable . ':-',
            $entrypoint,
            'The generated ' . $name . ' must come from the environment'
        );
    }
});

$runner->test('The config directory is writable by the web user', function () use ($root) {
    $dockerfile = project_file($root, 'Dockerfile');

    // The entrypoint runs as www-data, so it can only write the local
    // configuration if the config directory belongs to that account.
    assert_contains(
        'chown www-data:www-data /var/www/config',
        $dockerfile,
        'The config directory must be owned by the web user'
    );
});

$runner->test('The application is bound to the loopback interface', function () use ($root) {
    $compose = project_file($root, 'docker-compose.yml');

    assert_contains('"127.0.0.1:8080:80"', $compose, 'The published port must be loopback-only');
});

$runner->test('The database is not published to the host', function () use ($root) {
    $compose = project_file($root, 'docker-compose.yml');

    // The database has no ports mapping outside a comment.
    $withoutComments = preg_replace('/#.*$/m', '', $compose);

    if (preg_match('/^\s*ports:/m', $withoutComments, $m, PREG_OFFSET_CAPTURE) === 1) {
        $offset = $m[0][1];
        $appSection = strpos($withoutComments, '  app:');

        // Only one ports block is allowed, and it belongs to the app service.
        $portsCount = preg_match_all('/^\s*ports:/m', $withoutComments);
        assert_equals(1, $portsCount, 'Only the app service may publish a port');
    }
});

// ---------------------------------------------------------------------------
// 6. The compose file is well-formed YAML
// ---------------------------------------------------------------------------

$runner->group('6. The compose file structure');

$runner->test('The compose file declares the db and app services', function () use ($root) {
    $compose = project_file($root, 'docker-compose.yml');

    assert_contains('db:', $compose);
    assert_contains('app:', $compose);
});

$runner->test('The app waits for a healthy database', function () use ($root) {
    $compose = project_file($root, 'docker-compose.yml');

    assert_contains('condition: service_healthy', $compose);
});

$runner->test('The compose file declares the three named volumes', function () use ($root) {
    $compose = project_file($root, 'docker-compose.yml');

    assert_contains('db-data:', $compose);
    assert_contains('uploads-data:', $compose);
    assert_contains('logs-data:', $compose);
});

$runner->test('The compose file is syntactically plausible YAML', function () use ($root) {
    $compose = project_file($root, 'docker-compose.yml');

    // A tab character makes YAML invalid.
    assert_true(
        strpos($compose, "\t") === false,
        'The compose file contains a tab character, which YAML forbids'
    );

    // The top-level keys must be present and at the start of a line.
    foreach (array('services:', 'volumes:') as $key) {
        assert_contains($key, $compose);
    }

    // Every line must either be blank, a comment, or have consistent
    // indentation (a multiple of two spaces).
    foreach (explode("\n", $compose) as $number => $line) {
        if (trim($line) === '' || strpos(ltrim($line), '#') === 0) {
            continue;
        }

        $indent = strlen($line) - strlen(ltrim($line, ' '));

        assert_true(
            $indent % 2 === 0,
            'Line ' . ($number + 1) . ' has an odd indentation of ' . $indent . ' spaces'
        );
    }
});

exit($runner->summary());
