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
 *
 * **Security.** The theme JSON is interpolated server-side into the
 * `<style id="tylio-theme">:root{…}</style>` block by `Renderer`.
 * Without sanitization, an authenticated admin could break the public
 * page by injecting CSS that closes the rule and adds arbitrary
 * declarations (e.g. `body { display: none }`). The
 * `sanitizeTheme()` whitelist below enforces shape + char-class on
 * every interpolated key. The same sanitizer also runs at render time
 * in `Renderer::themeCssVars()` as defense-in-depth.
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
        $theme = $this->sanitizeTheme($theme);
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

    /**
     * Sanitize the theme payload at the controller boundary. Returns a
     * NEW array containing only the recognized sections, with each
     * value coerced to a safe shape:
     *   - palette colors → hex/rgb/rgba/named (whitelist regex)
     *   - font heading/body → letters + digits + a few punctuation chars
     *   - tile.* numeric values → cast to int/float, clamped
     *   - background.image → URL string with quote stripping
     *
     * **Protected** so sub-classes (multi-tenant overlay) inherit the
     * pipeline without copy-pasting.
     *
     * @param array<string,mixed> $theme
     * @return array<string,mixed>
     */
    protected function sanitizeTheme(array $theme): array
    {
        $out = [];

        if (isset($theme['palette']) && is_array($theme['palette'])) {
            $palette = [];
            foreach ($theme['palette'] as $k => $v) {
                if (!is_string($k) || !preg_match('/^[a-z_][a-z0-9_]*$/i', $k)) continue;
                $color = is_string($v) ? trim($v) : '';
                if ($color === '' || self::isValidCssColor($color)) {
                    $palette[$k] = $color;
                }
            }
            $out['palette'] = $palette;
        }

        if (isset($theme['font']) && is_array($theme['font'])) {
            $font = [];
            foreach (['heading', 'body'] as $slot) {
                $v = $theme['font'][$slot] ?? null;
                if (is_string($v) && self::isValidFontFamily($v)) {
                    $font[$slot] = $v;
                }
            }
            $out['font'] = $font;
        }

        if (isset($theme['tile']) && is_array($theme['tile'])) {
            $tile = [];
            // Numeric geometry: clamp into a sane range so a malicious
            // payload can't push a 9999999 px into the CSS var.
            foreach (['radius', 'gap', 'border'] as $k) {
                if (isset($theme['tile'][$k]) && is_numeric($theme['tile'][$k])) {
                    $tile[$k] = max(0, min(200, (int)$theme['tile'][$k]));
                }
            }
            if (isset($theme['tile']['opacity']) && is_numeric($theme['tile']['opacity'])) {
                $tile['opacity'] = max(0.0, min(1.0, (float)$theme['tile']['opacity']));
            }
            if (isset($theme['tile']['tessellate']) && is_numeric($theme['tile']['tessellate'])) {
                $tile['tessellate'] = max(0.0, min(1.0, (float)$theme['tile']['tessellate']));
            }
            // String enums: lock to the documented set (admin SPA's
            // `Theme.vue` only emits these values; anything else is a
            // tamper attempt and gets dropped).
            $enums = [
                'style' => ['solid', 'transparent', 'glass'],
                'mobile_spacing' => ['desktop', 'minimal'],
                'shadow' => ['none', 'soft', 'medium', 'strong'],
            ];
            foreach ($enums as $k => $allowed) {
                if (isset($theme['tile'][$k]) && is_string($theme['tile'][$k])
                    && in_array($theme['tile'][$k], $allowed, true)) {
                    $tile[$k] = $theme['tile'][$k];
                }
            }
            $out['tile'] = $tile;
        }

        if (isset($theme['background']) && is_array($theme['background'])) {
            $bg = [];
            if (isset($theme['background']['image']) && is_string($theme['background']['image'])) {
                // strip quotes/parens that could escape `url('…')`
                $bg['image'] = preg_replace('/["\\\'()]/', '', $theme['background']['image']) ?? '';
            }
            if (isset($theme['background']['intensity']) && is_numeric($theme['background']['intensity'])) {
                $bg['intensity'] = max(0.0, min(1.0, (float)$theme['background']['intensity']));
            }
            // Pattern: locked to the documented set (admin SPA `Theme.vue`).
            $bgPatterns = ['none', 'dots', 'grid', 'lines-thin', 'lines-thick', 'mosaic', 'cubes', 'image'];
            if (isset($theme['background']['pattern']) && is_string($theme['background']['pattern'])
                && in_array($theme['background']['pattern'], $bgPatterns, true)) {
                $bg['pattern'] = $theme['background']['pattern'];
            }
            $out['background'] = $bg;
        }

        // Preserve any opt-in sections that the renderer doesn't
        // interpolate into CSS (e.g. flags, semantic-version markers).
        foreach ($theme as $k => $v) {
            if (in_array($k, ['palette', 'font', 'tile', 'background'], true)) continue;
            if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $k)) continue;
            // Only allow scalar or shallow arrays — no nested objects with
            // string values that could re-introduce the injection vector.
            if (is_scalar($v) || is_array($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * Accepts:
     *   - 3/4/6/8-digit hex (`#abc`, `#abcd`, `#aabbcc`, `#aabbccdd`)
     *   - `rgb(r, g, b)` / `rgba(r, g, b, a)` (only digits, dots, commas, %)
     *   - CSS named color (single-word alpha only)
     */
    public static function isValidCssColor(string $v): bool
    {
        $v = trim($v);
        if ($v === '') return false;
        if (preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v)) return true;
        if (preg_match('/^rgba?\(\s*[\d.,%\s\/\-]+\)$/', $v)) return true;
        if (preg_match('/^hsla?\(\s*[\d.,%\s\/\-]+\)$/', $v)) return true;
        if (preg_match('/^[a-zA-Z]{3,30}$/', $v)) return true;
        return false;
    }

    /**
     * Allows the printable subset that real font names can contain:
     * letters, digits, space, hyphen, comma, dot, underscore, apostrophe.
     * Length capped at 60 chars.
     */
    public static function isValidFontFamily(string $v): bool
    {
        $v = trim($v);
        return $v !== '' && strlen($v) <= 60 && (bool)preg_match("/^[A-Za-z0-9 .,_'\\-]+$/", $v);
    }
}
