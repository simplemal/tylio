# Changelog

All notable changes to tylio are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


## v0.5.1 — 2026-05-18

### Fixed — `Ottimizza ora` ritornava `not_in_uploads` su qualche install

L'endpoint `POST /api/seo/og-image/optimize` (v0.5.0) leggeva `seo.og_image` da DB e applicava `parse_url + str_starts_with('uploads/')`. Su almeno un'install in produzione il check falliva con `not_in_uploads` anche se l'URL mostrato in UI era `/uploads/<filename>.jpg`. Plausibili cause concomitanti: virgolette JSON residue nel value DB (esiti di un round-trip serialize/deserialize), BOM UTF-8, whitespace leading/trailing dopo qualche migration o copia-incolla.

Fix:

- Il client (`OgImageUploader.vue`) ora passa `current_url` esplicitamente al backend (la fonte di verità è il `modelValue` visualizzato).
- Il backend accetta `current_url` dal body POST e ricade sul DB solo se non passato.
- Nuovo helper `MediaController::normalizeUrlValue($v)`: `trim` + strip BOM UTF-8 + strip outer JSON quotes (`"foo"` → `foo`) prima di `parse_url`. Gestisce silently i value "sporchi" venuti da migrations/round-trips.
- Su qualunque error path l'endpoint ora ritorna anche `{ seen: "..." }` con il valore che ha visto, così la prossima volta che fallisce l'utente ci passa il payload e capiamo a botto.

Stesso fix simmetrico sul SaaS (`TenantMediaController::optimizeOgImage`), eredita `normalizeUrlValue` dalla parent.

### Files

- `tylio/app/Controllers/MediaController.php` (normalizeUrlValue helper + accept current_url + error.seen)
- `tylio-platform/src/Controllers/TenantMediaController.php` (mirror tenant-scoped)
- `tylio/admin-src/src/api.ts` (`optimizeOgImage(currentUrl?)`)
- `tylio/admin-src/src/components/OgImageUploader.vue` (passa `props.modelValue`)


## v0.5.0 — 2026-05-18

### Added — auto-ottimizzazione delle OG image al caricamento + banner "Ottimizza esistente"

Le anteprime social (WhatsApp, iMessage, Telegram) **scartano** le immagini sopra ~600 KB e fanno fallback su un'altra immagine "rappresentativa" nella pagina: tipicamente un embed YouTube o un'icona casuale. Risultato osservato in produzione: condividi `ladyglow.it` su WhatsApp e vedi l'avatar del canale YouTube "Good Music" invece del logo del sito. Bug: chi carica un'og:image grande (es. 7 MB) non lo sa e l'unica traccia è la preview sbagliata.

Fix in due direzioni:

1. **Auto-ottimizza al caricamento**: quando carichi un'immagine in `Settings → SEO → Immagine social (Open Graph)`, il backend la ridimensiona a max 1200×630 (mantenendo aspect ratio, niente crop) e la riconvertendo come JPG quality 82. Il file finale sta tipicamente fra 100-300 KB. Helper nuovo: `Tylio\Util\ImageOptimizer::optimizeForOg($path)` (basato su `ext-gd`, già required).
2. **Banner per le og:image già caricate**: l'`OgImageUploader.vue` fa una `HEAD` request sull'URL corrente, legge `Content-Length`, e se l'immagine pesa > 600 KB mostra un banner ambra "Troppo grande per i social ({size}) — Ottimizza ora". Click → `POST /api/seo/og-image/optimize` → il backend prende il file referenced da `seo.og_image`, lo ricomprime e (se necessario) lo rinomina con estensione `.jpg`, aggiorna `media` table + `seo.og_image` con il nuovo URL.

### Files

- `tylio/app/Util/ImageOptimizer.php` — NEW (GD resize + JPG re-encode)
- `tylio/app/Controllers/MediaController.php` — `upload()` accetta `optimize_for=og`, nuovo metodo `optimizeOgImage()`
- `tylio/app/routes.php` — nuova route `POST /api/seo/og-image/optimize`
- `tylio-platform/src/Controllers/TenantMediaController.php` — override simmetrico tenant-scoped (`optimize_for=og` su `upload()`, nuovo `optimizeOgImage()` con scope `tenant_id`)
- `tylio-platform/src/routes.php` — wire della rotta tenant-scoped
- `tylio/admin-src/src/components/OgImageUploader.vue` — auto-detect oversize via HEAD + banner "Ottimizza ora"
- `tylio/admin-src/src/api.ts` — `uploadMedia(file, 'og')` + `optimizeOgImage()`
- `tylio/admin-src/src/style.css` — stile `.img-uploader__oversized`
- `tylio/admin-src/src/locales/{it,en}.json` — `media.ogOversized.*`

### Note operativa

Gli OG image già caricati > 600 KB **non vengono ricompressi automaticamente** al deploy v0.5.0: l'utente vedrà il banner ambra la prima volta che apre `Settings → SEO` e potrà cliccare **Ottimizza ora** per ricomprimere on-demand. Il file storico viene sovrascritto (stesso filename se già `.jpg`, oppure rinominato + setting aggiornato se era `.png/.webp/.gif`).


## v0.4.3 — 2026-05-18

### Fixed — `og:image` e `twitter:image` ora sono URL assoluti

Il template emetteva `<meta property="og:image" content="/uploads/...">` (path relativo). Open Graph richiede URL pieno con `https://...`: la maggior parte dei crawler social (WhatsApp, iMessage, Telegram preview-bot, …) ignora l'og:image se è relativo e fa fallback a un'altra immagine "rappresentativa" che trova nel body. Sul sito di un utente con un blocco `youtube` con `source_url` linkato, il fallback finiva spesso con l'OG del canale YouTube esterno — e la chat mostrava una preview completamente off-topic.

