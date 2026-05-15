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

        // Optional parent_id: place this block inside an existing group.
        // Validation: parent must exist and have type='group'; you cannot
        // nest a group inside another group (no recursion in the planner).
        $parentId = $this->resolveParentId($body);
        if ($parentId === false) {
            return AuthController::json($response, ['error' => 'invalid_parent'], 422);
        }
        if ($parentId !== null && $type === 'group') {
            return AuthController::json($response, ['error' => 'nested_groups_not_allowed'], 422);
        }

        $position = $this->nextPosition($type, $parentId);

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
            'parent_id' => $parentId,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'style' => json_encode($style, JSON_UNESCAPED_UNICODE),
        ]);
        $this->audit($request, 'block.create', "block:$id", ['type' => $type]);

        $row = $this->db->one('SELECT * FROM blocks WHERE id = ?', [$id]);
        return AuthController::json($response, ['block' => self::hydrate($row)], 201);
    }

    /**
     * Read + validate `parent_id` from a request body.
     *
     * Returns:
     *   - `null` when absent (or explicitly null/empty) → block stays top-level
     *   - the integer id when a valid group block exists with that id
     *   - `false` when the body sends a non-null parent_id that doesn't map to a
     *     group → caller turns this into a 422
     *
     * `$tenantId` scopes the parent lookup to a single tenant on the
     * SaaS overlay; OSS leaves it `null` (any block in the global db).
     */
    protected function resolveParentId(array $body, ?int $tenantId = null): int|null|false
    {
        if (!array_key_exists('parent_id', $body)) return null;
        $raw = $body['parent_id'];
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') return null;
        if (!is_numeric($raw)) return false;
        $pid = (int)$raw;
        if ($tenantId === null) {
            $parent = $this->db->one('SELECT id, type FROM blocks WHERE id = ?', [$pid]);
        } else {
            $parent = $this->db->one(
                'SELECT id, type FROM blocks WHERE id = ? AND tenant_id = ?',
                [$pid, $tenantId]
            );
        }
        if (!$parent) return false;
        if (($parent['type'] ?? '') !== 'group') return false;
        return $pid;
    }

    /**
     * Pick the position for a brand-new block in its parent scope.
     *
     * Footer is a "structural" pin-to-bottom block: always pushed past
     * the maximum existing position, and other newly-created top-level
     * blocks slide above any existing footers so the footer stays last
     * in the mosaic.
     *
     * Block inside a group: position is scoped to that group (the
     * footer rules don't apply — groups don't contain footers in
     * practice, and even if they did the layout planner doesn't
     * special-case them inside groups).
     *
     * `$tenantId` scopes every read/write to one tenant on the SaaS
     * overlay; OSS leaves it `null` (global namespace).
     */
    protected function nextPosition(string $type, ?int $parentId, ?int $tenantId = null): int
    {
        if ($parentId !== null) {
            if ($tenantId === null) {
                return (int)$this->db->value(
                    'SELECT COALESCE(MAX(position), 0) + 10 FROM blocks WHERE parent_id = ?',
                    [$parentId]
                );
            }
            return (int)$this->db->value(
                'SELECT COALESCE(MAX(position), 0) + 10 FROM blocks WHERE parent_id = ? AND tenant_id = ?',
                [$parentId, $tenantId]
            );
        }
        if ($type === 'footer') {
            if ($tenantId === null) {
                return (int)$this->db->value(
                    'SELECT COALESCE(MAX(position), 0) + 10 FROM blocks WHERE parent_id IS NULL'
                );
            }
            return (int)$this->db->value(
                'SELECT COALESCE(MAX(position), 0) + 10 FROM blocks WHERE parent_id IS NULL AND tenant_id = ?',
                [$tenantId]
            );
        }
        if ($tenantId === null) {
            $position = (int)$this->db->value(
                "SELECT COALESCE(MAX(position), 0) + 10 FROM blocks WHERE parent_id IS NULL AND type != 'footer'"
            );
            $this->db->query(
                "UPDATE blocks SET position = position + 10 WHERE parent_id IS NULL AND type = 'footer' AND position <= ?",
                [$position],
            );
        } else {
            $position = (int)$this->db->value(
                "SELECT COALESCE(MAX(position), 0) + 10 FROM blocks WHERE parent_id IS NULL AND tenant_id = ? AND type != 'footer'",
                [$tenantId]
            );
            $this->db->query(
                "UPDATE blocks SET position = position + 10 WHERE parent_id IS NULL AND tenant_id = ? AND type = 'footer' AND position <= ?",
                [$tenantId, $position],
            );
        }
        return $position;
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

        // Optional parent_id change: drag the block into / out of a
        // group. Validation mirrors create(); a group can't be nested
        // under another group. When the parent actually changes, we
        // also append the block at the end of the new parent's scope
        // (so reorder isn't necessary as a follow-up call).
        if (array_key_exists('parent_id', $body)) {
            $newParent = $this->resolveParentId($body);
            if ($newParent === false) {
                return AuthController::json($response, ['error' => 'invalid_parent'], 422);
            }
            $currentType = (string)($row['type'] ?? '');
            if ($newParent !== null && $currentType === 'group') {
                return AuthController::json($response, ['error' => 'nested_groups_not_allowed'], 422);
            }
            $currentParent = !empty($row['parent_id']) ? (int)$row['parent_id'] : null;
            if ($newParent !== $currentParent) {
                $update['parent_id'] = $newParent;
                if (!isset($body['position'])) {
                    $update['position'] = $this->nextPosition($currentType, $newParent);
                }
            }
        }

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

        // Scope: the order list is implicitly a reorder within a single
        // parent_id namespace (top-level OR inside one group). We don't
        // care which here — we just spread positions 10, 20, 30, …
        // across the ids in `$order`. The SPA sends one call per
        // affected parent on a drag-drop interaction.
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
        // parent_id may be NULL (top-level), an int (inside a group), or
        // missing on rows from a DB that predates migration 0008. Surface
        // it as int|null to the SPA so the dashboard can group accordingly.
        $row['parent_id'] = (isset($row['parent_id']) && $row['parent_id'] !== null && $row['parent_id'] !== 0)
            ? (int)$row['parent_id']
            : null;
        return $row;
    }
}
