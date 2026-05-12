<?php
declare(strict_types=1);

/**
 * Italian locale for server-rendered public pages and PHP-emitted
 * user-facing strings.
 *
 * See `en.php` for the canonical key set; any key missing here falls
 * back to English at runtime (see `I18n::t`).
 */

return [
    // -----------------------------------------------------------------
    // Media — messaggi di errore upload (lato server)
    // -----------------------------------------------------------------
    'media.upload.error.ini_size' => 'Il file supera la dimensione massima di upload del server. Riducilo o usa un\'immagine più leggera.',
    'media.upload.error.form_size' => 'Il file supera la dimensione massima consentita dal form.',
    'media.upload.error.partial' => 'Il file è stato caricato solo parzialmente. Riprova.',
    'media.upload.error.no_file' => 'Nessun file caricato.',
    'media.upload.error.no_tmp_dir' => 'Il server non ha una cartella temporanea disponibile. Contatta il supporto.',
    'media.upload.error.cant_write' => 'Impossibile scrivere il file su disco. Contatta il supporto.',
    'media.upload.error.extension' => 'Un\'estensione PHP ha bloccato l\'upload.',
    'media.upload.error.unknown' => 'Errore sconosciuto durante l\'upload.',
    'media.upload.error.too_large' => 'Il file è troppo grande (max {mb} MB).',

    // -----------------------------------------------------------------
    // Settings — messaggi di validazione lato server
    // -----------------------------------------------------------------
    'settings.errors.invalid_locale' => 'Codice lingua non valido. Usa 2 lettere (es. "it") o 2-2 (es. "it-IT").',
    'settings.errors.invalid_canonical_url' => 'URL canonico deve iniziare con http:// o https://.',
    'settings.errors.invalid_email' => 'Indirizzo email non valido.',

    // -----------------------------------------------------------------
    // Public site — empty page, lightbox, accessibility
    // -----------------------------------------------------------------
    'public.empty.title' => 'Benvenuto in <em>tylio</em>',
    'public.empty.message' => 'La pagina è vuota. Accedi all\'area admin per comporre la prima tessera.',
    'public.lightbox.close' => 'Chiudi',
    'public.powered_by' => 'Powered by tylio',

    // Admin-only banner shown at the top of the public site when
    // maintenance mode is on AND the visitor is the logged-in admin.
    'public.maintenance_banner.title' => 'Sito in manutenzione',
    'public.maintenance_banner.body' => 'I visitatori vedono la pagina di manutenzione. Tu, da loggato, vedi il sito normalmente per testarlo.',
    'public.maintenance_banner.action' => 'Gestisci',

    // -----------------------------------------------------------------
    // Public site — contact form (block-rendered)
    // -----------------------------------------------------------------
    'public.contact.send' => 'Invia',
    'public.contact.sending' => 'Invio…',
    'public.contact.success' => 'Messaggio inviato. Grazie!',
    'public.contact.error' => 'Qualcosa è andato storto. Riprova.',
    'public.contact.captcha_working' => 'Verifica anti-bot in corso…',
    'public.contact.captcha_working_sec' => 'Verifica anti-bot in corso… ({sec}s)',
    'public.contact.captcha_ok' => '✓ Verificato',
    'public.contact.captcha_failed' => 'Verifica fallita. Ricarica la pagina.',
    'public.contact.captcha_not_ready' => 'Verifica anti-bot non ancora completata, attendi un istante…',
    'public.contact.error_generic' => 'Si è verificato un errore. Riprova.',
    'public.contact.error_network' => 'Connessione interrotta. Riprova.',
    'public.contact.default_title' => 'Scrivimi',
    'public.contact.default_success' => 'Grazie, ti rispondo a breve.',

    // -----------------------------------------------------------------
    // Public site — block defaults (CTA labels, fallbacks)
    // -----------------------------------------------------------------
    'public.products.default_cta' => 'Acquista',
    'public.products.copy_code' => 'Clicca per copiare il codice',
    'public.embed.invalid_url' => 'URL embed non valido o non riconosciuto.',
    'public.youtube.unavailable' => 'Ultimo video momentaneamente non disponibile',
    'public.youtube.open_on_yt' => 'apri su YouTube',
    'public.social.website' => 'Sito web',
    'public.social.email' => 'Email',
    'public.social.phone' => 'Telefono',
    'public.social.rss' => 'RSS',
    'public.social.other' => 'Link',

    // -----------------------------------------------------------------
    // Public site — footer
    // -----------------------------------------------------------------
    'public.footer.powered_by' => 'Powered by <a href="https://github.com/anthropics" rel="noopener">tylio</a>',

    // -----------------------------------------------------------------
    // Service shells: placeholder, 503, 404
    // -----------------------------------------------------------------
    'shell.placeholder.title' => 'tylio',
    'shell.placeholder.body' => 'Sito in arrivo.',
    'shell.unavailable.title' => 'Servizio non disponibile',
    'shell.unavailable.body' => 'Il sito è temporaneamente non disponibile. Riprova più tardi.',
    'shell.not_found.title' => 'Pagina non trovata',
    'shell.not_found.body' => 'La pagina che cerchi non esiste.',
    'shell.not_found.back_home' => 'Torna alla home',

    // -----------------------------------------------------------------
    // Email transazionali (servizio Mailer). La locale attiva per ogni
    // send() è risolta da `settings.site.locale`. I body HTML mantengono
    // la palette Dracula inline tramite placeholder per non sporcare la
    // copia con valori esadecimali ripetuti.
    // -----------------------------------------------------------------
    'mail.invite.subject' => 'Il tuo nuovo sito su {brand} è pronto',
    'mail.invite.body_text' => <<<TXT
    Ciao,

    ti è stato creato un sito personale su {brand}:
      {url}

    Per accedere al pannello admin:
      {admin_url}

    Credenziali iniziali:
      Username: {username}
      Password temporanea: {temp_password}

    Al primo accesso ti chiederemo automaticamente di scegliere una nuova
    password (minimo 10 caratteri). Quella che vedi sopra è temporanea e
    diventa inutilizzabile subito dopo il cambio.

    Sicurezza:
    — la password ricevuta NON è conservata in chiaro sui nostri server
      (DB ha solo l'hash Argon2id, non reversibile);
    — questa email è l'unico posto dove la temporanea esiste in chiaro:
      cancellala dopo il primo login.

    Dopo il cambio password puoi personalizzare il tema, aggiungere le tue
    tessere (link, bio, social, gallery, ecc.) e caricare un'immagine.

    Per supporto: {support}
    Privacy/dati: {privacy}

    — {brand}
    TXT,
    'mail.invite.body_html' => <<<HTML
    <!doctype html>
    <html lang="{lang}">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <meta name="x-apple-disable-message-reformatting">
      <title>Il tuo sito su {brand} è pronto</title>
    </head>
    <body style="margin:0;padding:0;background:#1a1c25;color:#f8f8f2;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#1a1c25;padding:32px 12px;">
        <tr>
          <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:#282a36;border-radius:14px;border:1px solid rgba(248,248,242,0.12);overflow:hidden;">
              <tr>
                <td style="padding:28px 32px 8px;">
                  <div style="font-size:14px;letter-spacing:.04em;text-transform:uppercase;color:#97a3c2;margin-bottom:6px;">{brand}</div>
                  <h1 style="margin:0;font-family:Georgia,'Times New Roman',serif;font-weight:600;font-size:26px;line-height:1.2;color:#f8f8f2;">
                    Il tuo sito è pronto
                  </h1>
                  <p style="margin:10px 0 0;color:#97a3c2;font-size:15px;line-height:1.5;">
                    Ciao, abbiamo creato per te <a href="{url}" style="color:#bd93f9;text-decoration:none;font-weight:600;">{site_label}</a>.
                  </p>
                </td>
              </tr>
              <tr>
                <td style="padding:24px 32px 8px;">
                  <h2 style="margin:0 0 8px;font-family:Georgia,serif;font-size:18px;color:#bd93f9;">1. Accedi al pannello admin</h2>
                  <p style="margin:0 0 14px;color:#f8f8f2;font-size:15px;line-height:1.5;">
                    Al primo accesso ti chiederemo automaticamente di scegliere una nuova password. Quella qui sotto è temporanea e diventa inutilizzabile subito dopo il cambio.
                  </p>
                  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="background:#1a1c25;border:1px solid rgba(248,248,242,0.12);border-radius:10px;width:100%;">
                    <tr>
                      <td style="padding:14px 16px;">
                        <div style="color:#97a3c2;font-size:12px;letter-spacing:.04em;text-transform:uppercase;">Username</div>
                        <div style="color:#f8f8f2;font-family:'SF Mono',Menlo,Consolas,monospace;font-size:15px;margin-top:2px;">{username}</div>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:0 16px 14px;">
                        <div style="color:#97a3c2;font-size:12px;letter-spacing:.04em;text-transform:uppercase;">Password temporanea</div>
                        <div style="color:#ff79c6;font-family:'SF Mono',Menlo,Consolas,monospace;font-size:18px;margin-top:2px;font-weight:600;letter-spacing:.05em;">{temp_password}</div>
                      </td>
                    </tr>
                  </table>
                  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px;">
                    <tr>
                      <td style="background:#bd93f9;border-radius:10px;">
                        <a href="{admin_url}" style="display:inline-block;padding:12px 22px;color:#1a1c25;font-weight:600;font-size:15px;text-decoration:none;">Vai al pannello admin →</a>
                      </td>
                    </tr>
                  </table>
                  <p style="margin:14px 0 0;color:#97a3c2;font-size:13px;line-height:1.5;">
                    Oppure copia e incolla nel browser:<br>
                    <span style="color:#97a3c2;font-family:'SF Mono',Menlo,Consolas,monospace;font-size:12px;word-break:break-all;">{admin_url}</span>
                  </p>
                </td>
              </tr>
              <tr>
                <td style="padding:20px 32px 8px;">
                  <h2 style="margin:0 0 8px;font-family:Georgia,serif;font-size:18px;color:#bd93f9;">2. Inizia a costruire</h2>
                  <p style="margin:0;color:#f8f8f2;font-size:15px;line-height:1.6;">
                    Dopo aver impostato la tua password entri nel pannello admin e puoi:
                  </p>
                  <ul style="margin:8px 0 0;padding-left:20px;color:#f8f8f2;font-size:15px;line-height:1.6;">
                    <li>Personalizzare tema e palette</li>
                    <li>Comporre la tua home a tessere (link, bio, social, gallery, contatti, ecc.)</li>
                    <li>Caricare avatar e favicon</li>
                  </ul>
                </td>
              </tr>
              <tr>
                <td style="padding:20px 32px 24px;">
                  <h2 style="margin:0 0 8px;font-family:Georgia,serif;font-size:18px;color:#bd93f9;">Sulla sicurezza</h2>
                  <p style="margin:0;color:#97a3c2;font-size:13px;line-height:1.6;">
                    La password ricevuta non è memorizzata in chiaro sui nostri server (DB conserva solo
                    l'hash Argon2id, non reversibile). Questa email è l'unico posto dove la temporanea
                    esiste leggibile: cancellala dopo il cambio. Una volta sostituita, è inutilizzabile.
                  </p>
                </td>
              </tr>
              <tr>
                <td style="padding:18px 32px 24px;border-top:1px solid rgba(248,248,242,0.12);">
                  <p style="margin:0;color:#97a3c2;font-size:12px;line-height:1.6;">
                    Supporto: <a href="mailto:{support}" style="color:#bd93f9;text-decoration:none;">{support}</a>
                    &nbsp;·&nbsp;
                    Privacy: <a href="mailto:{privacy}" style="color:#bd93f9;text-decoration:none;">{privacy}</a>
                  </p>
                  <p style="margin:8px 0 0;color:#97a3c2;font-size:12px;">— {brand}</p>
                </td>
              </tr>
            </table>
            <p style="max-width:560px;margin:14px auto 0;color:#97a3c2;font-size:11px;line-height:1.5;text-align:center;">
              Hai ricevuto questa email perché qualcuno ha creato il sito {site_label} indicando il tuo indirizzo. Se non eri tu, ignora questa email — senza la password temporanea non si può accedere al pannello.
            </p>
          </td>
        </tr>
      </table>
    </body>
    </html>
    HTML,

    'mail.contact_notification.subject' => 'Nuovo messaggio dal sito {host}',
    'mail.contact_notification.body_text' => <<<TXT
    Hai ricevuto un nuovo messaggio dal form Contatti.

    {fields}

    —
    Sito: {host}
    {ip_line}Tessera: #{block_id}
    TXT,
    'mail.contact_notification.ip_line' => "IP: {ip}\n",
    'mail.contact_notification.no_fields' => 'Il form non ha trasmesso campi.',
    'mail.contact_notification.ip_label' => 'IP: {ip}',
    'mail.contact_notification.reply_hint_html' => 'Premi <strong>Rispondi</strong> nel tuo client di posta: la mail partirà direttamente verso <a href="mailto:{email}" style="color:{accent};text-decoration:none;">{email}</a>.',
    'mail.contact_notification.reply_none_html' => 'Il messaggio non contiene un indirizzo email — non puoi rispondere direttamente.',
    'mail.contact_notification.quote_header' => 'Il {date}, {name} ha scritto:',
    'mail.contact_notification.body_html' => <<<HTML
    <!doctype html>
    <html lang="{lang}">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <meta name="x-apple-disable-message-reformatting">
      <title>Nuovo messaggio dal sito {host}</title>
    </head>
    <body style="margin:0;padding:0;background:{bg};color:{text};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{bg};padding:32px 12px;">
        <tr>
          <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:{surface};border-radius:14px;border:1px solid {border};overflow:hidden;">
              <tr>
                <td style="padding:28px 32px 8px;">
                  <div style="font-size:14px;letter-spacing:.04em;text-transform:uppercase;color:{muted};margin-bottom:6px;">{brand}</div>
                  <h1 style="margin:0;font-family:Georgia,'Times New Roman',serif;font-weight:600;font-size:26px;line-height:1.2;color:{text};">
                    Nuovo messaggio
                  </h1>
                  <p style="margin:10px 0 0;color:{muted};font-size:15px;line-height:1.5;">
                    Hai ricevuto un nuovo messaggio dal form contatti del sito <span style="color:{accent};font-weight:600;">{host}</span>.
                  </p>
                </td>
              </tr>
              <tr>
                <td style="padding:14px 32px 0;">
                  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="background:{bg};border:1px solid {border};border-radius:10px;width:100%;">
                    {rows_html}
                  </table>
                  <p style="margin:14px 0 0;color:{muted};font-size:13px;line-height:1.5;">{reply_html}</p>
                </td>
              </tr>
              <tr>
                <td style="padding:18px 32px 24px;border-top:1px solid {border};">
                  <div style="color:{muted};font-size:12px;line-height:1.6;">
                    Tessera: #{block_id}{ip_line_html}
                  </div>
                  <p style="margin:10px 0 0;color:{muted};font-size:12px;line-height:1.6;">
                    Supporto: <a href="mailto:{support}" style="color:{accent};text-decoration:none;">{support}</a>
                    &nbsp;·&nbsp;
                    Privacy: <a href="mailto:{privacy}" style="color:{accent};text-decoration:none;">{privacy}</a>
                  </p>
                  <p style="margin:8px 0 0;color:{muted};font-size:12px;">— {brand}</p>
                </td>
              </tr>
            </table>
            <p style="max-width:560px;margin:14px auto 0;color:{muted};font-size:11px;line-height:1.5;text-align:center;">
              Ricevi questa email perché qualcuno ha compilato il form contatti del sito {host}. Il messaggio è anche salvato nell'admin di {brand} (sezione Messaggi).
            </p>
          </td>
        </tr>
      </table>
    </body>
    </html>
    HTML,

    'mail.password_reset.subject' => 'Reimposta la password del tuo sito {brand}',
    'mail.password_reset.body_text' => <<<TXT
    Ciao,

    Hai richiesto di reimpostare la password per:
      sito: {site_url}
      username: {username}

    Apri questo link entro {expires_minutes} minuti per scegliere una nuova password:
      {reset_url}

    Se non hai richiesto tu il reset puoi ignorare questa mail. Il link
    scade da solo e il tuo account rimane invariato.

    — {brand}
    TXT,

    // -----------------------------------------------------------------
    // Relative-date helper (Renderer::relativeDate)
    // -----------------------------------------------------------------
    'relative.future' => 'in arrivo',
    'relative.now' => 'ora',
    'relative.minutes_ago' => '{n} min fa',
    'relative.hours_ago' => '{n} ore fa',
    'relative.yesterday' => 'ieri',
    'relative.days_ago' => '{n} giorni fa',
    'relative.weeks_ago' => '{n} sett. fa',
    'relative.months_ago' => '{n} mesi fa',
    'relative.years_ago' => '{n} anni fa',

    // -----------------------------------------------------------------
    // Block registry — admin SPA "Add tile" sheet + field editor.
    // Keys are emitted by `Tylio\Services\BlockRegistry` and resolved
    // at the controller boundary (`TypesController`, `BlocksController`).
    // -----------------------------------------------------------------

    // Category namespaces
    'blocks.categories.identity' => 'identità',
    'blocks.categories.content' => 'contenuto',
    'blocks.categories.action' => 'azione',
    'blocks.categories.structure' => 'struttura',

    // ---------- hero ----------
    'blocks.hero.label' => 'Hero',
    'blocks.hero.description' => 'Pannello di apertura con avatar, titolo e sottotitolo.',
    'blocks.hero.fields.avatar.label' => 'Avatar / logo',
    'blocks.hero.fields.avatar.help' => 'Verrà mostrato circolare. Le aree fuori dal cerchio sono evidenziate in anteprima.',
    'blocks.hero.fields.title.label' => 'Titolo',
    'blocks.hero.fields.title.default' => 'Ciao, sono…',
    'blocks.hero.fields.title.placeholder' => 'Nome / claim breve',
    'blocks.hero.fields.title.help' => 'Mostrato come grande titolo della home. Se carichi anche un\'immagine sotto (Titolo grafico), il testo qui diventa la descrizione "alt" dell\'immagine per accessibilità e SEO — puoi sempre lasciarlo compilato.',
    'blocks.hero.fields.title_image.label' => 'Titolo grafico (opzionale)',
    'blocks.hero.fields.title_image.help' => 'Carica un\'immagine (es. logotipo o titolo stilizzato) che SOSTITUISCE il testo del titolo nella home. Il testo del campo "Titolo" viene usato come alt/title dell\'immagine — riempilo comunque per accessibilità e SEO. Lascia vuoto per usare solo il testo.',
    'blocks.hero.fields.subtitle.label' => 'Sottotitolo / Biografia',
    'blocks.hero.fields.subtitle.placeholder' => 'Una o due righe che ti descrivono. **Grassetto**, *corsivo*, [link](https://...) supportati.',
    'blocks.hero.fields.subtitle.help' => 'Testo libero in Markdown. Esempi: **grassetto**, *corsivo*, [scrivi qui il testo](https://link), `codice`, > una citazione, e all\'inizio di una riga - punto elenco. Vedi la legenda completa qui sotto.',

    // ---------- links ----------
    'blocks.links.label' => 'Link',
    'blocks.links.description' => 'Lista di link cliccabili (linktree-style), con icone e badge.',
    'blocks.links.fields.title.label' => 'Titolo sezione',
    'blocks.links.fields.title.default' => 'Link',
    'blocks.links.fields.items.label' => 'Link',
    'blocks.links.fields.items.of.label.label' => 'Testo',
    'blocks.links.fields.items.of.url.label' => 'URL',
    'blocks.links.fields.items.of.icon.label' => 'Icona',
    'blocks.links.fields.items.of.icon.help' => 'Nome Iconify, es. lucide:globe',
    'blocks.links.fields.items.of.badge.label' => 'Badge',
    'blocks.links.fields.items.of.badge.help' => 'Es. "novità"',
    'blocks.links.fields.items.of.description.label' => 'Descrizione',

    // ---------- apps ----------
    'blocks.apps.label' => 'App / Progetti',
    'blocks.apps.description' => 'Card grandi per progetti / app: icona, nome, descrizione, link, store badge.',
    'blocks.apps.fields.title.label' => 'Titolo sezione',
    'blocks.apps.fields.title.default' => 'I miei progetti',
    'blocks.apps.fields.subtitle.label' => 'Sottotitolo',
    'blocks.apps.fields.items.label' => 'App / Progetti',
    'blocks.apps.fields.items.of.name.label' => 'Nome',
    'blocks.apps.fields.items.of.tagline.label' => 'Tagline',
    'blocks.apps.fields.items.of.description.label' => 'Descrizione',
    'blocks.apps.fields.items.of.icon_image.label' => 'Icona/Logo',
    'blocks.apps.fields.items.of.cover_image.label' => 'Copertina (opzionale)',
    'blocks.apps.fields.items.of.accent.label' => 'Colore accent',
    'blocks.apps.fields.items.of.url.label' => 'URL principale',
    'blocks.apps.fields.items.of.app_store.label' => 'App Store URL',
    'blocks.apps.fields.items.of.play_store.label' => 'Google Play URL',
    'blocks.apps.fields.items.of.tag.label' => 'Tag',
    'blocks.apps.fields.items.of.tag.placeholder' => 'es. web, open source, design',
    'blocks.apps.fields.items.of.tag.help' => 'Uno o più tag separati da virgola. Mostrati come badge sopra il nome (massimo 4).',

    // ---------- bio ----------
    'blocks.bio.label' => 'Bio',
    'blocks.bio.description' => 'Testo lungo (markdown) per raccontare di te.',
    'blocks.bio.fields.title.label' => 'Titolo',
    'blocks.bio.fields.title.default' => 'Su di me',
    'blocks.bio.fields.body.label' => 'Testo (Markdown)',

    // ---------- products ----------
    'blocks.products.label' => 'Prodotti consigliati',
    'blocks.products.description' => 'Lista di prodotti con immagine, descrizione, link, prezzo e codice sconto.',
    'blocks.products.fields.title.label' => 'Titolo sezione',
    'blocks.products.fields.title.default' => 'Prodotti consigliati',
    'blocks.products.fields.subtitle.label' => 'Sottotitolo',
    'blocks.products.fields.cta_label.label' => 'Etichetta bottone',
    'blocks.products.fields.cta_label.default' => 'Acquista',
    'blocks.products.fields.cta_label.help' => 'Testo del pulsante che porta al prodotto.',
    'blocks.products.fields.items.label' => 'Prodotti',
    'blocks.products.fields.items.of.image.label' => 'Immagine',
    'blocks.products.fields.items.of.name.label' => 'Nome prodotto',
    'blocks.products.fields.items.of.description.label' => 'Descrizione',
    'blocks.products.fields.items.of.price.label' => 'Prezzo',
    'blocks.products.fields.items.of.price.help' => 'Es. "39 €" o "da 9,99 €/mese". Lasciare vuoto per non mostrarlo.',
    'blocks.products.fields.items.of.url.label' => 'URL prodotto',
    'blocks.products.fields.items.of.discount_code.label' => 'Codice sconto (opzionale)',
    'blocks.products.fields.items.of.discount_code.help' => 'Se compilato, viene mostrato un badge cliccabile che copia il codice negli appunti.',
    'blocks.products.fields.items.of.discount_label.label' => 'Testo dello sconto',
    'blocks.products.fields.items.of.discount_label.help' => 'Es. "−15%" o "Spedizione gratis". Mostrato accanto al codice.',

    // ---------- quote ----------
    'blocks.quote.label' => 'Citazione',
    'blocks.quote.description' => 'Pull quote con autore e ruolo opzionali. Perfetto per testimonianze.',
    'blocks.quote.fields.title.label' => 'Titolo sezione (opzionale)',
    'blocks.quote.fields.title.help' => 'Lascia vuoto se la citazione non ha bisogno di una intestazione (default).',
    'blocks.quote.fields.text.label' => 'Testo della citazione',
    'blocks.quote.fields.text.placeholder' => '«…»',
    'blocks.quote.fields.author.label' => 'Autore',
    'blocks.quote.fields.role.label' => 'Ruolo / fonte',
    'blocks.quote.fields.role.help' => 'Es. "CEO @ Acme" o "Cliente felice"',
    'blocks.quote.fields.avatar.label' => 'Avatar autore (opzionale)',
    'blocks.quote.fields.style.label' => 'Stile',
    'blocks.quote.fields.style.options.card' => 'Card classica',
    'blocks.quote.fields.style.options.minimal' => 'Minimal con bordo',
    'blocks.quote.fields.style.options.highlight' => 'Sfondo accent',
    'blocks.quote.fields.text_size.label' => 'Dimensione testo',
    'blocks.quote.fields.text_size.options.sm' => 'Piccolo',
    'blocks.quote.fields.text_size.options.md' => 'Standard',
    'blocks.quote.fields.text_size.options.lg' => 'Grande',
    'blocks.quote.fields.text_size.help' => 'Quanto è prominente il testo della citazione rispetto al resto della pagina.',
    'blocks.quote.fields.line_height.label' => 'Interlinea',
    'blocks.quote.fields.line_height.options.compact' => 'Compatta',
    'blocks.quote.fields.line_height.options.normal' => 'Normale',
    'blocks.quote.fields.line_height.options.relaxed' => 'Ampia',
    'blocks.quote.fields.line_height.help' => 'Spaziatura verticale tra le righe del testo. "Ampia" rende la citazione più ariosa.',

    // ---------- stats ----------
    'blocks.stats.label' => 'Numeri',
    'blocks.stats.description' => 'Griglia di KPI: anni di esperienza, progetti, clienti, ecc.',
    'blocks.stats.fields.title.label' => 'Titolo (opzionale)',
    'blocks.stats.fields.items.label' => 'Numeri',
    'blocks.stats.fields.items.of.value.label' => 'Valore',
    'blocks.stats.fields.items.of.value.help' => 'Es. "5+", "200", "98%", "€1.2M"',
    'blocks.stats.fields.items.of.label.label' => 'Etichetta',
    'blocks.stats.fields.items.of.icon.label' => 'Icona (opzionale)',

    // ---------- skills ----------
    'blocks.skills.label' => 'Competenze',
    'blocks.skills.description' => 'Elenco di skill / know-how con livello opzionale e raggruppamento per categoria.',
    'blocks.skills.fields.title.label' => 'Titolo sezione',
    'blocks.skills.fields.title.default' => 'Competenze',
    'blocks.skills.fields.subtitle.label' => 'Sottotitolo',
    'blocks.skills.fields.items.label' => 'Competenze',
    'blocks.skills.fields.items.of.name.label' => 'Nome',
    'blocks.skills.fields.items.of.name.placeholder' => 'es. PHP, Photoshop, public speaking',
    'blocks.skills.fields.items.of.level.label' => 'Livello (opzionale)',
    'blocks.skills.fields.items.of.level.placeholder' => 'es. Esperto · 5 anni · ★★★★☆',
    'blocks.skills.fields.items.of.level.help' => 'Testo libero. Mostrato in piccolo accanto al nome.',
    'blocks.skills.fields.items.of.category.label' => 'Categoria (opzionale)',
    'blocks.skills.fields.items.of.category.placeholder' => 'es. Sviluppo, Design, Lingue',
    'blocks.skills.fields.items.of.category.help' => 'Le skill con la stessa categoria vengono raggruppate sotto un\'intestazione condivisa. Lascia vuoto per una lista piatta.',
    'blocks.skills.fields.items.of.icon.label' => 'Icona (opzionale)',

    // ---------- cta ----------
    'blocks.cta.label' => 'Call to action',
    'blocks.cta.description' => 'Banner con titolo, descrizione e bottone. Per portare l\'attenzione su una singola azione.',
    'blocks.cta.fields.title.label' => 'Titolo',
    'blocks.cta.fields.subtitle.label' => 'Sottotitolo / descrizione',
    'blocks.cta.fields.button_label.label' => 'Testo bottone',
    'blocks.cta.fields.button_label.default' => 'Scopri',
    'blocks.cta.fields.button_url.label' => 'URL bottone',
    'blocks.cta.fields.icon.label' => 'Icona accanto al bottone (opzionale)',
    'blocks.cta.fields.style.label' => 'Stile',
    'blocks.cta.fields.style.options.gradient' => 'Gradiente accent',
    'blocks.cta.fields.style.options.solid' => 'Solido accent',
    'blocks.cta.fields.style.options.outline' => 'Bordo accent',
    'blocks.cta.fields.style.options.minimal' => 'Minimal',

    // ---------- faq ----------
    'blocks.faq.label' => 'Domande frequenti',
    'blocks.faq.description' => 'Lista accordion di domande e risposte. Niente JS richiesto, usa <details>.',
    'blocks.faq.fields.title.label' => 'Titolo',
    'blocks.faq.fields.title.default' => 'Domande frequenti',
    'blocks.faq.fields.items.label' => 'Domande',
    'blocks.faq.fields.items.of.question.label' => 'Domanda',
    'blocks.faq.fields.items.of.answer.label' => 'Risposta (Markdown)',

    // ---------- timeline ----------
    'blocks.timeline.label' => 'Timeline',
    'blocks.timeline.description' => 'Cronologia di milestone, eventi, tappe del percorso.',
    'blocks.timeline.fields.title.label' => 'Titolo',
    'blocks.timeline.fields.title.default' => 'Il mio percorso',
    'blocks.timeline.fields.items.label' => 'Eventi',
    'blocks.timeline.fields.items.of.date.label' => 'Data / periodo',
    'blocks.timeline.fields.items.of.date.help' => 'Es. "2024", "Marzo 2023", "Q1 2025"',
    'blocks.timeline.fields.items.of.title.label' => 'Titolo evento',
    'blocks.timeline.fields.items.of.description.label' => 'Descrizione',
    'blocks.timeline.fields.items.of.icon.label' => 'Icona (opzionale)',
    'blocks.timeline.fields.items.of.highlight.label' => 'Evidenzia',
    'blocks.timeline.fields.items.of.highlight.help' => 'Stile diverso, utile per le tappe più importanti.',

    // ---------- social ----------
    'blocks.social.label' => 'Social',
    'blocks.social.description' => 'Riga di icone social.',
    'blocks.social.fields.title.label' => 'Titolo',
    'blocks.social.fields.display.label' => 'Visualizzazione',
    'blocks.social.fields.display.help' => 'Cosa mostrare accanto a ogni icona.',
    'blocks.social.fields.display.options.icon_only' => 'Solo icone',
    'blocks.social.fields.display.options.icon_platform' => 'Icona + nome social',
    'blocks.social.fields.display.options.icon_account' => 'Icona + nome account',
    'blocks.social.fields.display.options.icon_full' => 'Icona + nome social + nome account',
    'blocks.social.fields.align.label' => 'Allineamento',
    'blocks.social.fields.align.help' => 'Posizione di titolo e pillole social nella tessera.',
    'blocks.social.fields.align.options.left' => 'Sinistra',
    'blocks.social.fields.align.options.center' => 'Centro',
    'blocks.social.fields.items.label' => 'Profili',
    'blocks.social.fields.items.of.platform.label' => 'Piattaforma',
    'blocks.social.fields.items.of.platform.options.website' => 'Sito web',
    'blocks.social.fields.items.of.platform.options.email' => 'Email',
    'blocks.social.fields.items.of.platform.options.phone' => 'Telefono',
    'blocks.social.fields.items.of.platform.options.other' => 'Altro',
    'blocks.social.fields.items.of.url.label' => 'URL',
    'blocks.social.fields.items.of.label.label' => 'Nome account / handle',
    'blocks.social.fields.items.of.label.help' => 'Es. @maurizio o "il mio canale". Mostrato in base alla modalità di visualizzazione scelta sopra.',

    // ---------- gallery ----------
    'blocks.gallery.label' => 'Galleria',
    'blocks.gallery.description' => 'Griglia di immagini con lightbox.',
    'blocks.gallery.fields.title.label' => 'Titolo',
    'blocks.gallery.fields.title.default' => 'Galleria',
    'blocks.gallery.fields.layout.label' => 'Layout',
    'blocks.gallery.fields.layout.options.mosaic' => 'Mosaico',
    'blocks.gallery.fields.layout.options.grid' => 'Griglia uniforme',
    'blocks.gallery.fields.layout.options.carousel' => 'Carosello',
    'blocks.gallery.fields.items.label' => 'Immagini',
    'blocks.gallery.fields.items.of.image.label' => 'Immagine',
    'blocks.gallery.fields.items.of.alt.label' => 'Testo alternativo',
    'blocks.gallery.fields.items.of.caption.label' => 'Didascalia',
    'blocks.gallery.fields.items.of.link.label' => 'Link (opzionale)',

    // ---------- podcast ----------
    'blocks.podcast.label' => 'Podcast',
    'blocks.podcast.description' => 'Tessera per il tuo podcast con link a Apple Podcasts, Spotify e/o sito proprio. Almeno uno è obbligatorio.',
    'blocks.podcast.fields.title.label' => 'Titolo sezione',
    'blocks.podcast.fields.title.default' => 'Ascolta il podcast',
    'blocks.podcast.fields.subtitle.label' => 'Sottotitolo',
    'blocks.podcast.fields.show_name.label' => 'Nome del podcast',
    'blocks.podcast.fields.show_name.placeholder' => 'es. Il mio podcast',
    'blocks.podcast.fields.show_name.help' => 'Mostrato sopra i bottoni delle piattaforme. Lascia vuoto per non mostrarlo.',
    'blocks.podcast.fields.apple_url.label' => 'Link Apple Podcasts',
    'blocks.podcast.fields.apple_url.help' => 'Accettati anche vecchi URL "itunes.apple.com". Quando presente, può essere usato per embeddare il player.',
    'blocks.podcast.fields.spotify_url.label' => 'Link Spotify',
    'blocks.podcast.fields.spotify_url.help' => 'Quando presente, può essere usato per embeddare il player.',
    'blocks.podcast.fields.site_url.label' => 'Link al sito (per ascolto online)',
    'blocks.podcast.fields.site_url.help' => 'Pagina propria dove si può ascoltare il podcast. Mostrata come bottone "Sito".',
    'blocks.podcast.fields.preferred_player.label' => 'Player principale',
    'blocks.podcast.fields.preferred_player.options.auto' => 'Automatico (Spotify > Apple)',
    'blocks.podcast.fields.preferred_player.options.none' => 'Solo bottoni (nessun player)',
    'blocks.podcast.fields.preferred_player.help' => 'Quale player vuoi mostrare in alto a tutta larghezza. Le altre piattaforme diventano bottoni cliccabili sotto. "Automatico" prende Spotify se c\'è (più diffuso in Italia), altrimenti Apple. "Solo bottoni" salta il player → tessera compatta. Il player mostra automaticamente "show" o "singolo episodio" in base al tipo di URL incollato (es. /show/… vs /episode/… su Spotify, oppure ?i=… su Apple).',
    'blocks.podcast.fields.description.label' => 'Descrizione (opzionale)',
    'blocks.podcast.fields.description.placeholder' => 'Una o due righe di presentazione',

    // ---------- youtube ----------
    'blocks.youtube.label' => 'YouTube',
    'blocks.youtube.description' => 'Mostra l\'ultimo video di un canale o di una playlist YouTube. Si aggiorna da solo.',
    'blocks.youtube.fields.title.label' => 'Titolo sezione',
    'blocks.youtube.fields.title.default' => 'Ultimo video',
    'blocks.youtube.fields.subtitle.label' => 'Sottotitolo',
    'blocks.youtube.fields.source_url.label' => 'URL del canale o della playlist',
    'blocks.youtube.fields.source_url.help' => 'Incolla l\'URL del canale (es. https://www.youtube.com/channel/UC…) oppure di una playlist (…/playlist?list=PL…). Per gli URL @handle, copia il link "Condividi" del canale invece — gli @handle non espongono direttamente l\'ID che ci serve.',
    'blocks.youtube.fields.mode.label' => 'Cosa mostrare',
    'blocks.youtube.fields.mode.options.latest' => 'Ultimo video pubblicato',
    'blocks.youtube.fields.mode.options.playlist' => 'Playlist completa (navigabile)',
    'blocks.youtube.fields.mode.help' => 'Modalità "ultimo video" leggiamo il feed pubblico del canale/playlist e mostriamo solo il più recente, aggiornandolo automaticamente. Modalità "playlist completa" embeddiamo l\'intera playlist navigabile (richiede playlist URL).',
    'blocks.youtube.fields.aspect.label' => 'Proporzioni player',
    'blocks.youtube.fields.aspect.options.16_9' => '16:9 (orizzontale)',
    'blocks.youtube.fields.aspect.options.9_16' => '9:16 (verticale / Shorts)',
    'blocks.youtube.fields.aspect.options.1_1' => '1:1 (quadrato)',

    // ---------- embed ----------
    'blocks.embed.label' => 'Embed',
    'blocks.embed.description' => 'YouTube, Spotify, Vimeo, iframe libera.',
    'blocks.embed.fields.title.label' => 'Titolo',
    'blocks.embed.fields.provider.label' => 'Provider',
    'blocks.embed.fields.provider.options.iframe' => 'iframe libera',
    'blocks.embed.fields.url.label' => 'URL contenuto',
    'blocks.embed.fields.url.help' => 'URL pubblico del media (es. video YouTube).',
    'blocks.embed.fields.aspect.label' => 'Aspect ratio',
    'blocks.embed.fields.aspect.options.9_16' => '9:16 (verticale)',

    // ---------- contact ----------
    'blocks.contact.label' => 'Contatti',
    'blocks.contact.description' => 'Form di contatto. Salva i messaggi nel DB e (se configurato) invia notifica email.',
    'blocks.contact.fields.title.label' => 'Titolo',
    'blocks.contact.fields.title.default' => 'Scrivimi',
    'blocks.contact.fields.subtitle.label' => 'Sottotitolo',
    'blocks.contact.fields.success_message.label' => 'Messaggio di conferma',
    'blocks.contact.fields.success_message.default' => 'Grazie, ti rispondo a breve.',
    'blocks.contact.fields.fields.label' => 'Campi del form',
    'blocks.contact.fields.fields.default.name' => 'Nome',
    'blocks.contact.fields.fields.default.email' => 'Email',
    'blocks.contact.fields.fields.default.message' => 'Messaggio',
    'blocks.contact.fields.fields.of.key.label' => 'Chiave',
    'blocks.contact.fields.fields.of.label.label' => 'Etichetta',
    'blocks.contact.fields.fields.of.type.label' => 'Tipo',
    'blocks.contact.fields.fields.of.type.options.text' => 'Testo',
    'blocks.contact.fields.fields.of.type.options.email' => 'Email',
    'blocks.contact.fields.fields.of.type.options.tel' => 'Telefono',
    'blocks.contact.fields.fields.of.type.options.textarea' => 'Area testo',
    'blocks.contact.fields.fields.of.required.label' => 'Obbligatorio',

    // ---------- divider ----------
    'blocks.divider.label' => 'Separatore',
    'blocks.divider.description' => 'Separatore visivo a tessere.',
    'blocks.divider.fields.style.label' => 'Stile',
    'blocks.divider.fields.style.options.tessera' => 'Tessere (3 rombi)',
    'blocks.divider.fields.style.options.dots' => 'Puntini (3)',
    'blocks.divider.fields.style.options.line' => 'Linea sottile',
    'blocks.divider.fields.style.options.line-double' => 'Linea doppia',
    'blocks.divider.fields.style.options.line-dashed' => 'Linea tratteggiata sottile',
    'blocks.divider.fields.style.options.line-dashed-thick' => 'Linea tratteggiata spessa',
    'blocks.divider.fields.style.options.diagonal' => 'Linee diagonali',
    'blocks.divider.fields.style.options.wave' => 'Onda',
    'blocks.divider.fields.style.options.chevrons' => 'Frecce a punta (3)',
    'blocks.divider.fields.style.options.floral' => 'Decorazione floreale',
    'blocks.divider.fields.style.options.stars' => 'Stelle (3)',
    'blocks.divider.fields.style.options.space' => 'Solo spazio',

    // ---------- footer ----------
    'blocks.footer.label' => 'Footer',
    'blocks.footer.description' => 'Piè di pagina con copyright e link minori.',
    'blocks.footer.fields.text.label' => 'Testo',
    'blocks.footer.fields.show_powered_by.label' => 'Mostra "powered by tylio"',
    'blocks.footer.fields.show_powered_by.help' => 'Se lasci attivo questo crediti, in fondo al sito appare un piccolo "powered by tylio" cliccabile. Ti ringraziamo se decidi di tenerlo: aiuta a far conoscere il progetto, è gratuito ed open-source. Puoi sempre disattivarlo in qualsiasi momento.',
    'blocks.footer.fields.links.label' => 'Link',
    'blocks.footer.fields.links.of.label.label' => 'Testo',
    'blocks.footer.fields.links.of.url.label' => 'URL',
];
