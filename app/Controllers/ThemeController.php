<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Services\DB;
use Tylio\Services\Renderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Theme palette / font / tile-style / background CRUD. The full theme
 * object is a single JSON column on the `theme` table.
 *
 * **Extendable by design.** Non-`final`; sub-classes (multi-tenant
 * overlay) can swap the storage scope from a single global row to a
 * `tenant_id`-keyed row.
 */
class ThemeController
{
    public function __construct(protected DB $db, protected Renderer $renderer) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return AuthController::json($response, ['theme' => $this->renderer->loadTheme()]);
    }

    public function publicTheme(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return AuthController::json($response, ['theme' => $this->renderer->loadTheme()]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $theme = $body['theme'] ?? null;
        if (!is_array($theme)) {
            return AuthController::json($response, ['error' => 'invalid_theme'], 422);
        }
        $json = json_encode($theme, JSON_UNESCAPED_UNICODE);
        $this->db->pdo()->prepare(
            "INSERT OR REPLACE INTO theme (id, data, updated_at) VALUES (1, ?, datetime('now'))"
        )->execute([$json]);

        $user = $request->getAttribute('user');
        $params = $request->getServerParams();
        $this->db->insert('audit_log', [
            'user_id' => $user['id'] ?? null,
            'action' => 'theme.update',
            'ip' => (string)($params['REMOTE_ADDR'] ?? ''),
        ]);
        return AuthController::json($response, ['theme' => $theme]);
    }
}