Fix in entrambi i layout (OSS `layout.php` + SaaS `site_layout.php`): se `seo.og_image` non comincia con `http(s)://`, prependiamo il canonical (settings) o `APP_URL` come base, restituendo un URL assoluto sia per `og:image` sia per `twitter:image`. Pure cosmetico: cast esplicito di `$ogImage` a string per quelle install che restituiscono `null` da `settingsValue()`.

### Files

- `tylio/app/Templates/layout.php`
- `tylio-platform/src/Templates/site_layout.php`


## v0.4.2 — 2026-05-18

### Added — pagina 404 OSS che usa il tema dell'utente

Prima l'OSS non aveva un handler dedicato per `HttpNotFoundException`: ogni rotta non matchata atterrava sul default Slim (HTML scarno, niente tema, niente CTA). Ora c'è una pagina 404 self-contained che:

- legge palette + fonts dal tema corrente del sito (palette `bg/surface/text/accent/border`, font heading + body), così è coerente con il resto del sito
- mostra il path richiesto in una pill quando è breve e leggibile, altrimenti fallback su messaggio generico
- ha un bottone primario "Torna alla home" → `/`
- nessuna dipendenza esterna oltre a Google Fonts (stesso pattern di `maintenance.php`)
- i18n it/en con stringhe hardcoded nel template (no I18n service necessario, la pagina funziona anche se il service è giù)
- header `Cache-Control: no-store` per non far cachare un 404 stale a CDN/browser quando l'utente crea la rotta dopo (es. nuovo blocco)

### Files

- `app/Templates/route_not_found.php` — NEW (self-contained, palette+font dal tema)
- `app/Services/Renderer.php` — nuovi metodi `renderNotFound(path, acceptLang)` + hook protetto `notFoundTemplatePath()` (lo overridiabile in TenantRenderer SaaS)
- `app/bootstrap.php` — wire `setErrorHandler(HttpNotFoundException, ...)` che richiama `renderer->renderNotFound()`


## v0.4.1 — 2026-05-18

### Fixed — footer pubblico "Powered by tylio" puntava all'org Anthropic

In `app/Locales/{it,en}.php` la key `public.footer.powered_by` aveva `href="https://github.com/anthropics"` invece di `https://github.com/simplemal/tylio`. Risultato: chiunque cliccava "Powered by tylio" nel footer di un sito tylio finiva sulla pagina GitHub di Anthropic. Errore introdotto in una mia traduzione e rimasto fino a oggi.


## v0.4.0 — 2026-05-18

### Fixed — Dashboard drag-into-group / drag-out-of-group (finalmente)

Migrazione del sub-container dei gruppi da `vuedraggable` (4.1.0, libreria abbandonata) a **`SortableJS` nativo**, montato via callback ref. Il top-level rimane su `vuedraggable` per il riordino.

Bug risolto: `vuedraggable` 4.1.0 non propaga `@add` ai callback Vue per item che arrivano da un'altra istanza (`evt.item._underlying_vm_` undefined → early-return interno). Risultato: drag-in nel gruppo non salvava `parent_id`, drag-out lasciava la tessera "fantasma" nel top-level (DOM aggiornato, ma `topLevel.value` reactive desincronizzato → la tessera non era più trascinabile fino a F5). I tentativi `v0.3.19`→`v0.3.23` (pre-alloc bucket, `:list`, `:group` object, migrazione `vue-draggable-plus`) operavano tutti sopra lo stesso adapter rotto e non risolvevano.

Architettura nuova:

- `Sortable.create(containerEl, { group: 'dash', onAdd, onRemove, onUpdate, ... })` per ogni `.dash-group__children`, montato in `registerGroupEl` (callback ref), distrutto a unmount (anche su delete del gruppo)
- `onAdd` (drag-in): legge `data-block-id` → `api.updateBlock(id, { parent_id: groupId })` → `reorder` letto dal DOM → `refresh()`
- `onRemove` (drag-out): se `evt.to` è `.dash-grid` → `api.updateBlock(id, { parent_id: null })` + reorder top-level + refresh. Se è un altro `.dash-group__children` → no-op (il target gestisce il proprio `onAdd`, niente race)
- `onUpdate` (riordino dentro un gruppo): reorder letto dal DOM + refresh
- `syncBuckets()` prealloca `childrenByParent[groupId] = []` per ogni gruppo, anche vuoto (binding `v-for` stabile)

### Added — dipendenza: `@types/sortablejs`

DevDep per il tipo `SortableEvent` usato nei callback. Runtime `sortablejs` è già stato nel bundle perché transitive di `vuedraggable`.

### Removed — codice morto post-refactor

`onTopLevelAdd`, `idFromEvent`, `DragEvt` type, `onGroupAdd`, `onGroupUpdate`, `makeGroupMove/Add/Update`, e i `console.log [dash]` diagnostici sono stati rimossi. Restano solo `console.error` sui catch.

### Files

- `admin-src/src/views/Dashboard.vue` — refactor sub-container a Sortable diretto
- `admin-src/package.json` + `package-lock.json` — `@types/sortablejs`


## v0.3.25 — 2026-05-17

### Added — Dashboard · "Sposta tessera esistente nel gruppo"

Dentro la card di ogni gruppo, in fondo, due bottoni in linea sostituiscono il singolo "Aggiungi al gruppo":

- **Aggiungi al gruppo** (ghost dashed, icona `folder-input`) — apre un picker delle tessere già esistenti fuori dai gruppi e le sposta dentro il gruppo selezionato (`PUT /api/blocks/{id}` con `parent_id`). Esclude gruppi e footer (regola server `group-in-group` / `footer-in-group`).
- **+ Aggiungi tessera** (primary) — crea una NUOVA tessera dentro il gruppo (comportamento del precedente "Aggiungi al gruppo").

Workaround funzionale al bug `drag-into-group` ancora sotto investigazione: l'utente non dipende più dal drag-and-drop per popolare un gruppo.

### Added — Dashboard · log diagnostici per il drag

