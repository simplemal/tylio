<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Config;
use Tylio\Services\Auth;
use Tylio\Services\DB;
use Tylio\Services\Migrations;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One-shot web endpoint for the initial setup (auto-disabled afterwards).
 * Exists because deploys via SFTP often can't run CLI commands on the server.
 */
final class InstallController
{
    public function __construct(
        private DB $db,
        private Auth $auth,
        private Config $config,
    ) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->ensureMigrationsRun();
        if ($this->hasUser()) {
            return $this->html($response, $this->lockedPage());
        }
        // Friendly warning: if the admin SPA bundle is missing, show
        // instructions on the install page. The public home already works;
        // only /admin is unreachable until `npm run build` is done.
        $adminMissing = !$this->adminShellBuilt();
        $preferredLocale = $this->detectPreferredLocale($request);
        return $this->html($response, $this->setupForm(null, '', $adminMissing, '', $preferredLocale));
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->ensureMigrationsRun();
        if ($this->hasUser()) {
            return $this->html($response->withStatus(403), $this->lockedPage());
        }
        $body = (array)$request->getParsedBody();
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $confirm = (string)($body['password2'] ?? '');
        $siteTitle = trim((string)($body['site_title'] ?? ''));
        $locale = strtolower(trim((string)($body['locale'] ?? '')));
        $systemTheme = (string)($body['system_theme'] ?? '');

