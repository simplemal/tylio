<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Config;
use Tylio\Services\DB;
use Tylio\Services\Renderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PageController
{
    public function __construct(
        protected DB $db,
        protected Renderer $renderer,
        protected Config $config,
    ) {}

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->trackVisit($request, null);
        $html = $this->renderer->renderPage();
        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=60');
    }

    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = $request->getQueryParams();
        $onlyId = isset($q['only']) && ctype_digit((string)$q['only']) ? (int)$q['only'] : null;
        $includeDisabled = ($q['include'] ?? '') === 'disabled';
        $html = $this->renderer->renderPage($includeDisabled, $onlyId);
        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function adminShell(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $shellPath = $this->config->path('admin/index.html');
        if (!file_exists($shellPath)) {
            // SPA not built: in PROD return a generic 503 (no internal
            // structure leaked). In DEBUG show the build instructions as a
            // hint for whoever is installing OSS locally.
            $isDebug = $this->config->bool('APP_DEBUG', false);
            $body = $isDebug ? $this->placeholderShell() : $this->serviceUnavailableShell();
            $response->getBody()->write($body);
            return $response
                ->withStatus(503)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Retry-After', '600');
        }
        $response->getBody()->write((string)file_get_contents($shellPath));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function sitemap(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $url = $this->config->appUrl() ?: ('https://' . $request->getUri()->getHost());
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
             . '<urlset xmlns="http://www.sitemaps.org/schemas/0.9">'
             . '<url><loc>' . htmlspecialchars($url . '/', ENT_XML1) . '</loc>'
             . '<changefreq>weekly</changefreq><priority>1.0</priority></url>'
             . '</urlset>';
        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    public function robots(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $url = $this->config->appUrl() ?: ('https://' . $request->getUri()->getHost());
        $settings = $this->renderer->loadSettings();
        $indexable = $settings['seo.robots_index'] ?? true;
        $body = $indexable
            ? "User-agent: *\nDisallow: /admin\nDisallow: /api\nSitemap: {$url}/sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n";
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    public function manifest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $settings = $this->renderer->loadSettings();
        $theme = $this->renderer->loadTheme();
        $faviconVersion = (string)($settings['seo.favicon'] ?? '');
        $hasFavicon = $faviconVersion !== '' && file_exists($this->config->path('favicons/icon-192.png'));

        $icons = [];
        if ($hasFavicon) {
            $icons = [
                ['src' => "/favicons/icon-192.png?v=$faviconVersion", 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => "/favicons/icon-512.png?v=$faviconVersion", 'sizes' => '512x512', 'type' => 'image/png'],
            ];
        }
        $manifest = [
            'name' => $settings['site.title'] ?? 'tylio',
            'short_name' => $settings['site.title'] ?? 'tylio',
            'description' => $settings['site.description'] ?? '',
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => $theme['palette']['bg'] ?? '#0f0d0a',
            'theme_color' => $theme['palette']['accent'] ?? '#d4a574',
            'icons' => $icons,
        ];
        $response->getBody()->write(json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/manifest+json');
    }

    /**
     * Anonymous visit tracking (privacy-first, no GDPR-relevant data).
     *
     * We store ONLY:
     *   - day        (for per-day stats)
     *   - block_id   (for per-tile stats)
     *
     * We do NOT store: IP, user_agent, referer, fingerprint, exact
     * timestamp. The counter is just "someone hit this page N times
     * today" — no unique users, no session. Bots are filtered via
     * user-agent, read inline and NOT persisted.
     */
    private function trackVisit(ServerRequestInterface $request, ?int $blockId): void
    {
        $ua = (string)$request->getHeaderLine('User-Agent');
        if ($ua === '' || preg_match('/bot|crawler|spider|preview|monitor/i', $ua)) return;
        $this->db->insert('visits', [
            'day' => date('Y-m-d'),
            'block_id' => $blockId,
        ]);
    }

    /**
     * Public endpoint (no auth, no CSRF) hit via `navigator.sendBeacon`
     * from the public layout when the user clicks a link inside a tile.
     * Writes to `visits` with `block_id` set — the stats panel aggregates
     * from here for "most-clicked tiles".
     */
    public function trackClick(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        // sendBeacon sends JSON in the raw body, not form-encoded.
        if (empty($body)) {
            $raw = (string)$request->getBody();
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $body = $decoded;
            }
        }
        $blockId = (int)($body['block_id'] ?? 0);
        if ($blockId <= 0) return $response->withStatus(400);

        // Anti-spoofing: the block must exist (single-user install — no
        // tenant scoping, just a valid id).
        $exists = $this->db->one('SELECT 1 FROM blocks WHERE id = ?', [$blockId]);
        if (!$exists) return $response->withStatus(404);

        $this->trackVisit($request, $blockId);
        return $response->withStatus(204);
    }

    /**
     * "SPA not built" screen shown ONLY when APP_DEBUG=true, intended for
     * local dev. In production we use serviceUnavailableShell() so the
     * project's internal structure isn't exposed.
     */
    private function placeholderShell(): string
    {
        $admin = htmlspecialchars($this->config->adminPath());
        return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>tylio admin · dev</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<style>body{margin:0;font:16px system-ui;background:#0f0d0a;color:#f4ede1;display:grid;place-items:center;min-height:100vh}
.box{max-width:520px;padding:32px;border:1px solid #2a221b;border-radius:18px;background:#1a1612;text-align:center}
code{background:#0f0d0a;padding:2px 8px;border-radius:6px;color:#d4a574}
</style></head><body><div class="box">
<h1>Admin SPA not built yet</h1>
<p>(visible because APP_DEBUG=true)</p>
<p>Run from the project root:</p>
<pre><code>cd admin-src && npm install && npm run build</code></pre>
<p>Then reload <code>{$admin}</code>.</p>
</div></body></html>
HTML;
    }

    /**
     * Generic 503 screen: NO internal details (paths, commands, structure).
     * Shown in production when the admin SPA is not available.
     */
    private function serviceUnavailableShell(): string
    {
        return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Service unavailable</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<style>body{margin:0;font:16px system-ui;background:#0f0d0a;color:#f4ede1;display:grid;place-items:center;min-height:100vh}
.box{max-width:480px;padding:32px;border:1px solid rgba(244,237,225,0.08);border-radius:18px;background:#1a1612;text-align:center}
.box h1{margin:0 0 12px;font-size:20px;font-weight:600}
.box p{margin:0;color:#9c8e7c;font-size:14px;line-height:1.5}
</style></head><body><div class="box">
<h1>Service temporarily unavailable</h1>
<p>We're performing maintenance. Please try again in a few minutes.</p>
</div></body></html>
HTML;
    }
}
