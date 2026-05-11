<?php
declare(strict_types=1);

/**
 * tylio — single front controller.
 * All HTTP traffic enters here. Routing is handled by Slim.
 *
 * Static-asset handoff for PHP's built-in development server
 * (`php -S localhost:8000 -t . index.php`): if the request points at a
 * real file under DocumentRoot, `return false` from the router lets the
 * cli-server SAPI serve it directly with the right Content-Type. In
 * production this branch is dead code: Apache handles it via the
 * `RewriteCond %{REQUEST_FILENAME} !-f` rule in `.htaccess`.
 */
if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (is_string($path) && $path !== '' && $path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

require __DIR__ . '/vendor/autoload.php';

$app = (require __DIR__ . '/app/bootstrap.php')();
$app->run();