<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Config;
use Tylio\Services\Auth;
use Tylio\Services\DB;
use Tylio\Services\Renderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public-facing routes: home page, sitemap, robots, manifest, click
 * tracking, plus the admin SPA shell at `/admin`. Reads pages through
 * the `Renderer` service (which sub-classes can swap to a tenant-aware
 * variant).
 *
 * **Extendable by design.** Non-`final` and `protected` dependencies
 * so the multi-tenant overlay can render per-tenant pages by overriding
 * `home()`/`preview()` with tenant-scoped data loading.
 */
class PageController
{
    public function __construct(
        protected DB $db,
        protected Renderer $renderer,
        protected Config $config,
        protected Auth $auth,
    ) {}

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->installPending()) {
            $response->getBody()->write($this->renderInstallWelcome());
            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store');
        }
        // Maintenance mode flow:
        //  - visitors → renderMaintenance() + HTTP 503
        //  - admin (logged in) → renderPage() with an injected banner at
        //    the top that says "site is offline to visitors, only you
        //    see this" with a link back to Settings → Manutenzione.
        // We do NOT track admin maintenance hits — they would skew the
        // stats panel.
        $maintenanceOn = $this->isMaintenanceOn();
        $adminLogged = $maintenanceOn && $this->isAdminLogged($request);
        if ($maintenanceOn && !$adminLogged) {
            $accept = (string)$request->getHeaderLine('Accept-Language');
            $html = $this->renderer->renderMaintenance($accept);
            $response->getBody()->write($html);
            return $response
                ->withStatus(503)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Retry-After', '600');
        }
        $this->trackVisit($request, null);
        // When the admin is previewing during maintenance, ask the
        // renderer to inject the "you're seeing this, visitors are
        // blocked" banner. Cache-Control becomes no-store so we don't
        // accidentally serve the admin-only banner to anonymous CDN
        // hits later (Cloudflare keys cache on URL, not session).
        if ($adminLogged) {
            $html = $this->renderer->renderPage(false, null, [], true);
            $response->getBody()->write($html);
            return $response
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store');
        }
        $html = $this->renderer->renderPage();
        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=60');
    }

    /**
     * Did the install wizard run yet? `users` table either doesn't exist
     * (migrations have never run) or is empty (no admin created yet, no
     * archive imported). Either way the site has nothing to show and
     * the visitor needs to be sent to `/install`.
     */
    protected function installPending(): bool
    {
        try {
            $row = $this->db->one('SELECT COUNT(*) AS n FROM users');
            return !$row || (int)($row['n'] ?? 0) === 0;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Self-contained HTML for the "site not installed yet" landing page.
     * Inline CSS only — no static assets needed (which might not be
     * served yet on a botched install). Logo is the same SVG used by
     * the admin login.
     */
    protected function renderInstallWelcome(): string
    {
        $installUrl = '/install';
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>tylio — setup pending</title>
<style>
  :root { color-scheme: dark; }
  html, body { margin: 0; height: 100%; }
  body {
    background: radial-gradient(circle at top, #232743 0%, #14161f 70%);
    color: #f1f3fa;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
    display: grid; place-items: center; padding: 24px;
  }
  .card {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 18px;
    padding: 40px 36px;
    max-width: 460px;
    text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,0.45);
  }
  .logo {
    width: 64px; height: 64px; margin: 0 auto 18px;
    background: linear-gradient(135deg, #ff5fbf, #6a4cff);
    border-radius: 16px;
    display: grid; place-items: center;
    box-shadow: 0 10px 30px rgba(106,76,255,0.4);
  }
  .logo span {
    font-weight: 700; font-size: 32px; color: #fff; letter-spacing: -0.04em;
  }
  h1 { margin: 0 0 8px; font-size: 22px; font-weight: 600; }
  p { margin: 0 0 24px; color: #aab0c8; line-height: 1.55; font-size: 15px; }
  a.cta {
    display: inline-block; padding: 12px 24px;
    background: linear-gradient(135deg, #ff5fbf, #6a4cff);
    color: #fff; text-decoration: none; border-radius: 999px;
    font-weight: 600; font-size: 15px;
    box-shadow: 0 8px 24px rgba(106,76,255,0.35);
    transition: transform 0.15s ease;
  }
  a.cta:hover { transform: translateY(-1px); }
</style>
</head>
<body>
  <main class="card">
    <div class="logo" aria-hidden="true"><span>t</span></div>
    <h1>tylio è installato ma non configurato</h1>
    <p>Completa il setup creando l'utente admin o importando un archivio di un sito esistente.</p>
    <a class="cta" href="$installUrl">Avvia l'installazione</a>
  </main>
</body>
</html>
HTML;
    }

    /**
     * Reads `settings['site.maintenance']` directly (one tiny SQL hit).
     * Kept as a single-purpose query rather than going through
     * `Renderer::loadSettings()` — the maintenance path must stay light
     * even when the DB is under heavy contention. Returns false on any
     * error (fail-open: better to serve the site than to 503 because
     * of a settings row glitch).
     */
    protected function isMaintenanceOn(): bool
    {
        try {
            $row = $this->db->one(
                'SELECT value FROM settings WHERE key = ?',
                ['site.maintenance'],
            );
            if (!$row) return false;
            $decoded = json_decode((string)$row['value'], true);
            return $decoded === true || $decoded === 1 || $decoded === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Returns true if the request carries a valid (non-pending) admin
     * session cookie. Used to short-circuit maintenance mode so the
     * admin can keep working on the live site.
     */
    protected function isAdminLogged(ServerRequestInterface $request): bool
    {
        try {
            return $this->auth->loadFromRequest($request);
        } catch (\Throwable) {
            return false;
        }
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
