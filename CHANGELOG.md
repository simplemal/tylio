# Changelog

All notable changes to tylio are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


## v0.3.3 — 2026-05-16

### Fixed — In-app updater rejected every new release on v0.3.1 installs

Il `UpdateApplier::stagingLooksValid()` di v0.3.1 cercava `public/index.php` come check del tarball scaricato, ma tylio ha l'entry point a `index.php` (root) — `public/` contiene solo asset statici (`public/admin/` per la SPA). Risultato: **ogni release** da v0.3.2 in poi veniva rifiutata con HTTP 422 `staging_invalid` cliccando "Aggiorna ora" da un install v0.3.1.

Tre modifiche per fixare + permettere agli install v0.3.1 di aggiornarsi senza re-installazione manuale:

1. **`stagingLooksValid` cerca ora `index.php` al root** del tarball — quello giusto.
2. **Nuovo stub `public/index.php`** (301 redirect a `/`). Inerte se mai servito (non lo è — `.htaccess` al root rotta tutto su `index.php`), ma soddisfa il check buggato della v0.3.1 → un install su v0.3.1 può applicare v0.3.3 dalla GUI senza tocchi manuali.
3. **`.gitignore`**: il vecchio `/public/` ignorava tutta la directory (incluso il nuovo sentinel). Cambiato a `/public/*` + `!/public/index.php` per re-includere il file specifico mantenendo ignored il resto (asset legacy di vecchi build SPA).

**Per maurizionatali.it / installazioni su v0.3.1**: niente da fare manualmente. Click "Verifica ora" → "Aggiorna ora" pesca v0.3.3, lo stub passa il check di v0.3.1, swap a v0.3.3, da qui in poi gli update funzionano cleanly. Dalla v0.3.4 in poi il check è giusto e lo stub diventa puramente decorativo.

## v0.3.2 — 2026-05-16

### Changed — Block edit page: width labels + new universal Align widget

- **Width control**: rimosso il suffisso "· 1/2" / "· 2/2" dalle label dei due pulsanti (ora solo "Mezza" / "Piena"). Hint mobile abbreviato a "Solo su desktop" (il "su mobile sempre piena" era ridondante).
- **Background control**: rimossa la stringa di help redundante ("Senza sfondo rimuove bg, bordo e ombre solo per questa tessera") — i due bottoni con icona + label sono già self-explanatory.
- **Nuovo widget Allineamento universale**: subito sotto "Larghezza", tre opzioni `Sinistra | Centro | Destra` valide per **tutte** le tessere (prima erano solo Hero/Social a livello di field schema, e senza opzione "destra"). Salvato in `style.align` con `left` come default non persistito. Effetto applicato via classe `.m-tile--align-{center|right}` emessa da `layout.php` sul wrapper della tessera, con regole CSS che bridge-ano il comportamento esistente di Hero (avatar|titolo split preservato) e Social (riga di icone allineata).
- **Backward compat**: i blocchi Hero/Social esistenti salvati con `data.align` continuano a funzionare — i template li leggono come fallback se `style.align` non è impostato.

### Added — Admin shell

- **OSS: pill dominio sotto il logo `tylio.app`**. La pill di brand che fino a v0.3.1 esisteva solo nei tenant SaaS (mostrava lo slug) ora compare anche sull'OSS, mostrando `site.title` (o, fallback, l'hostname senza `www.`). Stesso treatment visivo, stessa abitudine per i self-hoster con più siti / staging.
- **Banner persistente "email admin"**: nell'header di ogni pagina admin appare un banner warn finché l'email admin non è impostata E verificata. Due testi:
  - `site.admin_email` vuoto → "Non è stata impostata una email per l'admin"
  - email settata ma `verified_at` null → "Non è stata verificata l'email {x}"
  Il banner è un `<router-link>` che porta in un click a Settings → sezione email. Persistente (no dismiss): l'email è il canale primario di password-reset e 2FA fallback, quindi resta visibile finché non è risolto.

### Changed — Login 2FA UI

- **Backup-codes**: il toggle checkbox "Usa un backup code (se hai perso l'app authenticator)" sopra il pulsante di accesso è stato rimosso e sostituito da un link testuale sotto il pulsante, nello stesso stile della prossima "Hai dimenticato la password" (preview). Toggle pulito che alterna TOTP ↔ backup mode senza perdere la sessione 2FA pendente.

### NOT included in this release

