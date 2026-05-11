<?php
declare(strict_types=1);

use Tylio\Controllers\AuthController;
use Tylio\Controllers\BlocksController;
use Tylio\Controllers\ExportController;
use Tylio\Controllers\FaviconController;
use Tylio\Controllers\InstallController;
use Tylio\Controllers\MediaController;
use Tylio\Controllers\PageController;
use Tylio\Controllers\SettingsController;
use Tylio\Controllers\SubmissionsController;
use Tylio\Controllers\ThemeController;
use Tylio\Controllers\TwoFactorController;
use Tylio\Controllers\TypesController;
use Tylio\Middleware\AuthMiddleware;
use Tylio\Middleware\CsrfMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {

    // ============= INSTALL (one-shot) =============
    $app->get('/install', [InstallController::class, 'show']);
    $app->post('/install', [InstallController::class, 'submit']);

    // ============= PUBLIC SITE =============
    $app->get('/', [PageController::class, 'home']);
    $app->get('/sitemap.xml', [PageController::class, 'sitemap']);
    $app->get('/robots.txt', [PageController::class, 'robots']);
    $app->get('/manifest.webmanifest', [PageController::class, 'manifest']);
    $app->post('/submit/{blockId:[0-9]+}', [SubmissionsController::class, 'submit']);
    // Click tracking via navigator.sendBeacon from the public layout.
    $app->post('/track-click', [PageController::class, 'trackClick']);

    // ============= ADMIN SHELL (built SPA) =============
    $app->get('/admin', [PageController::class, 'adminShell']);
    $app->get('/admin/{path:.+}', [PageController::class, 'adminShell']);

    // ============= API: PUBLIC =============
    $app->group('/api', function (RouteCollectorProxy $g) {
        $g->post('/auth/login', [AuthController::class, 'login']);
        // Login step 2 (TOTP / backup code): the pending session was created
        // by step 1. Auth is via cookie + code verification. No CSRF here
        // (same as /auth/login) — brute-force abuse is covered by IP-based
        // rate limit + 250 ms delay on failure.
        $g->post('/auth/login/2fa', [AuthController::class, 'login2fa']);
        $g->get('/auth/me', [AuthController::class, 'me']);
        $g->get('/theme/public', [ThemeController::class, 'publicTheme']);
    });

    // ============= API: AUTHENTICATED =============
    $app->group('/api', function (RouteCollectorProxy $g) {
        $g->post('/auth/logout', [AuthController::class, 'logout']);

        $g->get('/types', [TypesController::class, 'index']);

        $g->get('/blocks', [BlocksController::class, 'index']);
        $g->post('/blocks', [BlocksController::class, 'create']);
        $g->get('/blocks/{id:[0-9]+}', [BlocksController::class, 'show']);
        $g->put('/blocks/{id:[0-9]+}', [BlocksController::class, 'update']);
        $g->delete('/blocks/{id:[0-9]+}', [BlocksController::class, 'destroy']);
        $g->post('/blocks/reorder', [BlocksController::class, 'reorder']);
        // Apply the source block's data + style to ALL blocks of the same
        // type (server-side whitelist: `divider` only). Used by the
        // "Apply to all separators" button in EditBlock.
        $g->post('/blocks/{id:[0-9]+}/apply-to-same-type', [BlocksController::class, 'applyToSameType']);

        $g->get('/theme', [ThemeController::class, 'show']);
        $g->put('/theme', [ThemeController::class, 'update']);

        $g->get('/settings', [SettingsController::class, 'index']);
        $g->put('/settings', [SettingsController::class, 'update']);

        $g->get('/media', [MediaController::class, 'index']);
        $g->post('/media', [MediaController::class, 'upload']);
        $g->delete('/media/{id:[0-9]+}', [MediaController::class, 'destroy']);

        $g->post('/favicon', [FaviconController::class, 'upload']);
        $g->delete('/favicon', [FaviconController::class, 'destroy']);

        $g->get('/preview', [PageController::class, 'preview']);
        $g->get('/stats', [SettingsController::class, 'stats']);
        $g->get('/submissions', [SubmissionsController::class, 'index']);
        $g->get('/submissions/unread-count', [SubmissionsController::class, 'unreadCount']);
        $g->post('/submissions/mark-all-read', [SubmissionsController::class, 'markAllRead']);
        $g->post('/submissions/{id:[0-9]+}/read', [SubmissionsController::class, 'markRead']);
        $g->delete('/submissions/{id:[0-9]+}', [SubmissionsController::class, 'destroyOne']);
        $g->delete('/submissions', [SubmissionsController::class, 'destroyAll']);

        $g->get('/export', [ExportController::class, 'download']);
        $g->get('/export/inline', [ExportController::class, 'downloadInline']);

        // 2FA management endpoints (user already authenticated).
        $g->get('/2fa/status', [TwoFactorController::class, 'status']);
        $g->post('/2fa/setup/init', [TwoFactorController::class, 'setupInit']);
        $g->post('/2fa/setup/confirm', [TwoFactorController::class, 'setupConfirm']);
        $g->post('/2fa/disable', [TwoFactorController::class, 'disable']);
        $g->post('/2fa/backup/regenerate', [TwoFactorController::class, 'regenerateBackup']);
    })->add(CsrfMiddleware::class)->add(AuthMiddleware::class);
};
