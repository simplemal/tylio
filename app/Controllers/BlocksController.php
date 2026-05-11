<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Services\BlockRegistry;
use Tylio\Services\DB;
use Tylio\Services\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * CRUD for the tiles ("blocks") that compose the public home page. The
 * registry-driven schema means new tile types only need a row in
 * `BlockRegistry::definitions()` plus a template under
 * `Templates/blocks/` — no controller code change.
 *
 * **Extendable by design.** Non-`final` and `protected` dependencies so
 * sub-classes (e.g. the multi-tenant overlay) can scope every query by
 * `tenant_id` while reusing the registry / default-data / audit logic.
 */
class BlocksController
{
    public function __construct(
        protected DB $db,
        protected BlockRegistry $registry,
        protected I18n $i18n,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rows = $this->db->all('SELECT * FROM blocks ORDER BY position ASC, id ASC');
        return AuthController::json($response, ['blocks' => array_map(self::hydrate(...), $rows)]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $row = $this->db->one('SELECT * FROM blocks WHERE id = ?', [$args['id']]);
        if (!$row) return AuthController::json($response, ['error' => 'not_found'], 404);
        return AuthController::json($response, ['block' => self::hydrate($row)]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $type = (string)($body['type'] ?? '');
        if (!$this->registry->get($type)) {
            return AuthController::json($response, ['error' => 'invalid_type'], 422);
        }

        if ($type === 'footer') {
            $position = (int)$this->db->value('SELECT COALESCE(MAX(position), 0) + 10 FROM blocks');
        } else {
            $position = (int)$this->db->value(
                "SELECT COALESCE(MAX(position), 0) + 10 FROM blocks WHERE type != 'footer'"
            );
            $this->db->query(
                "UPDATE blocks SET position = position + 10 WHERE type = 'footer' AND position <= ?",
                [$position],
            );
        }

        if (isset($body['data']) && is_array($body['data'])) {
            $data = $body['data'];
        } else {
            // SPA didn't supply data (e.g. "Add tile" with no overrides):
            // pull defaults from the registry and resolve `blocks.*` keys
            // through I18n so the DB ends up with localized strings, not
            // translation keys.
            $this->i18n->setLocale($this->i18n->negotiate($request->getHeaderLine('Accept-Language')));
            $data = BlockRegistry::resolveStrings($this->registry->defaultData($type), $this->i18n);
        }
        $style = isset($body['style']) && is_array($body['style']) ? $body['style'] : [];

        $id = $this->db->insert('blocks', [
            'type' => $type,
            'position' => $position,
            'enabled' => 1,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'style' => json_encode($style, JSON_UNESCAPED_UNICODE),
        ]);
        $this->audit($request, 'block.create', "block:$id", ['type' => $type]);

        $row = $this->db->one('SELECT * FROM blocks WHERE id = ?', [$id]);
        return AuthController::json($response, ['block' => self::hydrate($row)], 201);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)$args['id'];
        $row = $this->db->one('SELECT * FROM blocks WHERE id = ?', [$id]);
        if (!$row) return AuthController::json($response, ['error' => 'not_found'], 404);

        $body = (array)$request->getParsedBody();
        $update = ['updated_at' => date('Y-m-d H:i:s')];
        if (isset($body['enabled'])) $update['enabled'] = $body['enabled'] ? 1 : 0;
        if (isset($body['data']) && is_array($body['data'])) $update['data'] = json_encode($body['data'], JSON_UNESCAPED_UNICODE);
        if (isset($body['style']) && is_array($body['style'])) $update['style'] = json_encode($body['style'], JSON_UNESCAPED_UNICODE);
        if (isset($body['position'])) $update['position'] = (int)$body['position'];

        $this->db->update('blocks', $update, 'id = :id', ['id' => $id]);
        $this->audit($request, 'block.update', "block:$id");
        $row = $this->db->one('SELECT * FROM blocks WHERE id = ?', [$id]);
        return AuthController::json($response, ['block' => self::hydrate($row)]);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)$args['id'];
        $this->db->query('DELETE FROM blocks WHERE id = ?', [$id]);
        $this->audit($request, 'block.delete', "block:$id");
        return AuthController::json($response, ['ok' => true]);
    }

    /**
     * Apply the source block's `data` + `style` to ALL blocks of the
     * same type. Used by the "Apply to all separators" button: it
     * uniforms the style across many blocks of the same type in one go.
     *
     * Whitelist `APPLY_TO_SAME_TYPE_ALLOWED`: only types where `data`
     * is "style/configuration" and NOT user-specific content. Dividers
     * are fine; hero/bio/products would be destructive (you'd wipe
     * distinct contents across multiple tiles).
     */
    private const APPLY_TO_SAME_TYPE_ALLOWED = ['divider'];

    public function applyToSameType(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)$args['id'];
        $source = $this->db->one('SELECT id, type FROM blocks WHERE id = ?', [$id]);
        if (!$source) return AuthController::json($response, ['error' => 'not_found'], 404);

        $type = (string)$source['type'];
        if (!in_array($type, self::APPLY_TO_SAME_TYPE_ALLOWED, true)) {
            return AuthController::json($response, ['error' => 'unsupported_type'], 422);
        }

        $body = (array)$request->getParsedBody();
        if (!isset($body['data']) || !is_array($body['data'])) {
            return AuthController::json($response, ['error' => 'invalid_data'], 422);
        }
        if (!isset($body['style']) || !is_array($body['style'])) {
            return AuthController::json($response, ['error' => 'invalid_style'], 422);
        }
        $data = $body['data'];
        $style = $body['style'];

        $count = 0;
        $this->db->transaction(function () use ($type, $data, $style, &$count) {
            $rows = $this->db->all('SELECT id FROM blocks WHERE type = ?', [$type]);
            $now = date('Y-m-d H:i:s');
            foreach ($rows as $row) {
                $this->db->update('blocks', [
                    'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    'style' => json_encode($style, JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                ], 'id = :id', ['id' => (int)$row['id']]);
                $count++;
            }
        });
        $this->audit($request, 'block.apply_to_same_type', "type:$type", ['count' => $count]);

        return AuthController::json($response, ['ok' => true, 'count' => $count, 'type' => $type]);
    }

    public function reorder(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $order = $body['order'] ?? [];
        if (!is_array($order)) return AuthController::json($response, ['error' => 'invalid_order'], 422);

        $this->db->transaction(function () use ($order) {
            $pos = 10;
            foreach ($order as $id) {
                $this->db->update('blocks', ['position' => $pos], 'id = :id', ['id' => (int)$id]);
                $pos += 10;
            }
        });
        $this->audit($request, 'block.reorder');
        return AuthController::json($response, ['ok' => true]);
    }

    protected function audit(ServerRequestInterface $request, string $action, ?string $resource = null, array $meta = []): void
    {
        $user = $request->getAttribute('user');
        $params = $request->getServerParams();
        $this->db->insert('audit_log', [
            'user_id' => $user['id'] ?? null,
            'action' => $action,
            'resource' => $resource,
            'metadata' => $meta ? json_encode($meta) : null,
            'ip' => (string)($params['REMOTE_ADDR'] ?? ''),
        ]);
    }

    protected static function hydrate(array $row): array
    {
        $row['data'] = json_decode($row['data'] ?? '{}', true) ?: new \stdClass();
        $row['style'] = json_decode($row['style'] ?? '{}', true) ?: new \stdClass();
        $row['enabled'] = (bool)$row['enabled'];
        $row['position'] = (int)$row['position'];
        $row['id'] = (int)$row['id'];
        return $row;
    }
}
