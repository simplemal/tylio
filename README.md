# tylio

> Your home page, one tile at a time.

**tylio** is a **self-hosted, single-user** personal page builder. You compose your home page from modular tiles (hero, links, social, gallery, embed, contact, …). Mobile-first, server-rendered, you own the data.

## Philosophy

- **Single-user, single-site.** One install = one person = one page. No multi-tenant, no complications.
- **Server-rendered.** The public page is server-side HTML. No heavy SPA for visitors. The Vue admin SPA is only for whoever edits.
- **Own your data.** Local SQLite, no lock-in, no cloud API. Export everything as static HTML in one click.
- **No tracking.** A single technical session cookie (`__Host-tylio_sid`). No analytics, no profiling.

## Features

- 16+ pre-built tile types (hero, links, apps, bio, products, quote, stats, cta, faq, timeline, social, gallery, embed, contact, divider, footer, podcast, youtube)
- Theme editor with presets (Neon City, Sunrise, Elegant Turquoise, Nordic, Pink Lady, Forest, Cocktail, Commander, Matrix)
- Drag & drop tile reordering
- Media library with upload (JPEG/PNG/WebP/GIF — no SVG, for safety)
- Contact form with honeypot anti-spam + email notification
- Optional **TOTP 2FA** (Google Authenticator, Aegis, 1Password, etc.) with backup codes
- Daily visit counters + per-tile click stats
- **Static HTML export**: download the page as a ZIP, or as a single `index.html` with everything inlined
- Argon2id passwords, HttpOnly+Secure+SameSite=Strict sessions, CSRF, login rate-limit

## Requirements

- PHP **8.2+** with extensions: `pdo_sqlite`, `gd`, `zip`, `sodium`, `mbstring`, `fileinfo`, `json`
- **Composer** 2.x
- **Node 20+** + npm (only to build the admin SPA — not needed on the server in production)
- Apache with `mod_rewrite` (or equivalent with front-controller routing)

## Install (first time)

```bash
# 1. Clone
git clone https://github.com/simplemal/tylio.git my-site
cd my-site

# 2. PHP dependencies
composer install --no-dev --optimize-autoloader

# 3. Build the admin SPA (Vue + Vite)
cd admin-src
npm install
npm run build          # writes ../admin/ (gitignored)
cd ..

# 4. Configuration
cp .env.example .env
# Edit .env: at minimum set APP_URL and APP_KEY:
#   APP_KEY=$(php -r "echo bin2hex(random_bytes(32));")

# 5. Webserver
# Apache: DocumentRoot = project root. The shipped .htaccess provides
# front-controller routing on index.php and denies access to
# .env, vendor/, app/, etc.
```

Visit `https://your-domain.example/install` in a browser to:
- create the admin user,
- initialize the SQLite database and apply migrations,
- load a few example tiles.

`/install` auto-disables itself after first run. Then `/admin` is your editor.

