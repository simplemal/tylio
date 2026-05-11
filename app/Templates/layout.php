<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $theme
 * @var array $settings
 * @var array $blocks
 * @var string $appUrl
 */
$siteTitle = $renderer->settingsValue($settings, 'site.title', 'tylio');
$siteTagline = $renderer->settingsValue($settings, 'site.tagline', '');
$siteDescription = $renderer->settingsValue($settings, 'site.description', '');
// Public locale: explicit `settings.site.locale` wins; otherwise we use
// whatever I18n negotiated for this render (Accept-Language → default).
$locale = (string)$renderer->settingsValue($settings, 'site.locale', '');
if ($locale === '') $locale = $renderer->i18n()->currentLocale();
$author = $renderer->settingsValue($settings, 'site.author', '');
$ogImage = $renderer->settingsValue($settings, 'seo.og_image', '');
$faviconVersion = (string)$renderer->settingsValue($settings, 'seo.favicon', '');
$faviconBase = '/favicons';
$hasFavicon = $faviconVersion !== '' && file_exists(dirname(__DIR__, 2) . '/favicons/icon-32.png');

// SEO extra
$canonical = trim((string)$renderer->settingsValue($settings, 'seo.canonical_url', ''));
if ($canonical === '') $canonical = $appUrl;
$canonical = rtrim($canonical, '/');
$indexable = $renderer->settingsValue($settings, 'seo.robots_index', true);
$twitterHandle = trim((string)$renderer->settingsValue($settings, 'seo.twitter_handle', ''));
if ($twitterHandle && $twitterHandle[0] !== '@') $twitterHandle = '@' . ltrim($twitterHandle, '@');
$cssPath = __DIR__ . '/public.css';
$css = file_exists($cssPath) ? file_get_contents($cssPath) : '';
$themeVars = $renderer->themeCssVars($theme);
$fontHead = $theme['font']['heading'] ?? 'Fraunces';
$fontBody = $theme['font']['body'] ?? 'Inter';
// Generic @wght spec (compatible with any Google Font, variable or static).
// We no longer request `opsz` — it was Fraunces-specific and broke other
// fonts like Space Grotesk.
$wghtAxis = ':wght@400;500;600;700';
$families = [urlencode($fontHead) . $wghtAxis];
if (strcasecmp($fontHead, $fontBody) !== 0) {
    $families[] = urlencode($fontBody) . $wghtAxis;
}
$fontParam = 'family=' . implode('&family=', $families);
?><!doctype html>
<html lang="<?= $renderer->escape($locale) ?>" data-theme-mode="<?= $renderer->escape($theme['mode'] ?? 'auto') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<?php
// theme-color: paints the address bar of Safari (and equivalents on
// Chrome Android, Edge, Firefox mobile) with the theme background color.
// When the background is a PHOTO, the bg color is almost always hidden:
// in that case we fall back to `surface` (tile color), which stays
// visible in the borders and between tiles. The regex only allows hex
// formats (#RGB / #RRGGBB / #RRGGBBAA) to prevent injection.
$_themeColor = ($theme['background']['pattern'] ?? '') === 'image'
    ? ($theme['palette']['surface'] ?? '#1a1612')
    : ($theme['palette']['bg'] ?? '#0f0d0a');
if (!preg_match('/^#[0-9a-f]{3,8}$/i', (string)$_themeColor)) $_themeColor = '#0f0d0a';
?>
<meta name="theme-color" content="<?= $renderer->escape($_themeColor) ?>">
<title><?= $renderer->escape($siteTitle) ?><?= $siteTagline ? ' — ' . $renderer->escape($siteTagline) : '' ?></title>
<meta name="description" content="<?= $renderer->escape($siteDescription) ?>">
<?php if ($author): ?><meta name="author" content="<?= $renderer->escape($author) ?>"><?php endif; ?>
<?php if ($canonical): ?><link rel="canonical" href="<?= $renderer->escape($canonical) ?>/"><?php endif; ?>
<meta name="robots" content="<?= $indexable ? 'index, follow' : 'noindex, nofollow' ?>">

