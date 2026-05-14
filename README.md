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

- PHP **8.2+** with extensions: `pdo_sqlite`, `gd`, `zip`, `sodium`, `mbstring`, `fileinfo`, `json`, `curl`, `intl`, `xml`
- **Composer** 2.x (installed via the official installer — see notes below)
- **Node 20+** + npm (only to build the admin SPA — not needed on the server in production)
- `sqlite3` CLI (optional, useful for inspecting the database)
- Apache with `mod_rewrite` (or equivalent with front-controller routing)

## Install (first time)

> **Fast path for Ubuntu/Debian users:** run `sudo bash scripts/install-prereqs.sh`
> after cloning. It installs PHP 8.3 with all extensions, Composer (official
> installer), Node 20, and `sqlite3` — picking the right packages for your
> distro codename (focal/jammy/noble/bookworm/trixie). Pass `--check` to
> verify what's already installed without touching the system.

### 1. Clone

```bash
git clone https://github.com/simplemal/tylio.git my-site
cd my-site
```

If you cloned with `sudo` into a system directory (e.g. `/var/www/`), git's
"dubious ownership" protection will block subsequent `git` / `composer`
commands. Allow the directory for both root and your deploy user:

```bash
git config --global --add safe.directory /var/www/my-site
sudo git config --global --add safe.directory /var/www/my-site
```

### 2. Install PHP 8.2+ and required extensions (Ubuntu/Debian)

Ubuntu 20.04 (focal) ships PHP 7.4 by default and Ubuntu 22.04 (jammy) ships
PHP 8.1 — too old for tylio. Use the **Sury PPA** (`packages.sury.org/php/`),
*not* `ppa:ondrej/php` on Launchpad (the launchpad PPA has been empty for
focal since June 2025 — verified).

```bash
sudo apt update
sudo apt install -y curl ca-certificates lsb-release apt-transport-https
curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
sudo dpkg -i /tmp/debsuryorg-archive-keyring.deb
echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
    | sudo tee /etc/apt/sources.list.d/sury-php.list
sudo apt update

# Install PHP 8.3 + the extensions tylio needs.
# Replace "8.3" with the version you want — make sure to keep it consistent.
sudo apt install -y \
    php8.3 php8.3-cli php8.3-fpm \
    php8.3-sqlite3 php8.3-gd php8.3-zip \
    php8.3-mbstring php8.3-xml php8.3-curl php8.3-intl
```

> Built-ins in PHP 8.3 (no extra package needed): `sodium`, `fileinfo`,
> `json`, `ctype`, `openssl`.

Sanity check:

```bash
php --version
php -m | grep -iE 'pdo_sqlite|gd|zip|mbstring|sodium|curl|intl'
```

### 3. Install Composer (official installer — NOT `apt`)

**Do not** run `sudo apt install composer`. On Ubuntu/Debian the apt package
pulls **`php-cli` from the distro** as a dependency, and the resulting
`/usr/bin/composer` is hard-wired to that older PHP — even if you installed
8.3 from Sury. Symptom: `composer install` fails with `ext-gd / ext-pdo_sqlite /
ext-zip missing` because Composer is looking inside the wrong PHP.

```bash
php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm /tmp/composer-setup.php
composer --version   # should print "Composer version 2.x"
```

### 4. Install Node 20+ (NodeSource setup script)

Node 20+ is not available in the Ubuntu repos on focal/jammy. Use the
NodeSource setup script:

```bash
sudo apt install -y curl
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo bash -
sudo apt install -y nodejs
node --version    # v20.x
npm --version
```

### 5. Install the `sqlite3` CLI (optional but useful)

`php8.3-sqlite3` is only the PHP extension. The `sqlite3` shell binary is
separate:

```bash
sudo apt install -y sqlite3
```

### 6. PHP dependencies and admin SPA

```bash
composer install --no-dev --optimize-autoloader

cd admin-src
npm install
npm run build          # writes ../admin/ (gitignored)
cd ..
```

### 7. Pre-create runtime directories with correct ownership

PHP-FPM runs as `www-data` on Ubuntu/Debian (`apache` on Red Hat). When you
cloned with `sudo` the project root is `root:root`, so the `mkdir` calls in
`bootstrap.php` would silently fail and `data/db.sqlite` would never get
created. Create the directories upfront and hand them to the web user:

