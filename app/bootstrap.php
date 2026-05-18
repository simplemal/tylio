<?php
declare(strict_types=1);

use DI\Container;
use Dotenv\Dotenv;
use Tylio\Config;
use Tylio\Util\Build;
use Tylio\Services\Auth;
use Tylio\Services\BlockRegistry;
use Tylio\Services\Csrf;
use Tylio\Services\DB;
use Tylio\Services\EmailVerification;
use Tylio\Services\Export;
use Tylio\Services\I18n;
use Tylio\Services\Import;
use Tylio\Services\Mailer;
use Tylio\Services\Migrations;
use Tylio\Services\RateLimit;
use Tylio\Services\Renderer;
use Tylio\Services\StaticExporter;
use Tylio\Services\UpdateChecker;
use Slim\Factory\AppFactory;

return static function (): \Slim\App {
    // Ensure every file/dir created by this worker is rw for the group
    // too. Without this, the default php-fpm umask (022) makes db.sqlite,
    // session files, logs, uploads and favicons mode 644/755 — owner rw
    // only. On shared hosting where the sftp user (e.g. `ladyglow`)
    // shares the www-data group with PHP-FPM, those mode bits mean
    // either the sftp user OR www-data ends up read-only on files the
    // other created. Result: "attempt to write a readonly database" the
    // first time something tries to update the schema. umask(0007) =>
    // files born 660, dirs 770, group preserved by the setgid bit on
    // the parent.
    umask(0007);
    $rootPath = dirname(__DIR__);

    if (file_exists($rootPath . '/.env')) {
        Dotenv::createImmutable($rootPath)->safeLoad();
    }

    // Cache-buster build version (reads the BUILD file from project root,
    // defines TYLIO_BUILD).
    Build::init($rootPath);

    $config = new Config($rootPath);

    if ($config->bool('APP_DEBUG', false)) {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
    } else {
        // `E_STRICT` was folded into `E_NOTICE` in PHP 8.4 and the constant
        // itself is now deprecated, so we don't reference it anymore.
        error_reporting(E_ALL & ~E_DEPRECATED);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', $rootPath . '/data/logs/php-error.log');
    }

    foreach (['data', 'data/sessions', 'data/logs'] as $dir) {
        $path = $rootPath . '/' . $dir;
        if (!is_dir($path)) @mkdir($path, 0770, true);
    }

    $container = new Container();
    $container->set(Config::class, $config);
    $container->set(DB::class, fn() => new DB($config));
    $container->set(RateLimit::class, fn(Container $c) => new RateLimit($c->get(DB::class), $config));
    $container->set(Auth::class, fn(Container $c) => new Auth($c->get(DB::class), $config));
    $container->set(Csrf::class, fn() => new Csrf());
    $container->set(BlockRegistry::class, fn() => new BlockRegistry());
    $container->set(I18n::class, fn() => new I18n($config));
    $container->set(Mailer::class, fn(Container $c) => new Mailer(
        $config,
        $c->get(I18n::class),
        $c->get(DB::class),
    ));
    // Email verification for `site.admin_email`: code generation, hash
    // storage, rate-limited resend. See `app/Services/EmailVerification.php`.
    $container->set(EmailVerification::class, fn(Container $c) => new EmailVerification(
        $c->get(DB::class),
        $config,
        $c->get(Mailer::class),
    ));
    // MailController: SMTP smoke-test endpoint (`POST /api/admin/mail/test`).
    $container->set(\Tylio\Controllers\MailController::class, fn(Container $c) => new \Tylio\Controllers\MailController(
        $c->get(Mailer::class),
        $c->get(DB::class),
    ));
    $container->set(Renderer::class, fn(Container $c) => new Renderer(
        $c->get(DB::class),
        $c->get(BlockRegistry::class),
        $config,
        $c->get(I18n::class),
    ));
    $container->set(StaticExporter::class, fn(Container $c) => new StaticExporter(
        $c->get(Renderer::class),
        $config,
    ));
    // Full-site export / import (DB rows + uploads, packaged as tar.gz).
    // Used by /admin/export, /admin/import and /install/import — all
    // gated by their respective controllers.
    $container->set(Export::class, fn(Container $c) => new Export(
        $c->get(DB::class),
        $config,
    ));
    $container->set(Import::class, fn(Container $c) => new Import(
        $c->get(DB::class),
        $config,
    ));
    // GitHub release lookup for the admin SPA's "Aggiornamenti tylio"
    // card. Results cached in `settings` for 24h to stay under the
    // GitHub API rate limit.
    $container->set(UpdateChecker::class, fn(Container $c) => new UpdateChecker(
        $c->get(DB::class),
        $config,
    ));
    $container->set(\Tylio\Services\UpdateApplier::class, fn(Container $c) => new \Tylio\Services\UpdateApplier(
        $c->get(DB::class),
        $config,
        new Migrations($c->get(DB::class), $config),
    ));
    $container->set(\Tylio\Controllers\UpdateController::class, fn(Container $c) => new \Tylio\Controllers\UpdateController(
        $c->get(UpdateChecker::class),
        $c->get(\Tylio\Services\UpdateApplier::class),
        $c->get(DB::class),
    ));

    // Auto-migrate on boot: idempotent, safe on a fresh install too.
    try {
        (new Migrations($container->get(DB::class), $config))->run();
    } catch (\Throwable $e) {
        error_log('[tylio] migrations bootstrap failed: ' . $e->getMessage());
    }

    AppFactory::setContainer($container);
    $app = AppFactory::create();
    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();

    $errorMiddleware = $app->addErrorMiddleware(
        $config->bool('APP_DEBUG', false),
        true,
        true,
        $container->has('logger') ? $container->get('logger') : null,
    );

    $errorMiddleware->setErrorHandler(
        \Slim\Exception\HttpNotFoundException::class,
        function (\Psr\Http\Message\ServerRequestInterface $request) use ($container) {
            $path = $request->getUri()->getPath();
            $acceptLang = (string)($request->getHeaderLine('Accept-Language') ?? '');
            try {
                $renderer = $container->get(\Tylio\Services\Renderer::class);
                $body = $renderer->renderNotFound($path, $acceptLang);
            } catch (\Throwable $e) {
                $body = '<!doctype html><meta charset="utf-8"><title>404</title><h1>404 — Not found</h1><p><a href="/">Home</a></p>';
            }
            $response = new \Slim\Psr7\Response(404);
            $response->getBody()->write($body);
            return $response
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('Expires', '0');
        }
    );

    // Trust proxies in front (Cloudflare / pfSense / HAProxy / similar).
    $trusted = array_filter(array_map('trim', explode(',', (string)$config->get('TRUSTED_PROXIES', ''))));
    if (!empty($trusted) && isset($_SERVER['REMOTE_ADDR'])) {
        if (Tylio\Util\Net::ipInRanges($_SERVER['REMOTE_ADDR'], $trusted)) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
                $proto = strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO']));
                if ($proto === 'https') {
                    $_SERVER['HTTPS'] = 'on';
                    $_SERVER['SERVER_PORT'] = '443';
                }
            }
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
                if (!empty($_SERVER[$h])) {
                    $first = trim(explode(',', (string)$_SERVER[$h])[0]);
                    if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP) !== false) {
                        $_SERVER['REMOTE_ADDR'] = $first;
                        break;
                    }
                }
            }
        }
    }

    (require __DIR__ . '/routes.php')($app);

    return $app;
};