`console.log` strategici sui handler vuedraggable (`@start`, `@end`, `@add`, `@update`, `@remove`, `@sort`, `:move`) sia top-level che per i sub-container dei gruppi. Prefisso `[dash]`. Servono a identificare la causa del bug per cui il drag di una tessera dentro un gruppo non emette `@add` sul sub-sortable in alcune condizioni. Saranno rimossi quando la radice del bug sarà tracciata.

### Files

- `admin-src/src/components/MoveExistingToGroupSheet.vue` — NEW (picker modal)
- `admin-src/src/views/Dashboard.vue` — due bottoni in linea + handler `openMoveExistingToGroup` / `pickMoveExisting` + log
- `admin-src/src/locales/{it,en}.json` — keys `moveExistingSheet.*`


## v0.3.24 — 2026-05-17

### Reverted — Dashboard drag-into-group: ripristino della versione che funzionava

`v0.3.19` → `v0.3.23` hanno provato a "fixare" un bug che non esisteva: il drag-into-group funzionava da `c74befb` (pre-`v0.3.7`). I miei tentativi (pre-alloc bucket → `:list` → `:group` object → migrazione a `vue-draggable-plus`) hanno rotto e regredito invece di aiutare.

`Dashboard.vue` checkout-ato dal commit `c74befb` ("fix(dashboard/groups): bypass vuedraggable @change in cross-list (read id from data-block-id + use @add/@update)"), `vue-draggable-plus` rimosso dalle dipendenze. Stack tornato a `vuedraggable` 4.1.0 com'era.

Se Maurizio vede ancora il drag-into-group rotto, è un bug LATO SERVER (SaaS o stato DB del tenant), non lato SPA. Quel codice ha funzionato per settimane.


## v0.3.23 — 2026-05-17

### Fixed — Drag-into-group: migrazione da `vuedraggable` a `vue-draggable-plus`

