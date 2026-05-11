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
        return $this->html($response, $this->setupForm(null, '', $adminMissing));
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

        $err = null;
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
            $err = 'Username must be 3-32 chars (letters, digits, _ . -).';
        } elseif (strlen($password) < 10) {
            $err = 'Password must be at least 10 characters.';
        } elseif ($password !== $confirm) {
            $err = 'Passwords do not match.';
        }
        if ($err) {
            return $this->html($response->withStatus(422), $this->setupForm($err, $username));
        }

        $hash = $this->auth->hashPassword($password);
        $this->db->insert('users', ['username' => $username, 'password_hash' => $hash]);

        // Seed sample blocks if the page is empty
        if ((int)$this->db->value('SELECT COUNT(*) FROM blocks') === 0) {
            $this->seedSample();
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', $this->config->adminPath() . '/');
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

    private function setupForm(?string $err = null, string $username = '', bool $adminMissing = false): string
    {
        $errHtml = $err ? '<p class="err">' . htmlspecialchars($err) . '</p>' : '';
        $u = htmlspecialchars($username);
        $admin = htmlspecialchars($this->config->adminPath());
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
        return $this->wrap('Initial setup', <<<HTML
<h1>Welcome to <em>tylio</em></h1>
<p class="muted">Create the admin user. After this you won't be able to access this page anymore.</p>
$warnHtml
$errHtml
<form method="post" action="">
  <label>Username
    <input name="username" value="$u" autocomplete="off" required minlength="3" maxlength="32" pattern="[a-zA-Z0-9_.\-]+">
  </label>
  <label>Password (at least 10 characters)
    <input name="password" type="password" required minlength="10">
  </label>
  <label>Repeat password
    <input name="password2" type="password" required minlength="10">
  </label>
  <button type="submit">Create admin</button>
</form>
<p class="footer">Once the admin is created you'll be redirected to <code>$admin</code>.</p>
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