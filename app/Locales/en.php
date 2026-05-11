<?php
declare(strict_types=1);

/**
 * English locale (default / fallback) for server-rendered public pages
 * and PHP-emitted user-facing strings.
 *
 * Convention:
 *   - keys are dot-namespaced: `public.empty.title`, `relative.minutes_ago`
 *   - placeholders use `{name}` syntax (consumed by `I18n::t`)
 *   - keep entries grouped by surface; alphabetize within each group
 */

return [
    // -----------------------------------------------------------------
    // Media — upload error messages (server-side)
    // -----------------------------------------------------------------
    'media.upload.error.ini_size' => 'The file exceeds the server\'s maximum upload size. Shrink it or use a lighter image.',
    'media.upload.error.form_size' => 'The file exceeds the maximum size allowed by the form.',
    'media.upload.error.partial' => 'The file was only partially uploaded. Try again.',
    'media.upload.error.no_file' => 'No file uploaded.',
    'media.upload.error.no_tmp_dir' => 'The server has no temporary folder available. Contact support.',
    'media.upload.error.cant_write' => 'Cannot write the file to disk. Contact support.',
    'media.upload.error.extension' => 'A PHP extension blocked the upload.',
    'media.upload.error.unknown' => 'Unknown upload error.',
    'media.upload.error.too_large' => 'File too large (max {mb} MB).',

    // -----------------------------------------------------------------
    // Settings — server-side validation messages
    // -----------------------------------------------------------------
    'settings.errors.invalid_locale' => 'Invalid language code. Use 2 letters (e.g. "en") or 2-2 (e.g. "en-US").',
    'settings.errors.invalid_canonical_url' => 'Canonical URL must start with http:// or https://.',
    'settings.errors.invalid_email' => 'Invalid email address.',

    // -----------------------------------------------------------------
    // Public site — empty page, lightbox, accessibility
    // -----------------------------------------------------------------
    'public.empty.title' => 'Welcome to <em>tylio</em>',
    'public.empty.message' => 'This page is empty. Sign in to the admin area to compose the first tile.',
    'public.lightbox.close' => 'Close',
    'public.powered_by' => 'Powered by tylio',

    // -----------------------------------------------------------------
    // Public site — contact form (block-rendered)
    // -----------------------------------------------------------------
    'public.contact.send' => 'Send',
    'public.contact.sending' => 'Sending…',
    'public.contact.success' => 'Message sent. Thanks!',
    'public.contact.error' => 'Something went wrong. Please try again.',
    'public.contact.captcha_working' => 'Verifying you are human…',
    'public.contact.captcha_working_sec' => 'Verifying you are human… ({sec}s)',
    'public.contact.captcha_ok' => '✓ Verified',
    'public.contact.captcha_failed' => 'Verification failed. Reload the page.',
    'public.contact.captcha_not_ready' => 'Anti-bot check not finished, wait a moment…',
    'public.contact.error_generic' => 'Something went wrong. Please try again.',
    'public.contact.error_network' => 'Network error. Please try again.',
    'public.contact.default_title' => 'Write to me',
    'public.contact.default_success' => 'Thanks, I will get back to you soon.',

    // -----------------------------------------------------------------
    // Public site — block defaults (CTA labels, fallbacks)
    // -----------------------------------------------------------------
    'public.products.default_cta' => 'Buy',
    'public.products.copy_code' => 'Click to copy the code',
    'public.embed.invalid_url' => 'Invalid or unrecognized embed URL.',
    'public.youtube.unavailable' => 'Latest video temporarily unavailable',
    'public.youtube.open_on_yt' => 'open on YouTube',
    'public.social.website' => 'Website',
    'public.social.email' => 'Email',
    'public.social.phone' => 'Phone',
    'public.social.rss' => 'RSS',
    'public.social.other' => 'Link',

    // -----------------------------------------------------------------
    // Public site — footer
    // -----------------------------------------------------------------
    'public.footer.powered_by' => 'Powered by <a href="https://github.com/anthropics" rel="noopener">tylio</a>',

    // -----------------------------------------------------------------
    // Service shells: placeholder, 503, 404 (rendered when no tenant /
    // app not installed yet / route not matched)
    // -----------------------------------------------------------------
    'shell.placeholder.title' => 'tylio',
    'shell.placeholder.body' => 'Site coming soon.',
    'shell.unavailable.title' => 'Service unavailable',
    'shell.unavailable.body' => 'The site is temporarily unavailable. Please try again later.',
    'shell.not_found.title' => 'Not found',
    'shell.not_found.body' => 'The page you are looking for does not exist.',
    'shell.not_found.back_home' => 'Back to home',

    // -----------------------------------------------------------------
    // Transactional emails (Mailer service). Active locale per send() is
    // resolved from `settings.site.locale`. HTML bodies keep the inline
    // Dracula palette via placeholders so the visual identity stays
    // identical across languages.
    // -----------------------------------------------------------------
    'mail.invite.subject' => 'Your new site on {brand} is ready',
    'mail.invite.body_text' => <<<TXT
    Hi,

    a personal site has been created for you on {brand}:
      {url}

    To access the admin panel:
      {admin_url}

    Initial credentials:
      Username: {username}
      Temporary password: {temp_password}

    On first login we'll automatically ask you to choose a new password
    (minimum 10 characters). The one above is temporary and becomes
    unusable as soon as you change it.

    Security:
    — the password you received is NOT stored in plaintext on our servers
      (the DB only holds the Argon2id hash, which is not reversible);
    — this email is the only place where the temporary password exists
      in readable form: delete it after your first login.

    After changing your password you can customize the theme, add tiles
    (links, bio, social, gallery, etc.) and upload an image.

    Support: {support}
    Privacy/data: {privacy}

    — {brand}
    TXT,
    'mail.invite.body_html' => <<<HTML
    <!doctype html>
    <html lang="{lang}">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <meta name="x-apple-disable-message-reformatting">
      <title>Your site on {brand} is ready</title>
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
                    Your site is ready
                  </h1>
                  <p style="margin:10px 0 0;color:#97a3c2;font-size:15px;line-height:1.5;">
                    Hi, we have created <a href="{url}" style="color:#bd93f9;text-decoration:none;font-weight:600;">{site_label}</a> for you.
                  </p>
                </td>
              </tr>
              <tr>
                <td style="padding:24px 32px 8px;">
                  <h2 style="margin:0 0 8px;font-family:Georgia,serif;font-size:18px;color:#bd93f9;">1. Sign in to the admin panel</h2>
                  <p style="margin:0 0 14px;color:#f8f8f2;font-size:15px;line-height:1.5;">
                    On first login we'll automatically ask you to choose a new password. The one below is temporary and becomes unusable as soon as you change it.
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
                        <div style="color:#97a3c2;font-size:12px;letter-spacing:.04em;text-transform:uppercase;">Temporary password</div>
                        <div style="color:#ff79c6;font-family:'SF Mono',Menlo,Consolas,monospace;font-size:18px;margin-top:2px;font-weight:600;letter-spacing:.05em;">{temp_password}</div>
                      </td>
                    </tr>
                  </table>
                  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px;">
                    <tr>
                      <td style="background:#bd93f9;border-radius:10px;">
                        <a href="{admin_url}" style="display:inline-block;padding:12px 22px;color:#1a1c25;font-weight:600;font-size:15px;text-decoration:none;">Go to admin panel →</a>
                      </td>
                    </tr>
                  </table>
                  <p style="margin:14px 0 0;color:#97a3c2;font-size:13px;line-height:1.5;">
                    Or copy and paste in your browser:<br>
                    <span style="color:#97a3c2;font-family:'SF Mono',Menlo,Consolas,monospace;font-size:12px;word-break:break-all;">{admin_url}</span>
                  </p>
                </td>
              </tr>
              <tr>
                <td style="padding:20px 32px 8px;">
                  <h2 style="margin:0 0 8px;font-family:Georgia,serif;font-size:18px;color:#bd93f9;">2. Start building</h2>
                  <p style="margin:0;color:#f8f8f2;font-size:15px;line-height:1.6;">
                    Once you've set your password you can:
                  </p>
                  <ul style="margin:8px 0 0;padding-left:20px;color:#f8f8f2;font-size:15px;line-height:1.6;">
                    <li>Customize theme and palette</li>
                    <li>Compose your home page with tiles (link, bio, social, gallery, contact, etc.)</li>
                    <li>Upload avatar and favicon</li>
                  </ul>
                </td>
              </tr>
              <tr>
                <td style="padding:20px 32px 24px;">
                  <h2 style="margin:0 0 8px;font-family:Georgia,serif;font-size:18px;color:#bd93f9;">About security</h2>
                  <p style="margin:0;color:#97a3c2;font-size:13px;line-height:1.6;">
                    The password you received is not stored in plaintext on our servers (the DB only holds the Argon2id hash, which is not reversible). This email is the only place where the temporary password exists in readable form: delete it after changing it. Once replaced, it becomes unusable.
                  </p>
                </td>
              </tr>
              <tr>
                <td style="padding:18px 32px 24px;border-top:1px solid rgba(248,248,242,0.12);">
                  <p style="margin:0;color:#97a3c2;font-size:12px;line-height:1.6;">
                    Support: <a href="mailto:{support}" style="color:#bd93f9;text-decoration:none;">{support}</a>
                    &nbsp;·&nbsp;
                    Privacy: <a href="mailto:{privacy}" style="color:#bd93f9;text-decoration:none;">{privacy}</a>
                  </p>
                  <p style="margin:8px 0 0;color:#97a3c2;font-size:12px;">— {brand}</p>
                </td>
              </tr>
            </table>
            <p style="max-width:560px;margin:14px auto 0;color:#97a3c2;font-size:11px;line-height:1.5;text-align:center;">
              You received this email because someone created the site {site_label} using your address. If it wasn't you, ignore this email — without the temporary password it's impossible to access the admin panel.
            </p>
          </td>
        </tr>
      </table>
    </body>
    </html>
    HTML,

    'mail.contact_notification.subject' => 'New message from site {host}',
    'mail.contact_notification.body_text' => <<<TXT
    You received a new message from the Contact form.

    {fields}

    —
    Site: {host}
    {ip_line}Tile: #{block_id}
    TXT,
    'mail.contact_notification.ip_line' => "IP: {ip}\n",
    'mail.contact_notification.no_fields' => 'The form did not transmit any fields.',
    'mail.contact_notification.ip_label' => 'IP: {ip}',
    'mail.contact_notification.reply_hint_html' => 'Press <strong>Reply</strong> in your mail client: the reply will go directly to <a href="mailto:{email}" style="color:{accent};text-decoration:none;">{email}</a>.',
    'mail.contact_notification.reply_none_html' => 'The message does not contain an email address — you can\'t reply directly.',
    'mail.contact_notification.quote_header' => 'On {date}, {name} wrote:',
    'mail.contact_notification.body_html' => <<<HTML
    <!doctype html>
    <html lang="{lang}">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <meta name="x-apple-disable-message-reformatting">
      <title>New message from site {host}</title>
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
                    New message
                  </h1>
                  <p style="margin:10px 0 0;color:{muted};font-size:15px;line-height:1.5;">
                    You received a new message from the contact form on site <span style="color:{accent};font-weight:600;">{host}</span>.
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
                    Tile: #{block_id}{ip_line_html}
                  </div>
                  <p style="margin:10px 0 0;color:{muted};font-size:12px;line-height:1.6;">
                    Support: <a href="mailto:{support}" style="color:{accent};text-decoration:none;">{support}</a>
                    &nbsp;·&nbsp;
                    Privacy: <a href="mailto:{privacy}" style="color:{accent};text-decoration:none;">{privacy}</a>
                  </p>
                  <p style="margin:8px 0 0;color:{muted};font-size:12px;">— {brand}</p>
                </td>
              </tr>
            </table>
            <p style="max-width:560px;margin:14px auto 0;color:{muted};font-size:11px;line-height:1.5;text-align:center;">
              You're receiving this email because someone submitted the contact form on site {host}. The message is also saved in the {brand} admin (Messages section).
            </p>
          </td>
        </tr>
      </table>
    </body>
    </html>
    HTML,

    'mail.password_reset.subject' => 'Reset the password for your {brand} site',
    'mail.password_reset.body_text' => <<<TXT
    Hi,

    You requested a password reset for:
      site: {site_url}
      username: {username}

    Open this link within {expires_minutes} minutes to choose a new password:
      {reset_url}

    If you didn't request the reset you can ignore this email. The link
    expires on its own and your account stays unchanged.

    — {brand}
    TXT,

    // -----------------------------------------------------------------
    // Relative-date helper (Renderer::relativeDate)
    // -----------------------------------------------------------------
    'relative.future' => 'in the future',
    'relative.now' => 'now',
    'relative.minutes_ago' => '{n} min ago',
    'relative.hours_ago' => '{n}h ago',
    'relative.yesterday' => 'yesterday',
    'relative.days_ago' => '{n}d ago',
    'relative.weeks_ago' => '{n}w ago',
    'relative.months_ago' => '{n}mo ago',
    'relative.years_ago' => '{n}y ago',

    // -----------------------------------------------------------------
    // Block registry — admin SPA "Add tile" sheet + field editor.
    // Keys are emitted by `Tylio\Services\BlockRegistry` and resolved
    // at the controller boundary (`TypesController`, `BlocksController`).
    // -----------------------------------------------------------------

    // Category namespaces
    'blocks.categories.identity' => 'identity',
    'blocks.categories.content' => 'content',
    'blocks.categories.action' => 'action',
    'blocks.categories.structure' => 'structure',

    // ---------- hero ----------
    'blocks.hero.label' => 'Hero',
    'blocks.hero.description' => 'Opening panel with avatar, title and subtitle.',
    'blocks.hero.fields.avatar.label' => 'Avatar / logo',
    'blocks.hero.fields.avatar.help' => 'Will be displayed as a circle. Areas outside the circle are highlighted in the preview.',
    'blocks.hero.fields.title.label' => 'Title',
    'blocks.hero.fields.title.default' => 'Hi, I\'m…',
    'blocks.hero.fields.title.placeholder' => 'Name / short claim',
    'blocks.hero.fields.title.help' => 'Shown as the home\'s large heading. If you also upload an image below (Graphic title), the text here becomes the image\'s "alt" description for accessibility and SEO — you can always keep it filled in.',
    'blocks.hero.fields.title_image.label' => 'Graphic title (optional)',
    'blocks.hero.fields.title_image.help' => 'Upload an image (e.g. logotype or stylized title) that REPLACES the title text on the home. The "Title" field text is used as the image\'s alt/title — fill it in anyway for accessibility and SEO. Leave empty to use text only.',
    'blocks.hero.fields.subtitle.label' => 'Subtitle / Bio',
    'blocks.hero.fields.subtitle.placeholder' => 'One or two lines about you. **Bold**, *italic*, [link](https://...) supported.',
    'blocks.hero.fields.subtitle.help' => 'Free text in Markdown. Examples: **bold**, *italic*, [write your text here](https://link), `code`, > a quote, and at the start of a line - for a bullet point. See the full legend below.',

    // ---------- links ----------
    'blocks.links.label' => 'Links',
    'blocks.links.description' => 'List of clickable links (linktree-style), with icons and badges.',
    'blocks.links.fields.title.label' => 'Section title',
    'blocks.links.fields.title.default' => 'Links',
    'blocks.links.fields.items.label' => 'Links',
    'blocks.links.fields.items.of.label.label' => 'Text',
    'blocks.links.fields.items.of.url.label' => 'URL',
    'blocks.links.fields.items.of.icon.label' => 'Icon',
    'blocks.links.fields.items.of.icon.help' => 'Iconify name, e.g. lucide:globe',
    'blocks.links.fields.items.of.badge.label' => 'Badge',
    'blocks.links.fields.items.of.badge.help' => 'E.g. "new"',
    'blocks.links.fields.items.of.description.label' => 'Description',

    // ---------- apps ----------
    'blocks.apps.label' => 'Apps / Projects',
    'blocks.apps.description' => 'Large cards for projects / apps: icon, name, description, link, store badges.',
    'blocks.apps.fields.title.label' => 'Section title',
    'blocks.apps.fields.title.default' => 'My projects',
    'blocks.apps.fields.subtitle.label' => 'Subtitle',
    'blocks.apps.fields.items.label' => 'Apps / Projects',
    'blocks.apps.fields.items.of.name.label' => 'Name',
    'blocks.apps.fields.items.of.tagline.label' => 'Tagline',
    'blocks.apps.fields.items.of.description.label' => 'Description',
    'blocks.apps.fields.items.of.icon_image.label' => 'Icon/Logo',
    'blocks.apps.fields.items.of.cover_image.label' => 'Cover (optional)',
    'blocks.apps.fields.items.of.accent.label' => 'Accent color',
    'blocks.apps.fields.items.of.url.label' => 'Main URL',
    'blocks.apps.fields.items.of.app_store.label' => 'App Store URL',
    'blocks.apps.fields.items.of.play_store.label' => 'Google Play URL',
    'blocks.apps.fields.items.of.tag.label' => 'Tag',
    'blocks.apps.fields.items.of.tag.placeholder' => 'e.g. web, open source, design',
    'blocks.apps.fields.items.of.tag.help' => 'One or more comma-separated tags. Shown as badges above the name (max 4).',

    // ---------- bio ----------
    'blocks.bio.label' => 'Bio',
    'blocks.bio.description' => 'Long-form text (markdown) to tell your story.',
    'blocks.bio.fields.title.label' => 'Title',
    'blocks.bio.fields.title.default' => 'About me',
    'blocks.bio.fields.body.label' => 'Text (Markdown)',

    // ---------- products ----------
    'blocks.products.label' => 'Recommended products',
    'blocks.products.description' => 'List of products with image, description, link, price and discount code.',
    'blocks.products.fields.title.label' => 'Section title',
    'blocks.products.fields.title.default' => 'Recommended products',
    'blocks.products.fields.subtitle.label' => 'Subtitle',
    'blocks.products.fields.cta_label.label' => 'Button label',
    'blocks.products.fields.cta_label.default' => 'Buy',
    'blocks.products.fields.cta_label.help' => 'Text of the button that links to the product.',
    'blocks.products.fields.items.label' => 'Products',
    'blocks.products.fields.items.of.image.label' => 'Image',
    'blocks.products.fields.items.of.name.label' => 'Product name',
    'blocks.products.fields.items.of.description.label' => 'Description',
    'blocks.products.fields.items.of.price.label' => 'Price',
    'blocks.products.fields.items.of.price.help' => 'E.g. "$39" or "from $9.99/month". Leave empty to hide it.',
    'blocks.products.fields.items.of.url.label' => 'Product URL',
    'blocks.products.fields.items.of.discount_code.label' => 'Discount code (optional)',
    'blocks.products.fields.items.of.discount_code.help' => 'If filled, a clickable badge is shown that copies the code to the clipboard.',
    'blocks.products.fields.items.of.discount_label.label' => 'Discount text',
    'blocks.products.fields.items.of.discount_label.help' => 'E.g. "−15%" or "Free shipping". Shown next to the code.',

    // ---------- quote ----------
    'blocks.quote.label' => 'Quote',
    'blocks.quote.description' => 'Pull quote with optional author and role. Perfect for testimonials.',
    'blocks.quote.fields.title.label' => 'Section title (optional)',
    'blocks.quote.fields.title.help' => 'Leave empty if the quote doesn\'t need a heading (default).',
    'blocks.quote.fields.text.label' => 'Quote text',
    'blocks.quote.fields.text.placeholder' => '"…"',
    'blocks.quote.fields.author.label' => 'Author',
    'blocks.quote.fields.role.label' => 'Role / source',
    'blocks.quote.fields.role.help' => 'E.g. "CEO @ Acme" or "Happy customer"',
    'blocks.quote.fields.avatar.label' => 'Author avatar (optional)',
    'blocks.quote.fields.style.label' => 'Style',
    'blocks.quote.fields.style.options.card' => 'Classic card',
    'blocks.quote.fields.style.options.minimal' => 'Minimal with border',
    'blocks.quote.fields.style.options.highlight' => 'Accent background',
    'blocks.quote.fields.text_size.label' => 'Text size',
    'blocks.quote.fields.text_size.options.sm' => 'Small',
    'blocks.quote.fields.text_size.options.md' => 'Standard',
    'blocks.quote.fields.text_size.options.lg' => 'Large',
    'blocks.quote.fields.text_size.help' => 'How prominent the quote text is compared to the rest of the page.',
    'blocks.quote.fields.line_height.label' => 'Line height',
    'blocks.quote.fields.line_height.options.compact' => 'Compact',
    'blocks.quote.fields.line_height.options.normal' => 'Normal',
    'blocks.quote.fields.line_height.options.relaxed' => 'Relaxed',
    'blocks.quote.fields.line_height.help' => 'Vertical spacing between lines. "Relaxed" makes the quote feel more airy.',

    // ---------- stats ----------
    'blocks.stats.label' => 'Numbers',
    'blocks.stats.description' => 'KPI grid: years of experience, projects, customers, etc.',
    'blocks.stats.fields.title.label' => 'Title (optional)',
    'blocks.stats.fields.items.label' => 'Numbers',
    'blocks.stats.fields.items.of.value.label' => 'Value',
    'blocks.stats.fields.items.of.value.help' => 'E.g. "5+", "200", "98%", "$1.2M"',
    'blocks.stats.fields.items.of.label.label' => 'Label',
    'blocks.stats.fields.items.of.icon.label' => 'Icon (optional)',

    // ---------- skills ----------
    'blocks.skills.label' => 'Skills',
    'blocks.skills.description' => 'List of skills / know-how with optional level and category grouping.',
    'blocks.skills.fields.title.label' => 'Section title',
    'blocks.skills.fields.title.default' => 'Skills',
    'blocks.skills.fields.subtitle.label' => 'Subtitle',
    'blocks.skills.fields.items.label' => 'Skills',
    'blocks.skills.fields.items.of.name.label' => 'Name',
    'blocks.skills.fields.items.of.name.placeholder' => 'e.g. PHP, Photoshop, public speaking',
    'blocks.skills.fields.items.of.level.label' => 'Level (optional)',
    'blocks.skills.fields.items.of.level.placeholder' => 'e.g. Expert · 5 years · ★★★★☆',
    'blocks.skills.fields.items.of.level.help' => 'Free text. Shown in small print next to the name.',
    'blocks.skills.fields.items.of.category.label' => 'Category (optional)',
    'blocks.skills.fields.items.of.category.placeholder' => 'e.g. Development, Design, Languages',
    'blocks.skills.fields.items.of.category.help' => 'Skills with the same category are grouped under a shared heading. Leave empty for a flat list.',
    'blocks.skills.fields.items.of.icon.label' => 'Icon (optional)',

    // ---------- cta ----------
    'blocks.cta.label' => 'Call to action',
    'blocks.cta.description' => 'Banner with title, description and button. To focus attention on a single action.',
    'blocks.cta.fields.title.label' => 'Title',
    'blocks.cta.fields.subtitle.label' => 'Subtitle / description',
    'blocks.cta.fields.button_label.label' => 'Button text',
    'blocks.cta.fields.button_label.default' => 'Learn more',
    'blocks.cta.fields.button_url.label' => 'Button URL',
    'blocks.cta.fields.icon.label' => 'Icon next to the button (optional)',
    'blocks.cta.fields.style.label' => 'Style',
    'blocks.cta.fields.style.options.gradient' => 'Accent gradient',
    'blocks.cta.fields.style.options.solid' => 'Accent solid',
    'blocks.cta.fields.style.options.outline' => 'Accent outline',
    'blocks.cta.fields.style.options.minimal' => 'Minimal',

    // ---------- faq ----------
    'blocks.faq.label' => 'Frequently asked questions',
    'blocks.faq.description' => 'Accordion list of questions and answers. No JS required, uses <details>.',
    'blocks.faq.fields.title.label' => 'Title',
    'blocks.faq.fields.title.default' => 'Frequently asked questions',
    'blocks.faq.fields.items.label' => 'Questions',
    'blocks.faq.fields.items.of.question.label' => 'Question',
    'blocks.faq.fields.items.of.answer.label' => 'Answer (Markdown)',

    // ---------- timeline ----------
    'blocks.timeline.label' => 'Timeline',
    'blocks.timeline.description' => 'Timeline of milestones, events, stages of your journey.',
    'blocks.timeline.fields.title.label' => 'Title',
    'blocks.timeline.fields.title.default' => 'My journey',
    'blocks.timeline.fields.items.label' => 'Events',
    'blocks.timeline.fields.items.of.date.label' => 'Date / period',
    'blocks.timeline.fields.items.of.date.help' => 'E.g. "2024", "March 2023", "Q1 2025"',
    'blocks.timeline.fields.items.of.title.label' => 'Event title',
    'blocks.timeline.fields.items.of.description.label' => 'Description',
    'blocks.timeline.fields.items.of.icon.label' => 'Icon (optional)',
    'blocks.timeline.fields.items.of.highlight.label' => 'Highlight',
    'blocks.timeline.fields.items.of.highlight.help' => 'Different style, useful for the most important stages.',

    // ---------- social ----------
    'blocks.social.label' => 'Social',
    'blocks.social.description' => 'Row of social icons.',
    'blocks.social.fields.title.label' => 'Title',
    'blocks.social.fields.display.label' => 'Display',
    'blocks.social.fields.display.help' => 'What to show next to each icon.',
    'blocks.social.fields.display.options.icon_only' => 'Icons only',
    'blocks.social.fields.display.options.icon_platform' => 'Icon + platform name',
    'blocks.social.fields.display.options.icon_account' => 'Icon + account name',
    'blocks.social.fields.display.options.icon_full' => 'Icon + platform name + account name',
    'blocks.social.fields.align.label' => 'Alignment',
    'blocks.social.fields.align.help' => 'Position of title and social pills within the tile.',
    'blocks.social.fields.align.options.left' => 'Left',
    'blocks.social.fields.align.options.center' => 'Center',
    'blocks.social.fields.items.label' => 'Profiles',
    'blocks.social.fields.items.of.platform.label' => 'Platform',
    'blocks.social.fields.items.of.platform.options.website' => 'Website',
    'blocks.social.fields.items.of.platform.options.email' => 'Email',
    'blocks.social.fields.items.of.platform.options.phone' => 'Phone',
    'blocks.social.fields.items.of.platform.options.other' => 'Other',
    'blocks.social.fields.items.of.url.label' => 'URL',
    'blocks.social.fields.items.of.label.label' => 'Account name / handle',
    'blocks.social.fields.items.of.label.help' => 'E.g. @maurizio or "my channel". Shown according to the display mode chosen above.',

    // ---------- gallery ----------
    'blocks.gallery.label' => 'Gallery',
    'blocks.gallery.description' => 'Grid of images with lightbox.',
    'blocks.gallery.fields.title.label' => 'Title',
    'blocks.gallery.fields.title.default' => 'Gallery',
    'blocks.gallery.fields.layout.label' => 'Layout',
    'blocks.gallery.fields.layout.options.mosaic' => 'Mosaic',
    'blocks.gallery.fields.layout.options.grid' => 'Uniform grid',
    'blocks.gallery.fields.layout.options.carousel' => 'Carousel',
    'blocks.gallery.fields.items.label' => 'Images',
    'blocks.gallery.fields.items.of.image.label' => 'Image',
    'blocks.gallery.fields.items.of.alt.label' => 'Alternative text',
    'blocks.gallery.fields.items.of.caption.label' => 'Caption',
    'blocks.gallery.fields.items.of.link.label' => 'Link (optional)',

    // ---------- podcast ----------
    'blocks.podcast.label' => 'Podcast',
    'blocks.podcast.description' => 'Tile for your podcast with links to Apple Podcasts, Spotify and/or your own site. At least one is required.',
    'blocks.podcast.fields.title.label' => 'Section title',
    'blocks.podcast.fields.title.default' => 'Listen to the podcast',
    'blocks.podcast.fields.subtitle.label' => 'Subtitle',
    'blocks.podcast.fields.show_name.label' => 'Podcast name',
    'blocks.podcast.fields.show_name.placeholder' => 'e.g. My podcast',
    'blocks.podcast.fields.show_name.help' => 'Shown above the platform buttons. Leave empty to hide.',
    'blocks.podcast.fields.apple_url.label' => 'Apple Podcasts link',
    'blocks.podcast.fields.apple_url.help' => 'Older "itunes.apple.com" URLs are accepted too. When provided, it can be used to embed the player.',
    'blocks.podcast.fields.spotify_url.label' => 'Spotify link',
    'blocks.podcast.fields.spotify_url.help' => 'When provided, it can be used to embed the player.',
    'blocks.podcast.fields.site_url.label' => 'Site link (for online listening)',
    'blocks.podcast.fields.site_url.help' => 'Your own page where the podcast can be listened to. Shown as a "Site" button.',
    'blocks.podcast.fields.preferred_player.label' => 'Main player',
    'blocks.podcast.fields.preferred_player.options.auto' => 'Auto (Spotify > Apple)',
    'blocks.podcast.fields.preferred_player.options.none' => 'Buttons only (no player)',
    'blocks.podcast.fields.preferred_player.help' => 'Which player to show full-width at the top. The other platforms become clickable buttons below. "Auto" picks Spotify if available (more common in Italy), otherwise Apple. "Buttons only" skips the player → compact tile. The player automatically shows "show" or "single episode" based on the URL type (e.g. /show/… vs /episode/… on Spotify, or ?i=… on Apple).',
    'blocks.podcast.fields.description.label' => 'Description (optional)',
    'blocks.podcast.fields.description.placeholder' => 'One or two lines of intro',

    // ---------- youtube ----------
    'blocks.youtube.label' => 'YouTube',
    'blocks.youtube.description' => 'Shows the latest video from a YouTube channel or playlist. Auto-updates.',
    'blocks.youtube.fields.title.label' => 'Section title',
    'blocks.youtube.fields.title.default' => 'Latest video',
    'blocks.youtube.fields.subtitle.label' => 'Subtitle',
    'blocks.youtube.fields.source_url.label' => 'Channel or playlist URL',
    'blocks.youtube.fields.source_url.help' => 'Paste the channel URL (e.g. https://www.youtube.com/channel/UC…) or a playlist (…/playlist?list=PL…). For @handle URLs, copy the channel\'s "Share" link instead — @handles don\'t directly expose the ID we need.',
    'blocks.youtube.fields.mode.label' => 'What to show',
    'blocks.youtube.fields.mode.options.latest' => 'Latest published video',
    'blocks.youtube.fields.mode.options.playlist' => 'Full playlist (navigable)',
    'blocks.youtube.fields.mode.help' => '"Latest video" mode reads the public feed of the channel/playlist and shows only the most recent, auto-refreshing. "Full playlist" mode embeds the entire navigable playlist (requires a playlist URL).',
    'blocks.youtube.fields.aspect.label' => 'Player aspect ratio',
    'blocks.youtube.fields.aspect.options.16_9' => '16:9 (horizontal)',
    'blocks.youtube.fields.aspect.options.9_16' => '9:16 (vertical / Shorts)',
    'blocks.youtube.fields.aspect.options.1_1' => '1:1 (square)',

    // ---------- embed ----------
    'blocks.embed.label' => 'Embed',
    'blocks.embed.description' => 'YouTube, Spotify, Vimeo, free iframe.',
    'blocks.embed.fields.title.label' => 'Title',
    'blocks.embed.fields.provider.label' => 'Provider',
    'blocks.embed.fields.provider.options.iframe' => 'Free iframe',
    'blocks.embed.fields.url.label' => 'Content URL',
    'blocks.embed.fields.url.help' => 'Public URL of the media (e.g. YouTube video).',
    'blocks.embed.fields.aspect.label' => 'Aspect ratio',
    'blocks.embed.fields.aspect.options.9_16' => '9:16 (vertical)',

    // ---------- contact ----------
    'blocks.contact.label' => 'Contact',
    'blocks.contact.description' => 'Contact form. Saves messages to the DB and (if configured) sends email notification.',
    'blocks.contact.fields.title.label' => 'Title',
    'blocks.contact.fields.title.default' => 'Write to me',
    'blocks.contact.fields.subtitle.label' => 'Subtitle',
    'blocks.contact.fields.success_message.label' => 'Confirmation message',
    'blocks.contact.fields.success_message.default' => 'Thanks, I\'ll reply soon.',
    'blocks.contact.fields.fields.label' => 'Form fields',
    'blocks.contact.fields.fields.default.name' => 'Name',
    'blocks.contact.fields.fields.default.email' => 'Email',
    'blocks.contact.fields.fields.default.message' => 'Message',
    'blocks.contact.fields.fields.of.key.label' => 'Key',
    'blocks.contact.fields.fields.of.label.label' => 'Label',
    'blocks.contact.fields.fields.of.type.label' => 'Type',
    'blocks.contact.fields.fields.of.type.options.text' => 'Text',
    'blocks.contact.fields.fields.of.type.options.email' => 'Email',
    'blocks.contact.fields.fields.of.type.options.tel' => 'Phone',
    'blocks.contact.fields.fields.of.type.options.textarea' => 'Text area',
    'blocks.contact.fields.fields.of.required.label' => 'Required',

    // ---------- divider ----------
    'blocks.divider.label' => 'Divider',
    'blocks.divider.description' => 'Visual divider made of tiles.',
    'blocks.divider.fields.style.label' => 'Style',
    'blocks.divider.fields.style.options.tessera' => 'Tiles (3 diamonds)',
    'blocks.divider.fields.style.options.dots' => 'Dots (3)',
    'blocks.divider.fields.style.options.line' => 'Thin line',
    'blocks.divider.fields.style.options.line-double' => 'Double line',
    'blocks.divider.fields.style.options.line-dashed' => 'Thin dashed line',
    'blocks.divider.fields.style.options.line-dashed-thick' => 'Thick dashed line',
    'blocks.divider.fields.style.options.diagonal' => 'Diagonal lines',
    'blocks.divider.fields.style.options.wave' => 'Wave',
    'blocks.divider.fields.style.options.chevrons' => 'Chevrons (3)',
    'blocks.divider.fields.style.options.floral' => 'Floral decoration',
    'blocks.divider.fields.style.options.stars' => 'Stars (3)',
    'blocks.divider.fields.style.options.space' => 'Just space',

    // ---------- footer ----------
    'blocks.footer.label' => 'Footer',
    'blocks.footer.description' => 'Page footer with copyright and minor links.',
    'blocks.footer.fields.text.label' => 'Text',
    'blocks.footer.fields.show_powered_by.label' => 'Show "powered by tylio"',
    'blocks.footer.fields.show_powered_by.help' => 'If you keep this credit active, a small clickable "powered by tylio" appears at the bottom of the site. We appreciate you keeping it: it helps spread the word about the project, it\'s free and open-source. You can always turn it off at any time.',
    'blocks.footer.fields.links.label' => 'Links',
    'blocks.footer.fields.links.of.label.label' => 'Text',
    'blocks.footer.fields.links.of.url.label' => 'URL',
];