> **No shell on the server?** Install runs entirely via browser. Migrations apply automatically on the first hit to `/install` (and on every subsequent boot — they're idempotent).

## Upgrade (from one version to the next)

```bash
cd my-site
git pull origin main

# Align dependencies
composer install --no-dev --optimize-autoloader
cd admin-src && npm install && npm run build && cd ..

# Migrations run automatically on the next HTTP hit.
# (Idempotent: applying twice does nothing.)
# Or from CLI:
php scripts/migrate.php
```

To inspect migration state:

```bash
php scripts/migrate.php status   # what's applied, what's pending
php scripts/migrate.php version  # name of the latest applied
```

## Migration system (for contributors)

Files live in `app/Database/migrations/NNNN_description.{sql,php}`, run in alphanumeric order. The runner records every applied migration in a `migrations` table (idempotent).

**Create a new migration with the generator:**

```bash
php scripts/make-migration.php add_user_avatar           # → 0005_add_user_avatar.sql
php scripts/make-migration.php recalc_positions --php    # → 0005_recalc_positions.php
```

**SQL migration** (DDL + simple seeds):

```sql
-- 0005_add_user_avatar.sql
ALTER TABLE users ADD COLUMN avatar_url TEXT;
CREATE INDEX IF NOT EXISTS idx_users_avatar ON users(avatar_url);
```

**PHP migration** (complex data transform):

```php
<?php
return function (PDO $pdo, DB $db): void {
    foreach ($db->all('SELECT id, data FROM blocks') as $row) {
        $data = json_decode((string)$row['data'], true) ?: [];
        // …transform…
        $db->update('blocks', ['data' => json_encode($data)],
                    'id = :id', ['id' => $row['id']]);
    }
};
```

**Golden rule**: a migration that has been applied elsewhere (CI, production, other developers) **must not be modified anymore**. Always add a new migration. See `CONTRIBUTING.md` for details.

## Useful commands

```bash
composer test           # PHPUnit
composer analyse        # PHPStan (level 5)
composer check          # both
composer migrate        # alias for php scripts/migrate.php
composer seed           # CLI install alternative to /install web

php scripts/migrate.php status
php scripts/make-migration.php <name> [--php]
php scripts/seed.php --username=admin --password=secret
php scripts/seed.php --username=admin --password=new --reset   # reset password
```

## Layout

```
.
├── index.php                     single front controller (Slim entry)
├── .htaccess                     rewrite rules + security headers
├── .env.example / .env           configuration (see below)
├── app/
│   ├── bootstrap.php             DI container + Slim app
│   ├── routes.php                URL → controller map
│   ├── Config.php                env/path helpers
│   ├── Controllers/              HTTP handlers
│   ├── Services/                 DB, Auth, Renderer, BlockRegistry, I18n, …
│   ├── Middleware/               AuthMiddleware, CsrfMiddleware
│   ├── Templates/
│   │   ├── layout.php            public home
│   │   ├── public.css            CSS for the home (server-injected inline)
│   │   └── blocks/<type>.php     one tile's template
│   ├── Database/migrations/      *.sql / *.php (see above)
│   ├── Locales/                  en.php / it.php (server-side translations)
│   └── Util/                     Markdown, Net, Build, PodcastEmbed, YouTubeFeed
├── admin-src/                    Vue 3 + Vite (sources, locales JSON, vue-i18n)
├── admin/                        built SPA (gitignored, written by `npm run build`)
├── data/                         SQLite DB + sessions + logs (gitignored, auto-created)
├── uploads/                      media library (gitignored, auto-created on first upload)
├── favicons/                     favicon set (gitignored, auto-created)
├── scripts/                      migrate.php, seed.php, make-migration.php, deploy.sh
└── tests/Unit/                   PHPUnit
```

Apache `DocumentRoot` is the project root. `data/`, `uploads/`, and
`favicons/` are auto-created at the first request (PHP needs write
access to the project root, or to those subdirs once they exist).

## Adding a new tile

See the dedicated guide: **[docs/EXTENDING-BLOCKS.md](docs/EXTENDING-BLOCKS.md)**.

At a glance, six files:

1. `app/Services/BlockRegistry.php` (field schema)
2. `app/Locales/{en,it}.php` (i18n keys under `blocks.<type>.*`)
3. `app/Templates/blocks/<type>.php` (server-side HTML)
4. `Renderer::blockHasContent()` (empty-tile filter)
5. `admin-src/src/types.ts` (TypeScript types — recommended)
6. `admin-src/src/components/BlockPreview.vue` (dashboard preview — optional)

The admin SPA reads the schema from `/api/types` at runtime — no SPA rebuild is needed for new blocks that use existing field types.

## Security

Found a security bug? See [SECURITY.md](SECURITY.md). Do not open public issues for vulnerabilities.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Welcome: new tiles, new theme presets, i18n improvements, bug fixes.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE).

## Hosted version

[tylio.app](https://tylio.app) is the hosted service built on this codebase plus a separate multi-tenant overlay. For self-hosted use, everything you need is in this repo — it stands alone.
