<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Services\BlockRegistry;
use Tylio\Services\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/types — returns the block-type schema consumed by the admin SPA
 * (the "Add tile" sheet, the field editor, …).
 *
 * `BlockRegistry` keeps its user-facing strings as `blocks.*` translation
 * keys; this controller is the seam that resolves them against the active
 * locale before the JSON leaves the server. The locale is negotiated from
 * the request's `Accept-Language` header so the SPA gets the right language
 * out of the box without round-tripping.
 */
final class TypesController
{
    public function __construct(
        private BlockRegistry $registry,
        private I18n $i18n,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->i18n->setLocale($this->i18n->negotiate($request->getHeaderLine('Accept-Language')));
        $resolved = BlockRegistry::resolveStrings($this->registry->all(), $this->i18n);
        return AuthController::json($response, ['types' => array_values($resolved)]);
    }
}