```bash
sudo mkdir -p data data/sessions data/logs uploads favicons
sudo chown -R www-data:www-data data uploads favicons
sudo chmod -R 770 data uploads favicons
```

If you're on shared hosting with no shell, create those four directories via
SFTP — the install wizard will create `db.sqlite` on the first hit.

### 8. Configuration

```bash
cp .env.example .env
# Edit .env: at minimum set APP_URL and APP_KEY:
#   APP_KEY=$(php -r "echo bin2hex(random_bytes(32));")
```

### 9. Webserver

Apache: **DocumentRoot = project root** (the repo's top level, *not*
`public/`). The shipped `.htaccess` provides front-controller routing on
`index.php` and denies access to `.env`, `vendor/`, `app/`, etc.

Visit `https://your-domain.example/install` in a browser to:
- create the admin user,
- initialize the SQLite database and apply migrations,
- load a few example tiles.

`/install` auto-disables itself after first run. Then `/admin` is your editor.

> **No shell on the server?** Install runs entirely via browser. Migrations
> apply automatically on the first hit to `/install` (and on every subsequent
> boot — they're idempotent).
>
> **Migrating from a tylio.app SaaS tenant?** Drop a `.tar.gz` exported from
> the SaaS admin into the install wizard's second card ("Importa un sito
> esistente"). The importer rewrites the multi-tenant schema to single-tenant,
> moves the slug-scoped uploads to flat paths, and patches all DB references
> in one shot. See *Troubleshooting* below for details.

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

## Cutting a release

`scripts/make-release.sh` automates the steps required to publish a new
tylio release on GitHub. Each step is idempotent — if something fails
halfway through (e.g. the editor exits without saving), re-running the
script picks up where the previous run left off.

```bash
# Pick a semver version (the leading `v` is optional, it gets added if
# you omit it). Dry-runs aren't a thing — but every destructive step
# (push, GitHub release) asks for explicit confirmation.
scripts/make-release.sh v0.2.0
```

What it does, in order:

1. Validates the version against semver (`MAJOR.MINOR.PATCH[-pre][+build]`).
2. Writes `BUILD` (release marker, e.g. `v0.2.0`) and `.version`
   (UTC timestamp `YYYY-MM-DD-HHMMSS`). Both are read by
   `Tylio\Util\Build::init()` for asset cache-busting.
3. Prepends a new entry to `CHANGELOG.md`:
   `## v0.2.0 — YYYY-MM-DD` followed by an auto-collected list of
   `feat:`/`fix:` commits since the previous tag. Opens `$EDITOR`
   (fallback `vi`) so you can refine the notes — the version section
   between this header and the next `## v…` becomes the body of the
   GitHub release.
4. Runs `composer install --no-dev --optimize-autoloader` to refresh
   `composer.lock` against the release.
5. Runs `cd admin-src && npm install && npm run build && cd ..` to
   refresh the bundled SPA. The built `admin/` directory is then
   packaged as `tylio-admin-bundle-vX.Y.Z.tar.gz`, attached to the
   GitHub release so anyone updating an OSS install without Node can
   just drop the new bundle in place.
6. Commits everything as `chore(release): vX.Y.Z` and creates an
   annotated git tag `vX.Y.Z`.
7. Prompts before `git push origin main && git push origin vX.Y.Z`.
8. Prompts before `gh release create vX.Y.Z` with the auto-extracted
   changelog section as `--notes-file` and the admin tarball as an
   asset. Skipped silently if `gh` is not installed (you can create
   the release manually from the GitHub UI).

Re-cutting an existing version is supported with `--force-tag`
(deletes the local tag, re-creates it). Don't pass it after pushing —
re-tagging a published version is bad form. Just bump the patch level.
The script refuses to commit if the working tree has uncommitted
changes outside the gitignored `admin/` build directory.

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

## Troubleshooting

Real frictions encountered installing tylio on a fresh Ubuntu 20.04 LXC,
distilled. If you hit something new, please open an issue.

### `sudo: setrlimit(RLIMIT_CORE): Operation not permitted`

You're on a Proxmox unprivileged LXC. Benign warning — the command runs.
To suppress it, add `Defaults !use_pty` to `/etc/sudoers` via `visudo`,
or just ignore the line.

### `composer install` reports `ext-gd / ext-pdo_sqlite / ext-zip missing`

Most common cause on Ubuntu 20.04: you ran `apt install composer`, which
pulled the distro's PHP 7.4 as a dependency, and `/usr/bin/composer` is
now pinned to PHP 7.4 (which doesn't have the extensions). Fix: use the
official installer (step 3 above) and check `composer --version` prints
the right PHP path (`composer about` or `composer diagnose`).

If the right Composer is in place, you really are missing the extensions —
install them via the Sury PPA (step 2 above).

### `fatal: detected dubious ownership in repository at '/var/www/...'`

Git ≥ 2.35 refuses to operate in repos not owned by the current user
(anti-attack measure on shared filesystems). Clear it for both root and
your deploy user:

```bash
git config --global --add safe.directory /var/www/your-site
sudo git config --global --add safe.directory /var/www/your-site
```

### `bash: sqlite3: command not found`

`php8.3-sqlite3` is the PHP extension; the CLI binary is a separate package:

```bash
sudo apt install -y sqlite3
```

### HTTP 500 on the first hit; logs say `unable to open database file`

`data/` doesn't exist (or `www-data` can't write into it). After `sudo git
clone …` the project root is `root:root 755`. Pre-create the runtime dirs
and chown them (step 7 above):

```bash
sudo mkdir -p data data/sessions data/logs uploads favicons
sudo chown -R www-data:www-data data uploads favicons
sudo chmod -R 770 data uploads favicons
```

### Migrated from a tylio.app SaaS tenant: `no such column: id` on `theme`

The SaaS multitenant migration drops `theme.id` and uses `tenant_id` as
the primary key — but OSS queries `SELECT data FROM theme WHERE id = 1`.
Just patching the column isn't enough: the SaaS schema has
`INTEGER PRIMARY KEY` on `tenant_id`, which makes it a ROWID alias, so
each subsequent `INSERT OR REPLACE` from the OSS admin auto-increments
instead of overwriting — you end up with multiple "theme" rows.

**Don't migrate manually.** Use the importer: `POST /install/import` (no
admin yet) or `POST /admin/import` (already installed). It rebuilds the
`theme` table with the OSS schema (`id INTEGER PRIMARY KEY CHECK(id=1)`),
deletes extra tenant rows, and rewrites slug-scoped media paths to flat
ones in one transaction. See "Import a tar.gz export" below.

