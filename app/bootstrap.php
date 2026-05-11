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
use Tylio\Services\I18n;
use Tylio\Services\Mailer;
use Tylio\Services\Migrations;
use Tylio\Services\RateLimit;
use Tylio\Services\Renderer;
use Tylio\Services\StaticExporter;
use Slim\Factory\AppFactory;

return static function (): \Slim\App {
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

    $app->addErrorMiddleware(
        $config->bool('APP_DEBUG', false),
        true,
        true,
        $container->has('logger') ? $container->get('logger') : null,
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
