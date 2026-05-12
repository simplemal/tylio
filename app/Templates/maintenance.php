<?php
/**
 * Public-facing maintenance page. Rendered by `PageController::home()`
 * when `settings['site.maintenance']` is true AND the visitor isn't the
 * authenticated admin (the admin keeps seeing the real site so they can
 * still preview their work in-place).
 *
 * Self-contained: inlines theme palette + a single Google Fonts request
 * for the heading font. No JS, no API calls, no tracking — the goal is
 * a page that renders even if half the stack is broken.
 *
 * Expected variables:
 *
 * @var \Tylio\Services\Renderer $renderer
 * @var array $theme
 * @var array $settings
 * @var string $locale     resolved site locale (e.g. 'it', 'en')
 * @var string $title      site title (already escaped by caller? no — we escape here)
 * @var string $message    user-provided message (raw, we escape here). Empty → fallback.
 */
$palette = $theme['palette'] ?? [];
$bg = (string)($palette['bg'] ?? '#0f0d0a');
$surface = (string)($palette['surface'] ?? '#1a1612');
$text = (string)($palette['text'] ?? '#f4ede1');
$textMuted = (string)($palette['text_muted'] ?? '#9c8e7c');
$accent = (string)($palette['accent'] ?? '#d4a574');
$border = (string)($palette['border'] ?? 'rgba(244,237,225,0.08)');

$headingFont = (string)($theme['font']['heading'] ?? 'Fraunces');
$bodyFont = (string)($theme['font']['body'] ?? 'Inter');
// Whitelist sanitizer (matches ThemeController::isValidFontFamily): only
// letters/digits/spaces in the embedded <link> URL to avoid injection.
if (!preg_match('/^[A-Za-z0-9 ]{1,60}$/', $headingFont)) $headingFont = 'Fraunces';
if (!preg_match('/^[A-Za-z0-9 ]{1,60}$/', $bodyFont)) $bodyFont = 'Inter';
$fontFamilyParam = rawurlencode($headingFont) . ':wght@500;600&family=' . rawurlencode($bodyFont) . ':wght@400;500';

// Localized fallbacks. We don't pull these from the i18n bundle because
// the page must work even if the I18n service can't find a translation
// file — small hardcoded strings are intentional here.
$lang = strtolower(substr($locale, 0, 2));
$fallbacks = [
    'it' => [
        'title' => 'In manutenzione',
        'message' => 'Stiamo lavorando ad alcuni aggiornamenti. Torna tra qualche minuto.',
    ],
    'en' => [
        'title' => 'Under maintenance',
        'message' => "We're making a few updates. Please check back in a few minutes.",
    ],
];
$fb = $fallbacks[$lang] ?? $fallbacks['en'];

$pageTitle = htmlspecialchars($title !== '' ? $title : 'tylio', ENT_QUOTES, 'UTF-8');
$headline = htmlspecialchars($fb['title'], ENT_QUOTES, 'UTF-8');
$bodyText = htmlspecialchars($message !== '' ? $message : $fb['message'], ENT_QUOTES, 'UTF-8');
// Preserve user newlines in the message (Settings textarea is plain text).
$bodyText = nl2br($bodyText);
?><!doctype html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<title><?= $pageTitle ?> · <?= $headline ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=<?= $fontFamilyParam ?>&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: <?= htmlspecialchars($bg, ENT_QUOTES, 'UTF-8') ?>;
    --surface: <?= htmlspecialchars($surface, ENT_QUOTES, 'UTF-8') ?>;
    --text: <?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') ?>;
    --text-muted: <?= htmlspecialchars($textMuted, ENT_QUOTES, 'UTF-8') ?>;
    --accent: <?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>;
    --border: <?= htmlspecialchars($border, ENT_QUOTES, 'UTF-8') ?>;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { min-height: 100vh; }
  body {
    background: var(--bg);
    color: var(--text);
    font: 16px/1.55 "<?= htmlspecialchars($bodyFont, ENT_QUOTES, 'UTF-8') ?>", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    display: grid;
    place-items: center;
    padding: 32px 20px;
  }
  .box {
    max-width: 540px;
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 22px;
    padding: 36px 32px;
    text-align: center;
    box-shadow: 0 30px 80px -50px rgba(0,0,0,0.45);
  }
  .badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 4px 12px;
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--accent);
    font-size: 12px;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    margin-bottom: 18px;
  }
  .badge::before {
    content: '';
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--accent);
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent) 25%, transparent);
    animation: pulse 2s ease-in-out infinite;
  }
  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.45; }
  }
  h1 {
    font-family: "<?= htmlspecialchars($headingFont, ENT_QUOTES, 'UTF-8') ?>", Georgia, serif;
    font-weight: 600;
    font-size: clamp(1.8rem, 5vw, 2.4rem);
    letter-spacing: -0.01em;
    margin-bottom: 14px;
  }
  p.msg {
    color: var(--text-muted);
    font-size: 1rem;
    line-height: 1.65;
  }
  .site {
    margin-top: 28px;
    padding-top: 18px;
    border-top: 1px solid var(--border);
    color: var(--text-muted);
    font-size: 13px;
  }
  .site strong { color: var(--text); font-weight: 600; }
</style>
</head>
<body>
  <div class="box" role="status" aria-live="polite">
    <span class="badge"><?= $headline ?></span>
    <h1><?= $headline ?></h1>
    <p class="msg"><?= $bodyText ?></p>
    <div class="site"><strong><?= $pageTitle ?></strong></div>
  </div>
</body>
</html>
