# Changelog

All notable changes to tylio are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


## v0.2.5 — 2026-05-15

### Fixed — tessera Link UX (round 2)

- **Favicon mostrata correttamente**. Servizio DuckDuckGo `ip3` falliva (404) su molti domini reali (es. nevecosmetics.it) facendo scattare il fallback all'SVG generico. Switch a Google `faviconV2` (gstatic CDN) — hit rate >>95% sui domini comuni. `referrerpolicy=no-referrer` mantenuto.
- **Radio cards usano `--backend-accent-rgb`** (accent admin computato per contrasto) invece di `--accent-rgb` (frontend). Su palette estreme tipo "Pink Lady · light" il primo era bianco → bordo/dot invisibili. Allineato al pattern già usato da `.btn-primary` e dalla pill della sidebar.
- **Riga badge + toggle ora allineata correttamente**: `align-items: flex-end` + padding-bottom calibrato sul toggle. Lo switch sta sulla stessa baseline dell'input del badge, non centrato verticalmente all'intera field (che includeva la label sopra).
- **Sidebar "Tessere" highlight in edit-block**. `<router-link active-class>` di default attivava `/` solo a match esatto, perdendo la pill quando l'utente entrava in `/blocks/:id`. Aggiunta logica `isNavActive()` che marca Tessere attivo anche per `/blocks/*` sub-paths.

### Fixed — SaaS overlay (tylio.app)

- **TenantRenderer ha la propria copia di `links.php` + `public.css`** in `tylio-platform/src/Templates/`. L'aggiornamento del template OSS in v0.2.3/v0.2.4 NON arrivava al pubblico dei tenant — restava il vecchio markup con riga interamente cliccabile. Sincronizzati: solo il titolo è ora cliccabile, favicon Google, badge copy con feedback. Promemoria: ogni modifica al template OSS va portata anche nel duplicato platform.

## v0.2.4 — 2026-05-15

### Changed — admin editor della tessera Link (UX revision)