Punto 7 della lista (Forgot password flow completo: link + pagina + email temp pw + must_change_password) è rinviato a **v0.3.3**: richiede una migration users.must_change_password (da scrivere conditional per non collidere con la `1002` del SaaS overlay), un ChangePasswordController OSS (port da SaaS), un nuovo ForgotPasswordController, una vista SPA dedicata, e relativi locale strings — troppo per un'iterazione singola. La funzione `Mailer::sendPasswordReset()` esiste già, l'infrastruttura SaaS è già completa: il lavoro è isolato lato OSS.

## v0.3.1 — 2026-05-15

### Added — Aggiornamento in-app stile WordPress

Lo `UpdateChecker` mostrava già "Disponibile vX.Y.Z" ma per applicare serviva SSH manuale (`git pull`, `composer install`, `npm run build`, `php scripts/migrate.php`). Ora c'è il bottone **"Aggiorna ora"** nella card "Aggiornamenti tylio" di Settings che fa tutto da web GUI.

**Flusso quando l'admin clicca "Aggiorna ora":**
1. Server passa in maintenance mode automaticamente (visitatori vedono la pagina 503, l'admin resta loggato e vede il progress).
2. Scarica `tylio-source-vX.Y.Z.tar.gz` dall'asset della GitHub release.
3. Estrae in dir staging temporanea (`PharData`, zero shell dependency).
4. Backuppa la root corrente in `data/.backup/<old-version>-<timestamp>.tar.gz` (esclude `data/`, `uploads/`, `favicons/`, `.env` per tenere il backup small).
5. Swappa atomic-ish staging → root, preservando i dati runtime + `.env`.
6. Applica le migrazioni nuove (`Migrations::run`).
7. `opcache_reset()` così le next request usano il code nuovo.
8. Ripristina lo stato precedente di maintenance, persiste `site.last_update_at` / `site.last_update_version`.
9. La SPA refresha la dl delle versioni mostrando "Aggiornato a vX.Y.Z".

**Nuovo asset di release**: `tylio-source-vX.Y.Z.tar.gz` (full source + `vendor/` + `admin/` SPA precompilato). Lo `scripts/make-release.sh` lo genera per ogni release. Self-host con zero dipendenze runtime — non servono `composer` né `npm` sul server di destinazione, solo PHP.

**File nuovi/modificati:**
- `app/Database/migrations/0009_update_state.sql`: settings `site.update_in_progress`, `site.last_update_*`.
- `app/Services/UpdateApplier.php`: orchestrator (download + extract via PharData + backup + swap + migrate + opcache reset).
- `app/Controllers/UpdateController.php`: nuovi metodi `state()` (GET) e `apply()` (POST).
- `app/routes.php`: rotte `GET /api/admin/update/state`, `POST /api/admin/update/apply` sotto auth+CSRF.
- `admin-src/src/views/Settings.vue`: rimossa la sezione "How to update" con comandi shell, sostituita dal bottone "Aggiorna ora" con spinner + banner outcome (success verde / error rosso) + path del backup.
- `scripts/make-release.sh`: step 5b che genera il `tylio-source-*.tar.gz`.

**Sicurezza:**
- Auth + CSRF già coperti dal route group.
- Pre-flight check su write permission della root → errore esplicito se www-data non può scrivere.
- Maintenance mode forzato durante l'apply → niente request concorrenti che vedono code half-swapped.
- Backup automatico → rollback manuale possibile in caso di problemi: `tar xzf data/.backup/v0.3.0-...tar.gz -C /var/www/tylio/`.

**Sull'overlay SaaS (tylio.app):** `TenantUpdateController` disabilita anche `state` e `apply` (oltre al `check` già disabilitato). I tenant non possono toccare il codice OSS condiviso — l'aggiornamento del SaaS lo fa il superadmin via SFTP, come sempre.

**Limiti noti (questa è MVP):**
- `apply()` è sync nel request HTTP. Su un self-host lento il browser potrebbe timeout-are dopo 30-60s mentre lo swap continua server-side. Il `last_update_at` settato a fine ti dice comunque se è andato a buon fine.
- Niente rollback automatico in caso di crash mid-swap. La dir `.deprecated-*` con il code vecchio resta su disco fino al cleanup post-swap; in caso di crash mid-step, si rinomina a mano.
- Non supporta upgrade da versioni precedenti a v0.3.1 (le release < v0.3.1 non contengono l'asset `tylio-source-*.tar.gz`). Per maurizionatali.it / installazioni < v0.3.1: upgrade manuale a v0.3.1 una volta, poi auto-update da lì in poi.

## v0.3.0 — 2026-05-15

### Added — Block "group" container

- **Nuovo block type `group`**: contenitore che raccoglie 1–N tessere figlie e le dispone come un cluster coeso (es. 1 grande a sinistra + 2 stacked a destra). Migrazione `0008_group_blocks.sql` aggiunge `blocks.parent_id INTEGER NULL REFERENCES blocks(id) ON DELETE SET NULL` con index. `ON DELETE SET NULL` significa che eliminando un gruppo i figli vengono **staccati** al top-level invece di essere distrutti — niente perdita di dati.
- **Layout planner CSS Grid**: nuovo `Renderer::planLayout()` che cammina sui blocks, costruisce le righe della pagina, e per ogni cella emette un `grid-area` inline via CSS custom property `--ga`. Sul frontend pubblico il gruppo è **completamente invisibile**: niente wrapper, niente padding/margin, solo posizionamento via grid. L'utente finale vede solo le tessere.
- **Drag-and-drop cross-list in Dashboard admin**: trascina una tessera dentro un gruppo per attaccarla (`parent_id = group.id`), trascinala fuori per staccarla. Riordino dei figli funziona dentro al gruppo come al top-level. Workaround per bug noto di `vuedraggable` su drag cross-list: bypassiamo `@change` (che non si triggera correttamente) e usiamo direttamente gli eventi SortableJS `@add`/`@update` leggendo l'id del blocco trascinato da un attributo `data-block-id`.
- **Anti-folder-dodge**: trascinando una tessera *verso* un gruppo, il gruppo **non scivola via** (comportamento UX tipico di SortableJS che renderebbe impossibile droppare *dentro*). `:move` predicate veta lo swap quando il target è un gruppo e il dragged non lo è.
- **Server rifiuta gruppi annidati** (422): `BlocksController::create()`/`update()` verifica che `parent_id` non punti a un altro gruppo. La UI guarda anche lato suo per evitare di mostrare il pulsante "Aggiungi gruppo" dentro un gruppo.

### Added — Hero block "centered" option

- Nuovo campo `align` su hero (sinistra/centro) come già esisteva per il blocco social. Su desktop il blocco hero mantiene il suo split nativo (avatar | titolo / avatar | descrizione) — l'opzione "centro" sposta tutto il blocco al centro della propria riga senza stackare verticalmente. Su mobile il blocco è naturalmente centrato come prima.

### Fixed — Admin email verification UI

- Il badge **"Verificata"** non appare più quando `site.admin_email` è vuoto. Stato inconsistente possibile dopo signup malformato o cleanup parziale del DB (`admin_email=""` + `admin_email_verified_at` settato): ora la difesa è doppia.
  - UI (`Settings.vue`): `emailIsVerified` computed richiede **entrambe** le condizioni (`verified_at` non null **AND** `site.admin_email` non vuoto).
  - Server (`EmailVerificationController::status()`): se `email === ''` il payload restituisce `verified_at: null` forzato, anche se il setting persistito dice il contrario.

## v0.2.6 — 2026-05-15

### Fixed — tessera Link UX (round 3)

- **Favicon non più incapsulata in un quadrato colorato**. Tre varianti distinte per il container icona:
  - `--custom` (Iconify): sfondo `accent-soft` + glifo accent (invariato — è una glyph monocromatica, va bene tinta)
  - `--favicon`: **sfondo bianco sempre** (la superficie "attesa" per una favicon, indipendente dal tema del sito), favicon riempie 36×36 con `object-fit: contain` e `border-radius` solo sui bordi esterni del container
  - `--fallback` (no URL host o load fail): sfondo bianco + chain-link SVG in grigio neutro (`#666`) visibile su entrambi i temi
- **Icona è ora un link** (`<a>`) verso lo stesso URL del titolo. Mouse users possono cliccare sia il titolo che l'icona. `tabindex="-1"` + `aria-hidden="true"` evitano doppio tab-stop / lettura per screen reader (il titolo resta il link canonico).
- **Cursor `pointer` esplicito** su `.m-link__icon`, `.m-link__title` e `.m-badge--copy`. Alcuni reset CSS strippano il cursor di default sugli `<a>` — esplicitiamo per coerenza.
- **Hover del titolo: niente cambio colore**, solo `text-decoration: underline` con `text-decoration-color: currentColor`. Prima passava a `var(--accent)` che su palette estreme (accent bianco → invisibile) faceva sparire il testo.
- **Spazio verticale titolo→descrizione ridotto**: `line-height: 1.2` su `.m-link__label` e `1.25` su `.m-link__desc`.

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
