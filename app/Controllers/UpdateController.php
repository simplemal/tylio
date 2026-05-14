<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Services\UpdateChecker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/admin/update-check` — compares the locally installed tylio
 * version with the latest GitHub release of `simplemal/tylio` and
 * returns the result for the admin SPA's "Aggiornamenti tylio" card.
 *
 * Auth-gated (the route group registers AuthMiddleware + CsrfMiddleware
 * around it). Accepts `?force=1` to bust the 24h cache (called when the
 * user clicks "Verifica ora").
 *
 * **Extendable by design.** Non-`final`. The multi-tenant SaaS overlay
 * subclasses to return `{disabled: true, reason: 'saas'}` — the
 * platform operator updates the OSS package centrally, so tenants
 * must not see the update-check UI.
 */
class UpdateController
{
    public function __construct(protected UpdateChecker $checker) {}

    public function check(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $force = isset($params['force']) && ($params['force'] === '1' || $params['force'] === 'true');
        $result = $this->checker->check($force);
        return AuthController::json($response, $result);
    }
}