- **Icona scelta tramite due card radio**: `Usa favicon del sito` (selezionata di default) e `Usa icona personalizzata`. Il campo Iconify compare SOLO se si seleziona "personalizzata". Niente più placeholder `lucide:link` sempre visibile.
- **Badge + "Rendi copiabile" su una sola riga**: input badge a larghezza naturale + toggle a destra. Rimosso il blocco di help duplicato che appariva due volte sotto il toggle.
- **Bugfix: help duplicato sotto i toggle**. `Field.vue` rendering del tipo `toggle` emetteva l'help SIA dentro la label SIA nel `<p>` generico finale. Ora il `<p>` generico è soppresso per i toggle (l'help inline è già sufficiente). Effetto laterale benefico su tutti gli altri toggle con help nel sistema (es. social/contact dove c'era la stessa duplicazione silente).

### Added — schema field types (riusabili)

- **`radio_cards`**: nuovo tipo di campo, alternativa più grafica al `select` per scelte modali. Ogni opzione ha `label` + `description` opzionale, rendering a card con dot stile macOS, palette accent. Definibile da `BlockRegistry`.
- **`inline_group`**: nuovo tipo "virtuale" che renderizza i suoi figli affiancati su un'unica riga (es. text-input + toggle). Non introduce path nei dati — i figli scrivono direttamente sul record parent. Utile per coppie input+toggle correlate.
- **`show_when`**: clausola di visibilità condizionale per i campi dentro un `repeat`. Forma `{ key: 'altro_campo', equals: 'valore' }`. Il campo si renderizza solo se la sibling chiave matcha.
- **Migrazione legacy in EditBlock**: blocchi `links` salvati prima dell'introduzione di `icon_mode` ricevono `icon_mode='custom'` se hanno un'icona impostata, `'favicon'` altrimenti. Patch applicata PRIMA dello snapshot di dirty, così l'apertura di un blocco vecchio non risulta "modified" senza interazione utente.

## v0.2.3 — 2026-05-15

### Changed — tessera Link

- **Favicon di default sull'icona**. Se l'item ha un URL `http(s)` valido e nessuna icona scelta, viene caricata la favicon del sito tramite il servizio privacy-friendly di DuckDuckGo (`icons.duckduckgo.com/ip3/<host>.ico`, `referrerpolicy=no-referrer`, lazy). Per host non risolti, fallback all'SVG generico via `onerror`. Le icone esplicite (Iconify) restano prioritarie.
- **Solo il "Testo" è cliccabile**, non più tutta la riga. Il wrapper riga è ora un `<div>`; solo il titolo è un `<a>` con `target=_blank` per i link esterni. Effetto hover ridotto al solo titolo (accent + underline). Migliora chiarezza UX e accessibilità — tab-stop unico per item.
- **Opzione "Rendi copiabile" sul badge**. Nuovo toggle per item `badge_copyable`: se attivo, nel sito pubblico cliccando il badge il suo testo viene copiato negli appunti (`navigator.clipboard.writeText`) con feedback visivo (icona check + classe `.is-copied` per ~1.6s). Utile per codici sconto, riferimenti, ecc. Lo script è scoped per `data-block-id`, quindi più tessere `links` sulla stessa pagina non si pestano i piedi.

## v0.2.2 — 2026-05-15

### Fixed

- **Login.vue: messaggi di errore precisi** invece del generico "Errore di rete". Ora distingue:
  - `HTTP 5xx` → "Errore del server (HTTP {status}). Riprova tra poco — se persiste, controlla i log."
  - `ApiError` con status fuori da 401/403/404/429 → "Risposta inattesa dal server (HTTP {status})."
  - errore di rete vero (fetch fallisce prima di una risposta HTTP) → "Impossibile contattare il server. Verifica la connessione e riprova."
  - 401, 403, 404, 429: gestiti come prima (credenziali, password change required, wrong domain, rate limit)

  Prima quando il backend returnava 500 il SPA mostrava "Errore di rete" — fuorviante, perché l'utente pensava al wifi mentre il vero problema era server-side.

## v0.2.1 — 2026-05-15

### Fixed

- `UpdateChecker::gitDescribe()` ora passa `-c safe.directory=$root` inline, così `git describe` non fallisce con "dubious ownership" quando PHP-FPM gira come `www-data` su un repo clonato via `sudo git clone` (default OSS install path). Senza questo, la card "Aggiornamenti tylio" mostrava `Versione installata: dev` invece del tag reale.

## v0.2.0 — 2026-05-15

### Added

- **Admin email + verifica via codice**: l'install wizard chiede un'email opzionale per l'admin. La verifica avviene tramite codice 6-char (Crockford base32, no `0/O/1/I/L`), TTL 24h. La mail di **sola verifica** contiene esclusivamente il codice — niente username né URL admin per evitare leak in caso di typo. La mail di **benvenuto** con dati di accesso parte SOLO dopo verifica riuscita (flag `welcome_sent_at`). Rate-limit di 30 min sui resend manuali da Settings.
- **Settings → Email**: nuovo campo con tick verificato/non, riga codice + bottone "Verifica" + link "Reinvia codice" con countdown.
- **Submissions**: il form contatti ora forwarda solo a email **verificata** (`mail_status='unverified_recipient'` altrimenti). Setting legacy `contact.notify_email` auto-migrato a `site.admin_email`.
- **CI**: aggiunto `concurrency.group` + `cancel-in-progress` per cancellare i job pendenti di push superseded, evitando job zombie. `timeout-minutes` 15/10 sui job.

### Security

- Codice di verifica salvato come `hash('sha256', code . pepper)` — mai in chiaro.
- Max 5 tentativi sbagliati → invalida codice + forza resend.
- Audit log per ogni `requestCode` / `verifyCode`.

### Notes

- Pacchetto SaaS (`tylio-platform`): nuova `TenantSetup` pre-verifica l'email dell'admin tenant al momento di invito superadmin.
- TODO esplicito (`memory/tylio_todo.md`): UI password reset (form `/forgot-password`), 2FA via email come fallback TOTP, notifica al "previous email" al cambio.

## v0.1.0 — 2026-05-14

### Notes

- feat(updates/admin): "Aggiornamenti tylio" card in Settings with version compare + changelog
- feat(updates): /api/admin/update-check compares local vs GitHub release with 24h cache
- feat(import): /install/import + /admin/import accept tar.gz exports, handle schema variations
- feat(export): GET /admin/export produces full-site tar.gz (data + uploads + favicons)
- feat(install): comprehensive Linux prereq guide + automated bootstrap script
- fix(install): wizard now uses Neon · scuro palette (default OSS UI)
- fix(admin): warning surfaces readable on light AND dark palettes
- feat(maintenance): dedicated /maintenance route with own sidebar entry
- feat(maintenance): standalone settings card + admin banner on live site
- fix(theme): preserve palette.name (preset id) through sanitization
- feat(install+maintenance+palette): site setup wizard, maintenance mode, palette auto-highlight
- fix(theme): widen sanitizer whitelist for tile + background fields
- fix(admin/i18n): escape @ in translation strings + add pre-build syntax check
- fix(admin/i18n): use typographic apostrophe in IT to avoid vue-i18n lexer crash
- fix(admin): bump checkbox/radio size to 18px for visibility
- fix(admin): re-enable native appearance on checkbox/radio

## [Unreleased]

### Added

- **Maintenance mode**. New toggle in Settings → "Manutenzione" (`site.maintenance` + `site.maintenance_message` settings, migration `0006`). When on: public visitors get a dedicated, self-contained page rendered server-side by `PageController::home` (HTTP 503 + `Retry-After: 600`); the logged-in admin keeps seeing the real site so they can preview their work in place. AppShell banner in the admin SPA flags the state via the new `useSite` Pinia store. Multi-tenant overlay: `TenantPageController::home` does the same check tenant-scoped via `TenantAuth::loadFromRequestForTenant`, so an admin of tenant A can't bypass tenant B's maintenance.
- **Extended install wizard**. `/install` now also asks for site title (optional) + site language (radio, IT/EN, pre-selected from `Accept-Language`) + auto-applies the **Nordic** palette in light or dark variant based on the visitor's current system theme (`prefers-color-scheme`, detected client-side and locked at install time). No theme dropdown: with no in-page preview the choice would be guesswork — the user picks freely later from Theme → Presets.
- **`--backend-accent` color cascade for the admin SPA**. The active nav pill, `.btn-primary`, the settings toggle ON state, and the tile-type icons (Dashboard list, AddBlockSheet, EditBlock header) now share a single `--backend-accent` CSS variable computed at runtime from the user's palette by a 3-step cascade:
  1. `accent` — if it has enough contrast AND is not too neutral (luminance ∈ [0.10, 0.85] and `max(R,G,B) − min(R,G,B) ≥ 10`);
  2. `accent_soft` — same checks; this is where palette authors put the "contrast on primary accent" when the primary is neutral;
  3. `text` — guaranteed contrast with `surface`, the fallback the toggle used before this cascade existed.
  
  The foreground (`--backend-accent-fg`) is paired with each step (step 1: `accent_soft`; step 2: `accent`; step 3: `surface`) so the on-pill contrast follows the palette author's editorial choice rather than an auto-derived black/white. Driven by `theme.ts` so palette changes from the Theme editor apply live, no reload.
- **Pre-build `npm run check-i18n` gate** (`admin-src/scripts/check-i18n-syntax.mjs`). Scans every locale JSON for the three vue-i18n message-compiler pitfalls — bare `@` (`INVALID_LINKED_FORMAT`), ASCII apostrophe `'` next to `{placeholder}` (`UNEXPECTED_LEXICAL_ANALYSIS`), and stray `|` in non-plural strings. Wired into `npm run build` so the regression fails CI before reaching the bundle, instead of crashing at runtime with a blank route.
- **2FA login UX**: when "Use a backup code" is toggled, the screen now explains inline what a backup code is (single-use, 8 hex chars), how it relates to the other codes, and where to check the remaining count after login. Dynamic icon (`shield-check` ↔ `life-buoy`).

### Changed

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

- **Admin hover semantics**: text/icon colors NEVER change on hover. The previous logic shifted between three different tones (`ink-100` default → `ink-300` hover → `--backend-accent-fg` active) and looked broken on themes where the first two tones ended up similar. Now the foreground is locked across hover; hover is signalled by either a faint `ink-100/7%` background tint (non-active items) or by darkening the active bg via `color-mix(in srgb, accent 92%, black)` (active pill, `.btn-primary`).
- **Theme sanitizer (`ThemeController::sanitizeTheme`) whitelist widened** to preserve all documented `tile.*` and `background.*` fields. Previously the strict whitelist (introduced as CSS-injection hardening) was stripping `tile.style`, `tile.shadow`, `tile.tessellate`, `tile.mobile_spacing`, and `background.pattern` before they hit the DB, so editing them in the Theme tab appeared to "not save". The sanitizer remains strict — values outside the documented enums are still dropped — so the injection protection is intact.
- **Checkbox + radio appearance restored** in admin. The generic `input, textarea, select { appearance: none; }` rule was inheriting onto `<input type="checkbox">` and `<input type="radio">`, hiding the native checkmark/dot glyph. Now explicitly re-enabled, sized at 18px for visibility, bound to `accent-color: var(--accent)` so it stays on-palette.

- Contact-form tile (`contact` block) is now **excluded from static export** — the submit needs a backend. Explicit notice in the admin.
- Click tracking script removed from static export (was making silent 404s offline).
- `inlineAssets()` has size caps: 8 MB per asset, 80 MB total. Oversized assets stay as URLs instead of bloating the export.
- `ChangePasswordController` now requires the 2FA code if the user has it enabled — fixes a critical 2FA bypass via the password-change flow.
- `pending_2fa` sessions are rejected by `AuthMiddleware` / `loadFromRequest` — fixes a critical bypass where password alone (step 1) granted full access.
- Social tile: URLs without `https://` are now auto-prefixed instead of silently dropped. For `platform=website`, the label shows the domain rather than the generic "Website" — useful when there are several site links.

### Changed

- **Composer package renamed** from `tylio/tylio` to `tylio/core` for clarity: the OSS package can now coexist with future siblings under the `tylio/*` vendor namespace (e.g. `tylio/cli`, `tylio/platform`). Migration for self-hosters: `composer remove tylio/tylio && composer require tylio/core` — or, if you depend on it from another project's `composer.json`, just replace the package name in `require`. No PHP namespace changed (`Tylio\…` stays the same).

### Fixed

- **Palette picker now highlights the active preset on page load** (Theme → Presets). Two stacked bugs caused this:
  1. **Server side**: `ThemeController::sanitizeTheme` was dropping `palette.name` for every hyphenated preset id (`nordic-dark`, `pink-lady-light`, `neon-dark`, …). The whitelist treated `name` like a CSS color, and `isValidCssColor()`'s named-color regex `^[a-zA-Z]{3,30}$` rejects hyphens. So every save round-tripped the palette without its identifier. Fixed by validating `palette.name` separately as a slug (`/^[a-z][a-z0-9_-]{0,40}$/i`) — the injection guard stays in place, hyphenated ids now survive.
  2. **Client side defense in depth**: even for legacy installs whose stored palette never had a `name` (or whose name doesn't match any preset id, e.g. the old `terra` seed), `Theme.vue` now does a value-by-value structural match at `onMounted` via a new `detectPresetByValue()` helper and assigns the matching preset name locally. `savedSnapshot` is captured AFTER the assignment so the dirty-flag stays clean (no spurious unsaved-changes banner just from opening the page).
- **vue-i18n message-compiler crashes on Italian locale**. Bare `@` in translation strings (e.g. `@username`, `yourname@example.com`) was interpreted as the start of vue-i18n's "linked format" syntax (`@:other.key`), throwing `SyntaxError: 10` (`INVALID_LINKED_FORMAT`) at compile time. Since v11 compiles messages eagerly on the active locale, ONE broken string crashed the whole i18n setup and left every route that uses `t()` rendering blank. Fixed by escaping `@` as `{'@'}` in all locale strings and adding the pre-build `check-i18n` gate above.
- **ASCII apostrophe vs placeholder interaction in Italian locale**. Strings like `L'anteprima ... {save}` triggered the literal-escape lexer (`'…'` is vue-i18n's escape syntax). Switched every Italian `'` to the typographic `’` (U+2019) — same meaning, zero conflict with the lexer.
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