        $err = null;
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
            $err = 'Username must be 3-32 chars (letters, digits, _ . -).';
        } elseif (strlen($password) < 10) {
            $err = 'Password must be at least 10 characters.';
        } elseif ($password !== $confirm) {
            $err = 'Passwords do not match.';
        } elseif ($siteTitle !== '' && mb_strlen($siteTitle) > 80) {
            // Same upper bound enforced by Settings.vue's site title field.
            $err = 'Site title is too long (max 80 characters).';
        } elseif ($locale !== '' && !in_array($locale, ['it', 'en'], true)) {
            // Defense-in-depth: the form only ever submits 'it' or 'en'
            // (radio inputs). A different value here is a tamper attempt.
            $err = 'Invalid language choice.';
        }
        if ($err) {
            return $this->html($response->withStatus(422), $this->setupForm($err, $username, false, $siteTitle, $locale));
        }

        $hash = $this->auth->hashPassword($password);
        $this->db->insert('users', ['username' => $username, 'password_hash' => $hash]);

        // Apply install-time preferences:
        //  - site.title  → overrides the default seed title (only if provided)
        //  - site.locale → locks the public site to this language. If empty,
        //                  the renderer falls back to Accept-Language
        //                  negotiation (this is the documented OSS default).
        $this->applySiteTitle($siteTitle);
        $this->applySiteLocale($locale);

        // Apply install-time theme: Nordic, light or dark based on the
        // visitor's system theme (auto-detected by the form's JS via
        // `prefers-color-scheme`, no dropdown to avoid showing the user
        // 18 swatches without a preview). They can swap palette anytime
        // later from Theme → Presets, this is just a sensible default
        // that respects their system at the moment of install.
        $this->applyNordicTheme($systemTheme === 'dark' ? 'dark' : 'light');

        // Seed sample blocks if the page is empty
        if ((int)$this->db->value('SELECT COUNT(*) FROM blocks') === 0) {
            $this->seedSample();
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', $this->config->adminPath() . '/');
    }

    /**
     * Overwrite `settings.site.title` only when the user supplied a
     * value at install time. Otherwise we keep whatever the migration
     * seeded so old installs aren't surprised by a missing key.
     */
    private function applySiteTitle(string $title): void
    {
        if ($title === '') return;
        $this->db->pdo()->prepare(
            "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))"
        )->execute(['site.title', json_encode($title, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Overwrite `settings.site.locale` only when the user picked one at
     * install time. Empty value preserves the seed's empty string, which
     * means "negotiate per visitor from Accept-Language" — same default
     * documented in the 0001 migration.
     */
    private function applySiteLocale(string $locale): void
    {
        if ($locale === '') return;
        $this->db->pdo()->prepare(
            "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))"
        )->execute(['site.locale', json_encode($locale, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Replace the seeded `theme.palette` with Nordic (light or dark).
     * Other theme sections (font, tile, background, mode) are kept from
     * the 0001 seed so the install stays a single coherent default
     * theme, just with the Nordic palette swapped in.
     *
     * Palette values mirror `admin-src/src/presets.ts` exactly — keep
     * in sync if a palette is rebalanced.
     */
    private function applyNordicTheme(string $mode): void
    {
        $nordicLight = [
            'name' => 'nordic-light',
            'bg' => '#e5e9f0',
            'surface' => '#ffffff',
            'surface_alt' => '#efeff1',
            'text' => '#2e3440',
            'text_muted' => '#59718b',
            'accent' => '#5e81ac',
            'accent_alt' => '#8db9c7',
            'accent_soft' => '#f5faff',
            'accent_alt_fg' => '#ffffff',
            'border' => 'rgba(46,52,64,0.10)',
        ];
        $nordicDark = [
            'name' => 'nordic-dark',
            'bg' => '#2e3440',
            'surface' => '#3b4252',
            'surface_alt' => '#434c5e',
            'text' => '#eceff4',
            'text_muted' => '#d8dee9',
            'accent' => '#d8dee9',
            'accent_alt' => '#88c0d0',
            'accent_soft' => '#3b4252',
            'accent_alt_fg' => '#2e3440',
            'border' => 'rgba(216,222,233,0.10)',
        ];
        $palette = $mode === 'dark' ? $nordicDark : $nordicLight;

        $row = $this->db->one('SELECT data FROM theme WHERE id = 1');
        $theme = $row ? (json_decode((string)$row['data'], true) ?: []) : [];
        $theme['palette'] = $palette;
        // `mode` mirrors the preset's light/dark hint; the renderer uses
        // this for `<meta name="color-scheme">` and the public CSS hooks.
        $theme['mode'] = $mode === 'dark' ? 'dark' : 'light';

        $this->db->pdo()->prepare(
            "INSERT OR REPLACE INTO theme (id, data, updated_at) VALUES (1, ?, datetime('now'))"
        )->execute([json_encode($theme, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Pre-select the language radio based on `Accept-Language`. We only
     * support `it` and `en` natively — anything else falls back to
     * English. The user can still flip the radio before submitting.
     */
    private function detectPreferredLocale(ServerRequestInterface $request): string
    {
        $header = strtolower($request->getHeaderLine('Accept-Language'));
        // Crude but enough for the install screen: peek at the first
        // language tag's primary subtag.
        if (preg_match('/^\s*([a-z]{2})\b/', $header, $m)) {
            $tag = $m[1];
            if ($tag === 'it') return 'it';
        }
        return 'en';
    }

    private function ensureMigrationsRun(): void
    {
        try {
            (new Migrations($this->db, $this->config))->run();
        } catch (\Throwable $e) {
            // bubble up nicely below
            throw $e;
        }
    }

    private function hasUser(): bool
    {
        try {
            return (int)$this->db->value('SELECT COUNT(*) FROM users') > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check whether the admin SPA bundle has been built. Used to display a
     * helpful warning on the /install page when the user has cloned the
     * repo but hasn't yet run `cd admin-src && npm install && npm run build`.
     */
    private function adminShellBuilt(): bool
    {
        return file_exists($this->config->path('admin/index.html'));
    }

    private function seedSample(): void
    {
        $samples = [
            ['type' => 'hero', 'data' => [
                'title' => 'Hi, this is your home.',
                'subtitle' => "One tile at a time.\nBuilt with tylio — modular, yours, mobile-first.",
                'cta_label' => 'Explore',
                'cta_url' => '#',
            ]],
            ['type' => 'links', 'data' => [
                'title' => 'Quick links',
                'items' => [
                    ['label' => 'Main site', 'url' => 'https://example.com', 'icon' => 'lucide:globe', 'description' => 'My place on the web'],
                    ['label' => 'Newsletter', 'url' => 'https://example.com/newsletter', 'icon' => 'lucide:mail', 'badge' => 'new'],
                ],
            ]],
            ['type' => 'social', 'data' => [
                'title' => 'Social',
                'items' => [
                    ['platform' => 'github', 'url' => 'https://github.com/'],
                    ['platform' => 'instagram', 'url' => 'https://instagram.com/'],
                    ['platform' => 'youtube', 'url' => 'https://youtube.com/'],
                ],
            ]],
            ['type' => 'apps', 'data' => [
                'title' => 'Projects',
                'subtitle' => 'Things I make.',
                'columns' => '3',
                'items' => [
                    ['name' => 'Project One', 'tagline' => 'Short tagline.', 'description' => 'Describe what it does.', 'url' => 'https://example.com/one', 'tag' => 'web', 'accent' => '#9bb6ff'],
                    ['name' => 'Project Two', 'tagline' => 'Short tagline.', 'description' => 'Describe what it does.', 'url' => 'https://example.com/two', 'tag' => 'open source', 'accent' => '#a5e6c5'],
                    ['name' => 'Project Three', 'tagline' => 'Short tagline.', 'description' => 'Describe what it does.', 'url' => 'https://example.com/three', 'tag' => 'tools', 'accent' => '#ffb8a3'],
                ],
            ]],
            ['type' => 'footer', 'data' => [
                'text' => '© ' . date('Y'),
                'show_powered_by' => true,
                'links' => [],
            ]],
        ];
        $pos = 10;
        foreach ($samples as $s) {
            $this->db->insert('blocks', [
                'type' => $s['type'],
                'position' => $pos,
                'enabled' => 1,
                'data' => json_encode($s['data'], JSON_UNESCAPED_UNICODE),
                'style' => '{}',
            ]);
            $pos += 10;
        }
    }

    private function html(ResponseInterface $response, string $body): ResponseInterface
    {
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function setupForm(
        ?string $err = null,
        string $username = '',
        bool $adminMissing = false,
        string $siteTitle = '',
        string $preferredLocale = 'en'
    ): string {
        $errHtml = $err ? '<p class="err">' . htmlspecialchars($err) . '</p>' : '';
        $u = htmlspecialchars($username);
        $title = htmlspecialchars($siteTitle);
        $admin = htmlspecialchars($this->config->adminPath());
        $itChecked = $preferredLocale === 'it' ? ' checked' : '';
        $enChecked = $preferredLocale !== 'it' ? ' checked' : '';
        // Instruction banner if the admin SPA bundle hasn't been built yet.
        // It's a warning, not a block: the public home works regardless;
        // only /admin returns 503 until npm run build is done.
        $warnHtml = $adminMissing ? <<<HTML
<div class="warn">
  <strong>⚠️ Admin bundle not built.</strong>
  <p>You can still proceed with the setup, but <code>$admin</code> won't be reachable
  until you build the SPA. From the project root:</p>
  <pre>cd admin-src
npm install
npm run build</pre>
</div>
HTML : '';
        // The hidden `system_theme` field is filled in by the inline JS
        // below from `prefers-color-scheme`. It locks Nordic light/dark
        // at install time; users with JS off get Nordic light (the
        // default empty string falls through to light in the controller).
        return $this->wrap('Initial setup', <<<HTML
<h1>Welcome to <em>tylio</em></h1>
<p class="muted">Set up your site. After this you won't be able to access this page anymore.</p>
$warnHtml
$errHtml
<form method="post" action="">
  <label>Site title <span class="optional">(optional)</span>
    <input name="site_title" value="$title" maxlength="80" placeholder="e.g. Maurizio Natali">
  </label>
  <fieldset class="radio-group">
    <legend>Site language</legend>
    <label class="radio">
      <input type="radio" name="locale" value="it"$itChecked> Italian
    </label>
    <label class="radio">
      <input type="radio" name="locale" value="en"$enChecked> English
    </label>
    <p class="hint">Used for the public site's UI strings. You can change it later in Settings.</p>
  </fieldset>
  <label>Username
    <input name="username" value="$u" autocomplete="off" required minlength="3" maxlength="32" pattern="[a-zA-Z0-9_.\-]+">
  </label>
  <label>Password (at least 10 characters)
    <input name="password" type="password" required minlength="10">
  </label>
  <label>Repeat password
    <input name="password2" type="password" required minlength="10">
  </label>
  <input type="hidden" name="system_theme" id="system_theme" value="">
  <button type="submit">Create admin</button>
</form>
<p class="footer">Once the admin is created you'll be redirected to <code>$admin</code>.</p>
<script>
  // Locks the Nordic palette to light or dark based on the user's
  // current system theme. We do this server-side (one shot, then it
  // stays put even if the user later flips their OS theme) — the user
  // can swap palette anytime from Theme → Presets in the admin.
  try {
    var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.getElementById('system_theme').value = dark ? 'dark' : 'light';
  } catch (e) { /* JS-off: server falls back to light */ }
</script>
HTML);
    }

    private function lockedPage(): string
    {
        $admin = htmlspecialchars($this->config->adminPath());
        return $this->wrap('Already installed', <<<HTML
<h1>tylio is already installed</h1>
<p class="muted">To sign in go to <a href="$admin">$admin</a>.</p>
<p class="footer">To reset the password without admin access, delete <code>data/db.sqlite</code> on the server and reload this page.</p>
HTML);
    }

    private function wrap(string $title, string $body): string
    {
        $titleE = htmlspecialchars($title);
        return <<<HTML
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><title>$titleE · tylio</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  body{margin:0;font:16px Inter,system-ui,sans-serif;background:#0f0d0a;color:#f4ede1;display:grid;place-items:center;min-height:100vh;padding:24px;
    background-image:radial-gradient(700px 500px at 100% -10%, rgba(212,165,116,.18), transparent 60%),radial-gradient(600px 500px at -10% 110%, rgba(232,197,152,.12), transparent 60%);}
  .box{max-width:480px;width:100%;background:#1a1612;border:1px solid rgba(244,237,225,.08);border-radius:18px;padding:28px;box-shadow:0 30px 60px -40px rgba(0,0,0,.6)}
  h1{font-family:Fraunces,serif;margin:0 0 8px;font-size:28px;font-weight:600;letter-spacing:-.01em}
  em{font-style:normal;color:#d4a574}
  .muted{color:#9c8e7c;margin:0 0 16px}
  label{display:block;font-size:13px;color:#9c8e7c;margin:14px 0 0}
  input{width:100%;background:#221c17;border:1px solid rgba(244,237,225,.08);border-radius:12px;padding:12px;color:#f4ede1;margin-top:6px;font-family:inherit;box-sizing:border-box}
  input:focus{outline:none;border-color:#d4a574}
  button{margin-top:18px;width:100%;background:#d4a574;color:#1a1410;border:0;padding:13px;border-radius:999px;font:600 15px Inter,sans-serif;cursor:pointer;transition:background .2s}
  button:hover{background:#e8c598}
  .optional{color:#9c8e7c;font-weight:400}
  fieldset.radio-group{border:0;padding:0;margin:14px 0 0}
  fieldset.radio-group legend{font-size:13px;color:#9c8e7c;margin-bottom:6px;padding:0}
  fieldset.radio-group .radio{display:inline-flex;align-items:center;gap:8px;margin-right:18px;color:#f4ede1;font-size:14px}
  fieldset.radio-group .radio input{width:auto;margin:0;accent-color:#d4a574}
  fieldset.radio-group .hint{margin:6px 0 0;font-size:12px;color:#9c8e7c}
  .err{background:rgba(255,80,80,.1);border:1px solid rgba(255,80,80,.3);color:#ffb3a8;padding:10px 12px;border-radius:10px;margin:0 0 8px;font-size:14px}
  .warn{background:rgba(232,197,152,.08);border:1px solid rgba(232,197,152,.3);color:#e8c598;padding:12px 14px;border-radius:12px;margin:0 0 16px;font-size:13px;line-height:1.5}
  .warn strong{display:block;margin-bottom:4px}
  .warn p{margin:6px 0;color:#d4b58c}
  .warn pre{margin:8px 0 0;padding:10px;background:#0f0d0a;border-radius:8px;font:12px ui-monospace,monospace;overflow-x:auto;color:#f4ede1}
  .footer{font-size:12px;color:#9c8e7c;margin:18px 0 0}
  code{background:#221c17;padding:2px 6px;border-radius:6px;color:#d4a574;font-size:90%}
  a{color:#d4a574}
</style>
</head><body><div class="box">$body</div></body></html>
HTML;
    }
}