### Migrated from SaaS: images/icons broken (`/uploads/<slug>/foo.png`)

The SaaS overlay stores media under `public/uploads/<slug>/`; OSS stores
them flat in `public/uploads/`. The importer handles this automatically:
it copies files flat into `public/uploads/` / `public/favicons/` and
rewrites every reference in `blocks.data`, `blocks.style`, `theme.data`,
and `settings.value` from `/uploads/<slug>/` to `/uploads/` (and the same
for favicons). Both unescaped (`/uploads/x/`) and JSON-escaped
(`\/uploads\/x\/`) forms are covered.

### Import a tar.gz export

A tylio SaaS site (and a tylio OSS site, too) can produce a portable
`.tar.gz` archive containing the full state — DB rows + media files.

Export from the source site:
- **OSS**: `Settings → Esporta sito` (or `GET /admin/export`).
- **SaaS tenant**: same UI; the platform overlay scopes the export to the
  current tenant.
- **SaaS superadmin**: `Tenants → <tenant> → Export tenant` produces an
  archive for any tenant.

Import on the target OSS installation, before creating the admin user:

```bash
curl -F "archive=@my-site-export.tar.gz" https://my-new-site.example/install/import
```

…or via the install wizard's second card ("Hai un export di un sito tylio
esistente?"). After admin creation use `POST /admin/import` with
`confirm=true` instead — it overwrites every row of the current site.

### `npm run build` fails on Ubuntu 20.04 with `Unexpected token '?.'`

Your Node is too old. The distro repos ship Node 10.19 on focal; Vite 5
needs Node ≥ 20. Run the NodeSource setup script (step 4 above) and check
`node --version` prints `v20.x`.
