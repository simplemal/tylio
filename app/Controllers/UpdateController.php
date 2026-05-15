<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Services\DB;
use Tylio\Services\UpdateApplier;
use Tylio\Services\UpdateChecker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin endpoints for the self-service upgrade flow:
 *   GET  /api/admin/update-check  — compares local vs GitHub latest
 *                                   (24h cache; `?force=1` busts it).
 *   GET  /api/admin/update/state  — last update status, surfaced by
 *                                   Settings.vue's "Aggiornamenti tylio"
 *                                   card (last_update_at, last_error,
 *                                   in_progress, backup_path).
 *   POST /api/admin/update/apply  — fetches the release source tarball
 *                                   from GitHub, backs up the current
 *                                   install, swaps the new code in,
 *                                   runs migrations, resets opcache.
 *                                   Synchronous within the request.
 *
 * Auth-gated (the route group registers AuthMiddleware + CsrfMiddleware
 * around it).
 *
 * **Extendable by design.** Non-`final`. The multi-tenant SaaS overlay
 * subclasses to return `{disabled: true, reason: 'saas'}` for ALL
 * endpoints — the platform operator updates the OSS package centrally,
 * so tenants must not see self-update UI.
 */
class UpdateController
{
    public function __construct(
        protected UpdateChecker $checker,
        protected UpdateApplier $applier,
        protected DB $db,
    ) {}

    public function check(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $force = isset($params['force']) && ($params['force'] === '1' || $params['force'] === 'true');
        $result = $this->checker->check($force);
        return AuthController::json($response, $result);
    }

    /**
     * Surface the persisted update state. Pure-read endpoint — used by
     * the SPA to poll while an apply() is running in another tab and to
     * render the "last updated" line on initial load.
     */
    public function state(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return AuthController::json($response, [
            'in_progress'    => $this->readBool('site.update_in_progress'),
            'last_update_at' => $this->readStr('site.last_update_at'),
            'last_version'   => $this->readStr('site.last_update_version'),
            'last_error'     => $this->readStr('site.last_update_error'),
            'last_backup'    => $this->readStr('site.last_update_backup'),
        ]);
    }

    /**
     * Trigger an in-app upgrade. Body may contain `version` to target a
     * specific tag (e.g. `"v0.3.1"`); if omitted, fetches the latest
     * GitHub release.
     *
     * Returns 200 on success with `{ok:true, new_version, backup_path}`,
     * 4xx on validation/precondition failures (`permissions_denied`,
     * `already_in_progress`, `asset_missing`, `release_not_found`,
     * `download_failed`, `extract_failed`, `staging_invalid`), 5xx on
     * unexpected exceptions during swap or migrate.
     */
    public function apply(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $target = isset($body['version']) && is_string($body['version']) ? trim($body['version']) : '';
        if ($target !== '' && !preg_match('/^v?\d+\.\d+\.\d+/', $target)) {
            return AuthController::json($response, [
                'ok' => false,
                'error' => 'invalid_version',
                'detail' => "Tag non valido: $target",
            ], 422);
        }
        $result = $this->applier->apply($target !== '' ? $target : null);

        $status = 200;
        if (!$result['ok']) {
            // After narrowing `ok === false`, the apply() return type
            // guarantees `error` is set (every fail() path writes it),
            // so PHPStan rejects a `?? ''` defensive default here.
            $err = $result['error'];
            // Map "user error" cases to 4xx; everything else stays 500.
            $status = match ($err) {
                'permissions_denied'   => 412, // Precondition Failed
                'already_in_progress'  => 409, // Conflict
                'release_not_found'    => 404,
                'asset_missing'        => 424, // Failed Dependency
                'download_failed'      => 502, // Bad Gateway
                'extract_failed',
                'staging_invalid'      => 422,
                default                => 500,
            };
        }
        return AuthController::json($response, $result, $status);
    }

    protected function readBool(string $key): bool
    {
        $row = $this->db->one('SELECT value FROM settings WHERE key = ? LIMIT 1', [$key]);
        return $row !== null && (bool)json_decode((string)($row['value'] ?? ''), true);
    }

    protected function readStr(string $key): string
    {
        $row = $this->db->one('SELECT value FROM settings WHERE key = ? LIMIT 1', [$key]);
        if ($row === null) return '';
        $decoded = json_decode((string)($row['value'] ?? ''), true);
        return is_string($decoded) ? $decoded : '';
    }
}