`vuedraggable` 4.1.0 (l'ultima release, abandoned da anni) emette `@remove` sul source con `to: dash-group__children` corretto, ma il sortable destinazione (children del group) non emette `@add`, perdendo il drop. Tentativi falliti: pre-alloc buckets stabili (v0.3.19), `:list` invece di `:model-value` (v0.3.21), `:group` esplicito object (v0.3.22). Causa confermata: bug interno di vuedraggable v4 con nested sortable in `#item` slot.

Migrazione a [`vue-draggable-plus`](https://github.com/Alfred-Skyblue/vue-draggable-plus) 0.6.1 — drop-in modernizzato:
- npm: `+ vue-draggable-plus`, `vuedraggable` resta come transitive ma non più importato.
- Import in `Dashboard.vue`: `import { VueDraggable as draggable } from 'vue-draggable-plus'`.
- Template: niente più `<template #item="{ element: X }">` con scoped slot — si usa `v-for` direct sui children del `<draggable>`. Più semplice + Vue tracking nativo dei key.
- `:list` su children sostituito da `v-model` (vue-draggable-plus richiede modelValue), con reactivity preservata via proxy del `ref<Record>`.
- Log diagnostici v0.3.20 lasciati ancora attivi per verificare in produzione.


## v0.3.22 — 2026-05-17

### Fixed — Drag-into-group: `:group` esplicito object invece di string

Continua il debug del bug. Log v0.3.20 confermano: drag-out emette correttamente `@add` su top-level + `@remove` su children-sortable. Drag-in invece emette `@remove` su top-level (con `evt.to = dash-group__children`) MA il children-sortable del group non emette `@add`. Cambiamenti precedenti (`:list` invece di `:model-value`) non hanno cambiato il sintomo.

vuedraggable 4.1.0 (l'ultima release, abandoned da anni) ha un noto handling fragile dello shorthand string per `:group`. Cambio entrambi i draggable a `:group="{ name: 'dash', pull: true, put: true }"` esplicito.

Se non basta, prossimo step: migrare a `vue-draggable-plus` (fork attivamente manutenuto) o usare SortableJS direttamente bypassando vuedraggable per il children-sortable.


## v0.3.21 — 2026-05-17

### Fixed — Drag-into-group: `:list` invece di `:model-value` per i children

I log della v0.3.20 hanno confermato che durante un drag dal top-level dentro un gruppo, top-level emette `@remove` correttamente ma il `<draggable>` dei children del gruppo NON emette `@add`. SortableJS riconosce il drop (vedi `@end.to = dash-group__children`), ma vuedraggable v4 non collega l'evento al model quando la prop è `:model-value` + `@update:model-value` su una proprietà annidata di `ref<Record>` (`childrenByParent[b.id]`).

Fix: switch a `:list="childrenByParent[b.id]"`. Con `:list` vuedraggable muta direttamente l'array in-place (no v-model dance) e SortableJS emette correttamente `onAdd` → `@add` → `makeGroupAdd` → `updateBlock(id, {parent_id})` ✓. La reactivity di Vue 3 traccia mutate su array ref-proxied senza problemi.

Console log diagnostici lasciati in build per ora — li rimuoverò una volta confermato il fix in produzione.


## v0.3.20 — 2026-05-17

### Changed — Dashboard drag: console.log su tutti gli eventi vuedraggable

Build di diagnostica: il fix v0.3.19 (pre-alloca bucket children) non era sufficiente per `drag-into-group` su SaaS ladyglow. Maggior numero di event listener loggati in console (`@start/@end/@choose/@add/@update/@remove` per ogni `<draggable>`) + dump dei buckets ad ogni `syncBuckets`. Da rimuovere nel prossimo cut una volta capita la causa root.


## v0.3.19 — 2026-05-17

### Fixed — Drag-into-group non triggava `@add`, parent_id mai aggiornato

Sintomo (Maurizio, tenant SaaS ladyglow): gruppo con 3 tile, ne tolgo uno via drag (esce — il PUT `/api/blocks/<id>` con `parent_id: null` parte correttamente), poi lo trascino di nuovo DENTRO il gruppo → nessuna chiamata XHR, il tile resta visivamente nel gruppo per un attimo poi rimbalza fuori al primo refresh. Verificato dalla Network del browser: zero PUT/POST sul drag-back-into.

Causa: `Dashboard.vue` legava `<draggable>` dei children con `:model-value="childrenByParent[b.id] || []"`. Il fallback inline `|| []` produce un array NUOVO ad ogni render quando il bucket è undefined/vuoto. vuedraggable v4 sotto usa SortableJS, che identifica la lista destinazione tramite la reference dell'array di model. Con un array che cambia identity, l'event `onAdd` di SortableJS viene perso → `@add` di vuedraggable non emette → `makeGroupAdd` non viene chiamato → niente updateBlock con il nuovo `parent_id`.

Fix in `syncBuckets()`: pre-allocazione di un array vuoto `[]` per ogni block di tipo `group`, indipendentemente dal fatto che abbia children. Plus, template `:model-value="childrenByParent[b.id]"` senza fallback — l'array è sempre presente con identity stabile. Reorder dentro al gruppo e drag-into-empty-group ora emettono gli eventi giusti.


## v0.3.18 — 2026-05-16

### Fixed — `attempt to write a readonly database` su shared-group setup

Sintomo (Maurizio, ladyglow.it): install fresh, prima richiesta HTTP a `/install`. Apache → PHP-FPM (www-data) → bootstrap → SQLite crea `data/db.sqlite` con mode 644. Subito dopo, una qualunque scrittura schema (es. `CREATE TABLE migrations`) fallisce: `PDOException SQLSTATE[HY000]: 8 attempt to write a readonly database`. Causa: il file appartiene al gruppo www-data (via setgid sul parent), ma 644 = group ha solo read.

Su hosting "shared group" (sftp user `ladyglow` + PHP-FPM `www-data` nello stesso gruppo `www-data`) la cosa peggiora: ogni file scritto da uno dei due è read-only per l'altro, e si rompe alternato.

Fix: `umask(0007)` come prima istruzione del bootstrap (OSS + SaaS). I file nuovi creati dal worker PHP nascono `660`, le dir nuove `770`, il setgid sul parent preserva il group. Idempotente: dove le permission erano già giuste non cambia niente; dove erano sbagliate il nuovo file le indirizza correttamente.

NB: i file PRE-ESISTENTI con mode 644 non vengono toccati. Su install fresh con `data/` vuota non è un problema; su istanze già attive con `db.sqlite` 644 serve un singolo `chmod 0664 data/db.sqlite` una tantum.


## v0.3.17 — 2026-05-16

### Fixed — `Migrations::run` skippava su stamp stale (DB ricreato a mano)

Sintomo (Maurizio, ladyglow.it): cancello `data/db.sqlite` per ripartire da zero, ricarico la home, vedo la landing "tylio non configurato", clicco `/install/import` e ricevo `SQLSTATE[HY000]: 1 no such table: blocks`.

Causa: la fast-path di `Migrations::run` confrontava solo il fingerprint dei file di migrazione su disco col `data/.migrations-stamp`. Lo stamp era rimasto dal vecchio DB: file = uguali, fingerprint = match, skip. Il nuovo `db.sqlite` veniva creato vuoto da PDO al primo `SELECT`, ma niente migration veniva applicata.

Fix: dopo il match dello stamp, controllo addizionale `SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'`. Se la tabella `migrations` non c'è, il DB è nuovo e si rilanciano tutte le migration (idempotenti, costo trascurabile). Niente più "no such table" su recovery che cancella il `db.sqlite` ma scorda lo stamp.


## v0.3.16 — 2026-05-16

### Fixed — Landing "install pending": usa il logo reale tylio

La landing introdotta in v0.3.15 mostrava un quadratino gradient con una "t" inventata invece dell'identità di brand. Ora il SVG inline del logo ufficiale (10 tessere colorate, stesso file di `/logo.svg` usato dalla SPA admin e dal layout pubblico).


## v0.3.15 — 2026-05-16

### Changed — Pagina di benvenuto quando l'install non è ancora stato completato

Prima: su un'install fresh prima di aver fatto `/install`, navigare alla home dava un Slim error generico ("A website error has occurred…") perché `PageController::home` provava a leggere blocks/settings da un DB che non esiste o senza utenti. Brutto sia come UX sia come "primo impatto" su un dominio appena live.

Fix: `PageController::home` ora controlla `installPending()` (= zero utenti o tabella `users` inesistente) e in quel caso serve una pagina HTML auto-contenuta con logo, copy "tylio è installato ma non configurato — completa il setup creando l'utente admin o importando un archivio" e bottone CTA verso `/install`. Inline CSS, niente asset esterni, robots `noindex`. Nessun cambiamento al flow normale.


## v0.3.14 — 2026-05-16

### Fixed — Export/Import non migravano gli utenti (post-import: login impossibile)

Sintomo (Maurizio, ladyglow.it): dopo `/install/import` di un archivio tenant SaaS, la login admin NON accettava username/password che funzionavano sul sorgente — perché la tabella `users` era rimasta vuota.

Causa: `Export::writeData` produceva un `data.json` con `blocks`, `theme`, `settings`, `media` ma NESSUN `users`. `Import` di conseguenza non aveva niente da inserire. Bug presente fin dalla prima versione di export/import (v0.2.x), emerso solo ora che qualcuno ha effettivamente fatto un round-trip completo.

Fix in 3 punti:
- `Export::exportUsers` (nuovo): seleziona `id, username, password_hash, totp_secret, totp_enabled_at, totp_backup_codes, created_at, last_login_at` e li serializza in `data['users']`. La password arriva già hashata (argon2id), nessun clear-text è mai esportato.
- `TenantExport::exportUsers` (SaaS overlay): override scoped su `tenant_id`, stessa lista colonne (la colonna SaaS-only `must_change_password` viene esclusa di proposito — perdere quel flag su un import OSS è il comportamento giusto: il nuovo install non ha quella nozione).
- `Import::insertUsers`: `DELETE FROM users` + INSERT delle righe dell'archivio, preservando l'ID per mantenere coerenti gli eventuali `audit_log.user_id`. Salta righe senza `username` o `password_hash`.

### Notes

- "Recupera password" sulla login: non implementato. `Mailer::sendPasswordReset()` esiste ma il flow UI (form + token + reset) è in TODO ([memory/tylio_todo.md](https://github.com/simplemal/tylio/issues)). Non risolvibile in questa patch.


## v0.3.13 — 2026-05-16

### Fixed — Export/Import non preservava `parent_id` (group blocks)

I block container di tipo `group` hanno children col `blocks.parent_id` settato (vedi migration 0008). Sia `Export::exportBlocks` (OSS) sia `TenantExport::exportBlocks` (SaaS overlay) NON includevano la colonna `parent_id` nel SELECT, e `Import::insertBlocks` non la riscriveva. Risultato: l'archivio portava i child blocks ma all'import perdevano il riferimento al group → diventavano top-level e il group restava vuoto. Layout sballato post-import (sintomo Maurizio su ladyglow.it).

Fix in 3 punti:
- `Export::exportBlocks` + `TenantExport::exportBlocks`: aggiunto `parent_id` al SELECT + al payload JSON. ORDER BY rivisto in modo che i parent vengano sempre prima dei children (`ORDER BY (parent_id IS NOT NULL), parent_id, position, id`).
- `Import::insertBlocks`: preserva l'ID source durante l'INSERT (così `parent_id` riferisce correttamente alle nuove righe) + seconda passata `UPDATE blocks SET parent_id = ?` per riconnettere children → parent dopo che tutte le righe sono materializzate.

### Fixed — `POST /install/import` rispondeva JSON al browser

Quando il form HTML del wizard (`/install`, seconda card "Or restore an existing site") faceva submit, il server rispondeva `Content-Type: application/json` con `{ok:true, summary:{…}}` — il browser mostrava il JSON crudo su pagina bianca invece di portare l'utente al sito ricostruito. Ora `handleUpload($fromInstall=true)` redirige con HTTP 303 a `/admin?imported=1`. La risposta JSON resta per il path `/admin/import` (chiamato dal SPA via fetch).


## v0.3.12 — 2026-05-16

### Fixed — Banner "email non verificata" non spariva dopo verify

Sintomo (Maurizio): verifico l'email con il codice, in Settings → Comunicazioni vedo subito il chip "Verificata", ma il banner shell-wide arancione "Non è stata verificata l'email…" sopra le pagine resta visibile. Cambio route, banner ancora là. Solo `Cmd+Shift+R` lo nasconde.

Causa: `Settings.vue::loadEmailVerification` aggiornava solo la `ref` locale del componente, non il Pinia store `useSite()` che alimenta `site.activeBanner` (il computed che AppShell legge per scegliere quale banner mostrare). Il chip in-page e il banner shell leggevano da due fonti distinte; solo il primo si refreshava.

Fix: dopo `api.emailVerificationStatus()` chiamo `site.setEmailStatus(email, verified_at)` — il store è già definito apposta in `stores/site.ts` (`setEmailStatus(email, verifiedAt)`), il bug era che Settings.vue non lo chiamava mai. Ora il banner sparisce in tempo reale.


## v0.3.11 — 2026-05-16

### Changed — Resend codice verifica email: cooldown 30 min → 5 min

`EmailVerification::RESEND_COOLDOWN` da 1800s a 300s. Mezz'ora bloccava lo user case "ho sbagliato a digitare il codice 5 volte → invalidato → devo aspettare 30 min prima di ricevere il nuovo" che è quasi sempre user-error e non scenario di attacco. 5 minuti è abbastanza per scoraggiare flooding outbound + tollera tipi-test umani.

### Changed — Messaggio rate-limited: countdown live mm:ss

Prima: "Aspetta: si può richiedere un codice nuovo ogni 30 minuti." (statico, inutile per capire quando riprovare). Ora: "Aspetta: puoi richiedere un nuovo codice tra X:XX." — il countdown è preso dal `pending.can_resend_at` server-side e si auto-aggiorna ogni secondo (`emailCooldownLabel` reactive su `emailCooldownTick`). Refactor SPA: `emailVerifyError` da `ref<string>` a `computed` che traduce una `kind` ref (`'codeWrong' | 'rateLimited' | …`) — l'i18n call avviene al render time, quindi il countdown rimane vivo finché l'errore è visibile.


## v0.3.10 — 2026-05-16

### Fixed — `site.admin_email_verified_at` cancellato dal Salva successivo

Sintomo: inserisco l'email admin, ricevo il codice, lo verifico (server marca `site.admin_email_verified_at = NOW()`), tick verde compare. Clicco "Salva" sul form Settings senza toccare l'email. Reload la pagina → tick sparito, l'email risulta di nuovo "da verificare".

Causa: `SettingsController::persistSettings` iterava ogni key del payload del SPA scrivendolo nel DB. Il SPA include `site.admin_email_verified_at` (lo legge insieme alle altre settings al mount) e dopo verify NON aggiorna il proprio state locale per quel campo — quindi sul successivo Save invia `null`, sovrascrivendo il timestamp appena scritto dal flow di verifica.

Fix: nuova costante `SettingsController::PROTECTED_SETTING_KEYS = ['site.admin_email_verified_at', 'site.welcome_sent_at']`. La loop di persist skippa quelle chiavi — vengono toccate ESCLUSIVAMENTE dai loro flow server-internal (verifica email, welcome mail). Lo stesso fix replicato in `TenantSettingsController` (SaaS overlay) referenziando la stessa costante.


## v0.3.9 — 2026-05-16

### Fixed — `UpdateApplier::downloadTarball`: cURL con fallback fopen

`fopen()` su stream HTTP non gestiva bene il redirect 302 di GitHub release-asset → S3 (su alcuni server PHP-FPM tornava `false` senza apparente motivo). Sostituito con `curl_init` + `CURLOPT_FOLLOWLOCATION` + maxredirs 5 + connect timeout 10s. Fallback su `fopen` se l'estensione cURL non è installata (raro). Stesso fix lato platform per `OssDependencyUpdater`.

### Fixed — `OssDependencyUpdater::tempBase`: cascade su 3 path

Il fix precedente puntava `tempBase` sotto `vendor/tylio/.tylio-update-tmp` per garantire stesso filesystem del target di swap, ma su molti deploy quella parent dir non è scrivibile da `www-data` → `mkdir` fallisce silent → tutto downstream esplode con "impossibile aprire ... in scrittura". Ora la funzione prova in cascata: `vendor/tylio/.tylio-update-tmp` → `data/.tylio-update-tmp` → `sys_get_temp_dir()/tylio-oss-update`, prende il primo writable. `safeMove` continua a gestire cross-fs nel caso peggiore.

### Fixed — `OssDependencyUpdater`: detail error message sul download fail

Il `download_failed` ora riporta `curl(#NN HTTP NNN)`, l'errore di sistema e l'URL effettivo (post-redirect). Niente più troubleshooting al buio: l'admin vede subito se è SSL, DNS, 404, ecc.

### Fixed — `Mailer::send`: preflight TCP + socket timeout 10s

`stream_socket_client("tcp://$host:$port", …, 5)` come check preliminare prima di passare a Symfony Mailer. Se l'host SMTP non risponde in 5s si torna `false` con detail leggibile invece di lasciare il worker PHP bloccato 30-60s sul TLS handshake (che su Cloudflare-fronted setup genera 502). Plus `ini_set('default_socket_timeout', 10)` per assicurarsi che TUTTI i syscall socket rispettino il cap.

### Fixed — SMTP test: fallback su `mail.from_address` se `site.admin_email` è vuoto

Il SPA ora passa esplicitamente al server `to = site.admin_email || mail.from_address`. Su tenant SaaS appena creati `site.admin_email` può essere vuoto: prima il test falliva con "Indirizzo destinatario non valido", ora invia un test al mittente che hai appena impostato.

### Added — `scripts/deploy-saas.sh` (platform)

Hotfix-style deploy verso `tylio.app` SaaS: sftp upload + stampa del blocco `sudo` da incollare in ssh. Usabile per ogni file modificato del repo platform — risparmia il pattern manuale "carica → chown → reload" che facevamo a mano ogni volta.


## v0.3.8 — 2026-05-16

### Fixed — SMTP test: timeout 10s e cap a 20s su PHP execution

`Mailer::dsn()` ora include `?timeout=10` (passa al Symfony SMTP transport) e `MailController::test()` chiama `set_time_limit(20)`. Prima un host SMTP irraggiungibile teneva il worker PHP impegnato fino al `max_execution_time` di default (>30s), spesso oltre il timeout dell'origin che fa rispondere 502 al reverse proxy (Cloudflare in questo caso) con "origin returned an invalid or incomplete response". Adesso il connect SMTP si arrende a 10s e il pipeline a 20s, restituendo un `{ok:false, error:'send_failed', detail:...}` JSON pulito che il SPA mostra inline.

### Fixed — Favicon upload da galleria (no_such_column: path)

`FaviconController::resolveInput()` cercava `SELECT path FROM media` ma la tabella `media` non ha mai avuto una colonna `path` (lo schema è `filename`). Effetto: scegliendo un'immagine da Media → "Scegli dalla libreria" usciva `SQLSTATE[HY000]: 1 no such column: path`. Fix banale: `path` → `filename`, identico a `MediaController`.

### Fixed — UpdateApplier: rename cross-fs ora non distrugge l'install

`swapInPlace()` ora chiama `safeMove()` (rename con fallback `copy + rmrf`) invece di `rename()` cieco. Se il `rename()` cross-fs fallisce, ricade automaticamente su una copia ricorsiva e cancellazione della sorgente. Inoltre se un singolo swap fallisce a metà, ROLLBACK automatico: rimette tutti i `.deprecated-*` al posto originale invece di lasciare un vendor con solo `.deprecated-*` e niente codice (che è esattamente il disastro che ha rotto tylio.app). Stesso fix applicato lato platform (`OssDependencyUpdater`).

### Changed — Sezione Aggiornamenti spostata in Manutenzione

La card "Aggiornamenti tylio" non vive più sopra le Impostazioni: l'admin la trova in Manutenzione, sotto il toggle del maintenance mode e l'esplicazione "About". Riduce il rumore in Settings (dove la card era visibile sempre, anche quando non si stava aggiornando nulla) e tiene insieme le due azioni operative ("metti offline" + "aggiorna codice").

### Changed — SMTP test fa auto-save prima del send

Il bottone "Invia email di prova" persisteva i campi prima di chiamare `/api/admin/mail/test`. Eliminato il classico "ho compilato tutto ma mi dice non configurato": l'admin non deve più cliccare "Salva" in cima alla pagina prima di testare. Il SPA passa anche `to` esplicito (= `site.admin_email` del tenant corrente) per evitare che su SaaS il MailController peschi un admin_email di un altro tenant via SELECT non-scoped.

### Changed — Toggle SMTP usa lo stile globale `.settings-switch`

Il toggle SaaS "Usa il tuo server di posta" era reso con un button custom Tailwind; ora usa lo stesso pattern `.settings-switch` del toggle Manutenzione, in modo che i colori (track/thumb) seguano il tema attivo invece di restare grigio fissi.

### Changed — Dropdown "Sicurezza" SMTP: tolto il duplicato porta

Le opzioni del select erano "STARTTLS (587)", "SMTPS (465)", "Nessuna (sconsigliato)" — la porta è già un campo separato a fianco, ripeterla nei nomi confonde. Ora: "STARTTLS", "SSL/TLS", "Nessuna (sconsigliato)".


## v0.3.7 — 2026-05-16

### Added — Toggle "Usa il tuo server di posta" (SaaS-driven)

Su SaaS i tenant tylio.app per default mandano email via il mailer centralizzato della piattaforma. Un nuovo toggle in Settings → SMTP "Usa il tuo server di posta per inviare le email" (default OFF) consente al singolo tenant di switcharsi a un SMTP custom. Su OSS standalone il toggle non appare: l'admin DEVE configurare SMTP (è l'unica via).

- Migration `0012_mail_use_custom.sql`: seed `mail.use_custom_smtp = false`.
- `Mailer::settingsBool` helper aggiunto (per la subclass platform `TenantMailer`).
- Mailer ctor props da `private` a `protected` (subclass access).
- `Settings.vue`: hostname-based `isSaas` → su SaaS box col toggle prima della card SMTP, su OSS sezione always-on. Banner shell-wide "SMTP non configurato" gated su SaaS dal toggle.

### Added — Link block: accordion sui sub-item

I link items del block `links` ora sono drawer collapsibili: header sempre visibile (drag + label cliccabile + delete), body con i sub-field appare solo se espanso. Al mount tutti chiusi; `addItem` apre il nuovo item. Header dinamico: `item.label` (se non vuoto) altrimenti `Link #N`. Risolve le tessere con tanti link non navigabili.

Generalizzato via `FieldDef.collapsible: bool` + `FieldDef.collapsible_label_field: string` — riusabile per altri block types con repeat. State locale al componente Field.vue, NON persistito (richiesta Maurizio: fresh chiuso a ogni mount).

### Changed — OSS pill: dominio invece del titolo sito

`AppShell.vue` mostra ora il bare hostname (`maurizionatali.it`) sotto il logo `tylio.app`, niente più fallback su `site.title`. Più utile per multi-environment (staging.example.com vs example.com).

### Changed — `m-app__name`: halo soft per leggibilità accent-su-tint

Il fondo card di `m-app` è un gradient dello stesso accent del titolo → su progetti con accent saturo (rosso, ecc.) il titolo si fondeva col fondo. Aggiunto `text-shadow` doppio dello stesso colore del fondo card (close + blurry) come halo: stacca le lettere quando serve, invisibile quando il contrasto è già buono.

### Fixed — `UpdateApplier`: temp dir sotto rootPath (no più cross-fs rename)

Su molti server `/tmp` e `/var/www/...` sono filesystem separati. `PharData::extractTo` su `/tmp` + swap-rename su `/var/www/.../vendor` falliva con "Invalid cross-device link" → swap parziale + lock stuck. Fix: `tempBase()` ritorna `$rootPath/data/.tylio-update-tmp/` — stesso filesystem del target di swap. `rename` intra-fs OK. (Stesso fix sul `OssDependencyUpdater` lato platform).

### Fixed — Favicon upload: error handling specifico + supporto `media_id`

`FaviconController` riscritto in try/catch step-by-step. Ogni failure point ha codice + `detail` italiano leggibile + HTTP status mirato. 13 codici: `no_input`, `media_not_found`, `media_file_missing`, `invalid_mime`, `image_decode_failed`, `gd_unavailable`, `dest_not_writable`, `resize_failed`, `write_failed`, `upload_failed`, `tmp_alloc_failed`, `db_failed`, `exception`. **BUG FIX**: l'endpoint ora supporta DAVVERO il path `media_id` (body JSON) che il "scegli da galleria" del SPA usa — prima leggeva solo `$_FILES['file']` e ritornava sempre `no_file`. `FaviconUploader.vue` legge `e.data.detail` per mostrare il messaggio del server.

### Fixed — Media → "Copia URL" copia URL assoluto

`Media.vue::copy()` faceva `clipboard.writeText(m.url)` dove `m.url` è path relativo (`/uploads/abc.jpg`). Inutilizzabile fuori dall'origin. Ora pre-pende `window.location.origin` → si copia `https://maurizionatali.it/uploads/abc.jpg`.

## v0.3.6 — 2026-05-16

### Added — Configurazione SMTP da Settings + endpoint test

Un install OSS fresh non aveva SMTP configurato → nessuna mail (verifica admin, welcome, password reset, notifiche form contatti) arrivava. Ora:

- **Migration `0011_mail_settings.sql`**: seed dei settings `mail.host`, `mail.port` (`587`), `mail.security` (`tls`), `mail.user`, `mail.pass`, `mail.from_address`, `mail.from_name`, `mail.privacy_address`, `mail.support_address`. Tutti vuoti tranne `port`/`security`.
- **`Mailer` refactored**: nuovo `dsn()` che costruisce `smtp://USER:PASS@HOST:PORT?encryption=tls` (o `smtps://` per `ssl`) da settings se `mail.host` non vuoto, altrimenti fallback su env `MAIL_DSN`. Tutti i metodi `fromAddress/fromName/privacyAddress/supportAddress` leggono da settings con fallback env. Param `?DB $db` aggiunto al ctor (nullable per back-compat).
- **`MailController` nuovo**: `POST /api/admin/mail/test` invia una mail di prova all'admin email (o `body.to`) e ritorna `{ok: true, to}` o `{ok: false, error, detail}` con detail = `$mailer->lastError()` — niente più bisogno di `tail -f data/logs/mail.log` per capire perché SMTP non gira.
- **`Settings.vue` → nuova sezione SMTP** PRIMA di `#email`: host/port/security/user/pass/from_address/from_name + bottone "Invia email di prova" con outcome inline (verde/rosso).

Tested e2e locally con un PHP driver: empty→disabled; settings filled→DSN URL-encodato correttamente; clear→fallback env; ssl→`smtps://`.

### Added — Priorità banner shell admin

Quando il banner "SMTP non configurato" e quello "email admin non impostata/non verificata" sarebbero entrambi attivi, l'admin shell ne mostra **solo uno** — quello a priorità più alta. Ordine:

1. **SMTP non configurato** (blocca tutto il flusso email — gli altri warning sarebbero unactionable)
2. **Email admin non impostata**
3. **Email admin impostata ma non verificata**

`stores/site.ts` ha i nuovi getter `needsSmtpConfig` + `activeBanner` (computed). `AppShell.vue` rende UN solo `<router-link>` basato sul valore.

### Changed — Palette "Pink Lady · chiaro" ribilanciata

`admin-src/src/presets.ts`: `surface_alt` ora `#ffffff` (era `#efeff1`), `accent_soft` `#d45898` (era `#ff68b4` — diventa il contrasto sull'accento principale bianco), `accent_alt` `#f9d3e0` (era `#ffffff` — vero accento secondario rosa pastello), `accent_alt_fg` `#9a244f` (era `#ff68b4` — contrasto bordeaux sul secondario). Testo magenta su carta, accento bianco con testo rosa, badge rosa pallido con testo bordeaux.

### Changed — `tile.mobile_spacing: minimal` default per fresh install

`app/Database/migrations/0001_initial.sql`: il theme seedato sui nuovi install ora include `mobile_spacing: 'minimal'` — tessere edge-to-edge su mobile. Gli install esistenti non sono toccati (no backfill: chi aveva `desktop` l'aveva scelto consapevolmente). Per tornare al vecchio comportamento: Tema → Tile → spaziatura mobile = `desktop`.

### Fixed — Bordo dei sub-item nel field `repeat`

Sui temi dove `surface_alt` collassa su `surface` (es. Pink Lady · light: entrambi `#ffffff`), i box dei sub-item del field `repeat` (es. profili social, link items) scomparivano: `bg-ink-800 border border-white/5` non aveva contrasto. `admin-src/src/components/Field.vue`: rimosso `border border-white/5`, aggiunta classe `repeat-item-card` con `border: 1px solid rgb(var(--ink-100-rgb) / 0.5)` — sottile sui temi normali, distinguibile sui temi estremi.

### Fixed — Banner email non scrollava alla sezione

Il `router-link` del banner email (AppShell) puntava a `{name:'settings', hash:'#email'}` ma:
- `router.ts` non aveva `scrollBehavior` configurato → il hash veniva ignorato.
- `Settings.vue` aveva `id="communications"` invece di `email` → niente target.

`router.ts`: nuovo `scrollBehavior` che gestisce il hash con 50ms di delay per il mount async + 80px offset dalla sticky topbar. `Settings.vue`: aggiunto `<div id="email" class="scroll-mt-24">` come anchor sibling sopra `<section id="communications">` (no rename per non rompere riferimenti futuri).

## v0.3.5 — 2026-05-16

### Fixed — "Versione installata" mostrava `build-<timestamp>` invece del semver

Bug visibile nella card "Aggiornamenti tylio" in Settings: dopo un'installazione (manuale o via "Aggiorna ora"), il campo "Versione installata" leggeva `build-2026-05-16-073343` invece di `v0.3.4`. `Util\Build::candidatePaths()` cerca `.version` (timestamp del deploy, usato come cachebuster) prima di `BUILD` (semver tag della release), e `UpdateChecker::currentVersion()` delegava a quel lookup. Effetto collaterale serio: il compare semver trattava `build-…` come "più vecchio di qualsiasi tag" → `"Aggiorna ora"` restava sempre disponibile anche su install già aggiornate.

Fix: `UpdateChecker::currentVersion()` adesso legge `BUILD` direttamente come prima fonte se contiene un semver. `Util\Build::version()` resta come fallback (è il cachebuster, vuole un valore che cambia ad ogni deploy — il timestamp è giusto lì).

### Tested end-to-end before publishing

Per la prima volta da v0.3.1, ho fatto un test integrato locale di `apply()` prima di pubblicare: install fake a `/tmp/tylio-e2e/install` con BUILD=v0.3.4, driver PHP che istanzia `UpdateApplier` reale, chiama `apply()`, verifica backup + swap + migrate + settings persisted. Risultato: 2.02s, peak memory 12MB, backup ok (1.4MB .tar.gz), DB pulito, swap riuscito. Stop alle release alla cieca.

## v0.3.4 — 2026-05-16

### Fixed — In-app updater OOM-killed mid-backup, leaving lock stuck

Su un install OSS reale (21MB di source, `memory_limit = 128M`), il primo "Aggiorna ora" arrivava fino allo step di backup, poi PHP veniva killato dal kernel/PHP-FPM dentro `PharData::compress(\Phar::GZ)` perché Phar carica l'intero archivio in memoria. Il SIGKILL bypassa il `finally` → `site.update_in_progress = true` resta forever → l'utente non può ritentare senza fix SQL manuale.

Quattro fix:

1. **`backupCurrentRoot()` ora preferisce shell `tar -czf`** quando disponibile (check `function_exists('exec')` + `disable_functions` + `command -v tar`). Tar shell streamma senza buffering — zero memoria, 5-10× più veloce, niente OOM. `PharData` resta come fallback su host che disabilitano `exec()`.

2. **`set_time_limit(0)` + bump di `memory_limit` a 256M** all'inizio di `apply()`. Niente più timeout o memory-cap mid-flight.

3. **`register_shutdown_function` rilascia il lock anche su PHP fatal kill** (OOM, `request_terminate_timeout`, segfault). La maintenance mode INTENZIONALMENTE resta ON dopo un kill — uno stato semi-swappato non va servito; l'admin la sblocca dalla pagina Manutenzione una volta verificato.

4. **SPA `applyUpdate` legge `e.data.detail`** dal body della risposta invece di mostrare solo `Errore (500)`. Il messaggio diagnostico arriva all'utente in chiaro. Refresha anche `updateState` su failure così il banner persistente riflette l'errore vero del server.

**Per installazioni già a v0.3.1-v0.3.3 con lock stucked** (`update_in_progress = true` permanente):

```sql
UPDATE settings SET value = json('false') WHERE key = 'site.update_in_progress';
```

E rimuovere eventuali backup parziali in `data/.backup/*.tar` (senza estensione `.gz`).

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