<meta property="og:title" content="<?= $renderer->escape($siteTitle) ?>">
<meta property="og:description" content="<?= $renderer->escape($siteDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="<?= $renderer->escape(str_replace('-', '_', $locale)) ?>_<?= strtoupper(substr($locale, 0, 2)) ?>">
<?php if ($canonical): ?><meta property="og:url" content="<?= $renderer->escape($canonical) ?>/"><?php endif; ?>
<?php if ($siteTitle): ?><meta property="og:site_name" content="<?= $renderer->escape($siteTitle) ?>"><?php endif; ?>
<?php if ($ogImage): ?>
<meta property="og:image" content="<?= $renderer->escape($ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<?php endif; ?>

<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= $renderer->escape($siteTitle) ?>">
<meta name="twitter:description" content="<?= $renderer->escape($siteDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= $renderer->escape($ogImage) ?>"><?php endif; ?>
<?php if ($twitterHandle): ?>
<meta name="twitter:site" content="<?= $renderer->escape($twitterHandle) ?>">
<meta name="twitter:creator" content="<?= $renderer->escape($twitterHandle) ?>">
<?php endif; ?>
<?php if ($hasFavicon):
    $v = $renderer->escape($faviconVersion);
?>
<link rel="icon" type="image/png" sizes="32x32" href="<?= $faviconBase ?>/icon-32.png?v=<?= $v ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= $faviconBase ?>/icon-192.png?v=<?= $v ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $faviconBase ?>/icon-180.png?v=<?= $v ?>">
<?php else: ?>
<link rel="icon" type="image/svg+xml" href="/logo.svg?v=<?= TYLIO_BUILD ?>">
<?php endif; ?>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?<?= $fontParam ?>&display=swap" rel="stylesheet">
<script src="https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js" defer></script>
<style id="tylio-theme">:root{<?= $themeVars ?>}</style>
<style id="tylio-css"><?= $css ?></style>

<?php
// Structured JSON-LD (Person/WebSite) for Google rich results.
$jsonLd = ['@context' => 'https://schema.org'];
if ($author) {
    $jsonLd['@graph'][] = [
        '@type' => 'Person',
        'name' => $author,
        'url' => $canonical ?: null,
        'image' => $ogImage ?: null,
        'sameAs' => [],   // socials will be added if present via blocks (future iteration)
    ];
}
if ($siteTitle && $canonical) {
    $jsonLd['@graph'][] = [
        '@type' => 'WebSite',
        'name' => $siteTitle,
        'description' => $siteDescription,
        'url' => $canonical . '/',
        'inLanguage' => $locale,
    ];
}
if (!empty($jsonLd['@graph'])):
    // cleanup: remove null/empty keys from the output
    $cleanGraph = array_map(static function ($item) {
        return array_filter($item, static fn($v) => $v !== null && $v !== '' && $v !== []);
    }, $jsonLd['@graph']);
    $jsonLd['@graph'] = $cleanGraph;
