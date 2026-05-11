# Changelog

All notable changes to tylio are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Internationalization (i18n)**.
  - Admin SPA uses [vue-i18n](https://vue-i18n.intlify.dev) v11. Locale auto-detected from `navigator.language` (Italian → `it`, anything else → `en`). User override in Settings → "Language" survives reloads via `localStorage`.
  - Public site / server-rendered pages use a new `Tylio\Services\I18n`. Locale resolved from `settings.site.locale` (admin choice) → `Accept-Language` header → English fallback.
  - All user-facing strings live in `app/Locales/{en,it}.php` and `admin-src/src/locales/{en,it}.json`. Add a language = drop a `xx.{php,json}` file + extend `SUPPORTED_LOCALES`.
  - `BlockRegistry` is now pure data with `blocks.*` translation keys (resolved server-side by `TypesController` against the request's `Accept-Language`); the admin sheet labels follow the admin's browser language.
  - Transactional emails (Mailer) pick locale from `settings.site.locale` so the recipient gets the site owner's language.
  - English/Italian translations available out of the box. Existing single-language installs are unaffected: the seed migration leaves `site.locale` empty (negotiated per visitor).
- **TOTP 2FA** for the admin user: QR setup + 10 backup codes. Endpoints under `/api/2fa/*`, UI in Settings → "Two-factor authentication".
- **Migration system improvements**:
  - Support for `.php` migration files (alongside `.sql`) for complex data transforms.
  - `php scripts/migrate.php status` — list applied + pending migrations.
  - `php scripts/migrate.php version` — latest applied migration.
  - `php scripts/make-migration.php <slug> [--php]` — generator that auto-computes the next number.
- **Single-file HTML export**: download the home page as one `index.html` with everything inlined (CSS, images as data URIs). Endpoint `/api/export/inline`. Button in Settings → "Export home page".
- **9 theme preset families**: Neon City, Sunrise, Elegant Turquoise, Nordic, Pink Lady, Forest, Cocktail, Commander, Matrix (light + dark for each).
- **5 font categories**: Serif, Sans-serif, Modern, Script, Monospace. Live preview shows the font name rendered in the font itself, next to the select.
- **Dynamic font loading in theme preview**: the chosen font is loaded into the preview iframe via postMessage, no more "stuck on fallback until I save".
- **"Apply to all separators" button** in EditBlock for `divider` tiles: uniforms style across every separator on the page in one click.
- **CONTRIBUTING.md**, **SECURITY.md**, **CHANGELOG.md** (this file).
- **GitHub Actions CI**: phpstan + phpunit on PRs and pushes to `main` (PHP 8.2/8.3/8.4, Node 20/22).

### Changed

- Contact-form tile (`contact` block) is now **excluded from static export** — the submit needs a backend. Explicit notice in the admin.
- Click tracking script removed from static export (was making silent 404s offline).
- `inlineAssets()` has size caps: 8 MB per asset, 80 MB total. Oversized assets stay as URLs instead of bloating the export.
- `ChangePasswordController` now requires the 2FA code if the user has it enabled — fixes a critical 2FA bypass via the password-change flow.
- `pending_2fa` sessions are rejected by `AuthMiddleware` / `loadFromRequest` — fixes a critical bypass where password alone (step 1) granted full access.
- Social tile: URLs without `https://` are now auto-prefixed instead of silently dropped. For `platform=website`, the label shows the domain rather than the generic "Website" — useful when there are several site links.

### Changed

- **Composer package renamed** from `tylio/tylio` to `tylio/core` for clarity: the OSS package can now coexist with future siblings under the `tylio/*` vendor namespace (e.g. `tylio/cli`, `tylio/platform`). Migration for self-hosters: `composer remove tylio/tylio && composer require tylio/core` — or, if you depend on it from another project's `composer.json`, just replace the package name in `require`. No PHP namespace changed (`Tylio\…` stays the same).

### Fixed

- **Clone-readiness**: admin SPA build target moved from `public/admin/` to `admin/` so a fresh `npm run build` from `admin-src/` lands where the front controller actually looks for the SPA shell (`<root>/admin/index.html`). Fixes a 503 on `/admin` on freshly cloned checkouts.
- **PHP 8.4 compatibility**: removed the deprecated `E_STRICT` constant from `error_reporting()` in `bootstrap.php`. No more "Constant E_STRICT is deprecated" warnings in PHP 8.4 logs.
- Initial-seed defaults (`site.tagline`, `site.description`) translated to English in migration `0001`. `site.locale` is now empty by default, letting the visitor's `Accept-Language` drive the language.
- OG image preview: square images no longer squashed into a forced 1.91:1 box — the box now adapts to the image height (max 630px) with side bands styled like the upload area.
- Logo SVG in admin: cached CDN shadows/filters eliminated via Vite asset import (hash-based cache busting).
- `EditBlock` dirty flag no longer triggered by the initial block assignment (was a mount-time false positive).

## [0.1.0] — 2026-04-15

First public release.

### Added

- 14 base tile types (hero, links, apps, bio, products, social, gallery, embed, contact, divider, footer, podcast, youtube, plus quote, stats, cta, faq, timeline)
- Theme editor with palette, font, tile style, mobile spacing
- Drag & drop reorder, media library, contact form, superadmin 2FA
- Static ZIP export (multi-file)
- Auth, CSRF, rate limit, audit log
- Auto-migrations on bootstrap, install via `/install` web or `scripts/seed.php`

[Unreleased]: https://github.com/simplemal/tylio/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/simplemal/tylio/releases/tag/v0.1.0