?>
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
</head>
<?php
$tileStyle = $theme['tile']['style'] ?? 'solid';
$tileOpacity = (float)($theme['tile']['opacity'] ?? 0.7);
// "card" = the page deserves a single perimeter shadow (visually opaque tiles)
$tileCard = (
    $tileStyle === 'solid'
    || $tileStyle === 'glass'
    || ($tileStyle === 'transparent' && $tileOpacity >= 0.95)
);
?>
<body class="m-body"
      data-bg-pattern="<?= $renderer->escape($theme['background']['pattern'] ?? 'mosaic') ?>"
      data-tile-style="<?= $renderer->escape($tileStyle) ?>"
      data-tile-flush="<?= ((float)($theme['tile']['gap'] ?? 14) <= 0) ? '1' : '0' ?>"
      data-tile-card="<?= $tileCard ? '1' : '0' ?>"
      data-mobile-spacing="<?= ($theme['tile']['mobile_spacing'] ?? 'desktop') === 'minimal' ? 'minimal' : 'desktop' ?>">
  <div class="m-bg" aria-hidden="true">
    <div class="m-bg__pattern"></div>
  </div>

  <main class="m-mosaic" id="m-mosaic">
    <?php foreach ($blocks as $block):
        $span = $renderer->blockSpan($block);
        $disabledClass = $block['enabled'] ? '' : ' is-disabled';
        $orphanClass = !empty($block['__orphan']) ? ' m-tile--orphan' : '';
        // Per-block "No background" override: removes the tile's bg/border/shadow
        // ignoring the theme's tile-style. Set on block.style.no_bg.
        $noBgClass = !empty($block['style']['no_bg']) ? ' m-tile--no-bg' : '';
    ?>
      <section class="m-tile m-tile--span<?= $span ?> m-tile--<?= $renderer->escape($block['type']) ?><?= $disabledClass ?><?= $orphanClass ?><?= $noBgClass ?>" data-block-id="<?= (int)$block['id'] ?>" data-block-type="<?= $renderer->escape($block['type']) ?>">
        <?= $renderer->renderBlock($block, $theme) ?>
      </section>
    <?php endforeach; ?>

    <?php if (empty($blocks)): ?>
      <section class="m-tile m-tile--span2 m-tile--empty">
        <h1 class="m-empty-title"><?= $renderer->t('public.empty.title') /* contains <em> */ ?></h1>
        <p class="m-empty-msg"><?= $renderer->escape($renderer->t('public.empty.message')) ?></p>
      </section>
    <?php endif; ?>
  </main>

  <script>
    // ===== Live preview: receive the theme from the admin via postMessage =====
    // Used by the iframe in the Theme editor: no reload needed.
    // SECURITY: messages from origins other than ours are ignored. Without
    // this check, a page that embeds the iframe could inject arbitrary CSS
    // variables. Whitelist of allowed `--*` keys + string-only values (no
    // objects, no functions).
    (() => {
      const SELF_ORIGIN = window.location.origin;
      const ALLOWED_VAR_PREFIX = '--';
      const ALLOWED_BG_PATTERN = /^[a-z][a-z0-9-]{0,30}$/;
      const ALLOWED_TILE_STYLE = /^(solid|transparent|glass)$/;
      const ALLOWED_MOBILE_SPACING = /^(desktop|minimal)$/;
      const VAR_VALUE_MAX = 200; // anti-blob threshold
      // STRICT whitelist for font names: ASCII letters, digits and spaces.
      // Without this regex a same-origin attacker could inject " into the
      // href and reach arbitrary Google Fonts URLs; with the regex the
      // name is then url-encoded and used in a fixed href shape.
      const ALLOWED_FONT_NAME = /^[A-Za-z0-9 ]{1,60}$/;
      const FONT_AXIS = ':wght@400;500;600;700';

      function ensureGoogleFont(name) {
        if (typeof name !== 'string' || !ALLOWED_FONT_NAME.test(name)) return;
        // Already loaded? Avoid duplicates. Unique data-attribute per name.
        const sel = 'link[data-tylio-font="' + CSS.escape(name) + '"]';
        if (document.head.querySelector(sel)) return;
        const family = encodeURIComponent(name).replace(/%20/g, '+');
        const href = 'https://fonts.googleapis.com/css2?family=' + family + FONT_AXIS + '&display=swap';
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.setAttribute('data-tylio-font', name);
        document.head.appendChild(link);
      }

      window.addEventListener('message', (e) => {
        if (e.origin !== SELF_ORIGIN) return;          // ← origin check
        const m = e?.data;
        if (!m || m.type !== 'tylio:applyTheme' || !m.vars) return;
        const root = document.documentElement.style;
        for (const [k, v] of Object.entries(m.vars)) {
          if (typeof k !== 'string' || !k.startsWith(ALLOWED_VAR_PREFIX)) continue;
          if (typeof v !== 'string' || v.length > VAR_VALUE_MAX) continue;
          root.setProperty(k, v);
        }
        // Dynamic fonts: append a Google Fonts <link> for the chosen fonts
        // if not already present. Name validated via ALLOWED_FONT_NAME.
        if (m.fonts && typeof m.fonts === 'object') {
          ensureGoogleFont(m.fonts.heading);
          ensureGoogleFont(m.fonts.body);
        }
        if (typeof m.bgPattern === 'string' && ALLOWED_BG_PATTERN.test(m.bgPattern)) {
          document.body.setAttribute('data-bg-pattern', m.bgPattern);
        }
        if (typeof m.tileStyle === 'string' && ALLOWED_TILE_STYLE.test(m.tileStyle)) {
          document.body.setAttribute('data-tile-style', m.tileStyle);
        }
        if (typeof m.tileFlush !== 'undefined') document.body.setAttribute('data-tile-flush', m.tileFlush ? '1' : '0');
        if (typeof m.tileCard !== 'undefined') document.body.setAttribute('data-tile-card', m.tileCard ? '1' : '0');
        if (typeof m.mobileSpacing === 'string' && ALLOWED_MOBILE_SPACING.test(m.mobileSpacing)) {
          document.body.setAttribute('data-mobile-spacing', m.mobileSpacing);
        }
      });
      // Signal the parent we're ready to receive — same-origin only.
      try { window.parent && window.parent.postMessage({ type: 'tylio:previewReady' }, SELF_ORIGIN); } catch (_) { /* noop */ }
    })();
  </script>
  <script>
    // Simple lightbox (gallery) + small enhancements (no framework)
    (() => {
      const lb = document.createElement('div');
      lb.className = 'm-lightbox';
      lb.innerHTML = '<button class="m-lightbox__close" aria-label="<?= $renderer->escape($renderer->t('public.lightbox.close')) ?>">×</button><img alt="">';
      document.body.appendChild(lb);
      const img = lb.querySelector('img');
      const close = () => lb.classList.remove('is-open');
      lb.addEventListener('click', e => { if (e.target === lb || e.target.classList.contains('m-lightbox__close')) close(); });
      document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
      document.querySelectorAll('[data-lightbox]').forEach(el => {
        el.addEventListener('click', e => {
          e.preventDefault();
          img.src = el.getAttribute('data-lightbox');
          img.alt = el.getAttribute('data-alt') || '';
          lb.classList.add('is-open');
        });
      });

      // Reveal on scroll
      const io = new IntersectionObserver(entries => {
        for (const ent of entries) if (ent.isIntersecting) {
          ent.target.classList.add('is-revealed');
          io.unobserve(ent.target);
        }
      }, { threshold: 0.05 });
      document.querySelectorAll('.m-tile').forEach(t => io.observe(t));
    })();
  </script>
  <script>
    // Click tracking: every <a> inside a tile fires a beacon with the
    // block_id so the dashboard can show "most-clicked tiles". sendBeacon
    // is asynchronous and doesn't block navigation (it even fires after
    // the browser has started unloading the page).
    (() => {
      if (!('sendBeacon' in navigator)) return;
      document.addEventListener('click', (e) => {
        const a = e.target instanceof Element ? e.target.closest('a[href]') : null;
        if (!a) return;
        const tile = a.closest('[data-block-id]');
        if (!tile) return;
        const blockId = parseInt(tile.getAttribute('data-block-id'), 10);
        if (!blockId) return;
        try {
          const blob = new Blob([JSON.stringify({ block_id: blockId })], { type: 'application/json' });
          navigator.sendBeacon('/track-click', blob);
        } catch (_) { /* graceful: no JS = no tracking, OK */ }
      }, { capture: true });
    })();
  </script>
</body>
</html>
