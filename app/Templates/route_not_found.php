<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $theme
 * @var array $settings
 * @var string $locale
 * @var string $title
 * @var string $path
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
if (!preg_match('/^[A-Za-z0-9 ]{1,60}$/', $headingFont)) $headingFont = 'Fraunces';
if (!preg_match('/^[A-Za-z0-9 ]{1,60}$/', $bodyFont)) $bodyFont = 'Inter';
$fontFamilyParam = rawurlencode($headingFont) . ':wght@500;600&family=' . rawurlencode($bodyFont) . ':wght@400;500';

$lang = strtolower(substr($locale, 0, 2));
$fallbacks = [
    'it' => [
        'eyebrow' => 'errore 404',
        'headline' => 'Pagina non trovata',
        'message_with_path' => 'Il percorso %s non esiste su questo sito.',
        'message_generic' => 'La pagina che cerchi non esiste su questo sito.',
        'button' => 'Torna alla home',
    ],
    'en' => [
        'eyebrow' => 'error 404',
        'headline' => 'Page not found',
        'message_with_path' => "The path %s doesn't exist on this site.",
        'message_generic' => "The page you're looking for doesn't exist on this site.",
        'button' => 'Back to home',
    ],
];
$fb = $fallbacks[$lang] ?? $fallbacks['en'];

$pageTitle = htmlspecialchars($title !== '' ? $title : 'tylio', ENT_QUOTES, 'UTF-8');
$eyebrow = htmlspecialchars($fb['eyebrow'], ENT_QUOTES, 'UTF-8');
$headline = htmlspecialchars($fb['headline'], ENT_QUOTES, 'UTF-8');
$buttonLabel = htmlspecialchars($fb['button'], ENT_QUOTES, 'UTF-8');

$pathClean = ltrim((string)$path, '/');
$showPath = $pathClean !== '' && $pathClean !== 'index.php' && strlen($pathClean) <= 80;
$pathHtml = $showPath ? '<code class="path">' . htmlspecialchars('/' . $pathClean, ENT_QUOTES, 'UTF-8') . '</code>' : '';
$bodyText = $showPath
    ? sprintf(htmlspecialchars($fb['message_with_path'], ENT_QUOTES, 'UTF-8'), $pathHtml)
    : htmlspecialchars($fb['message_generic'], ENT_QUOTES, 'UTF-8');
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
    display: inline-block;
    padding: 4px 12px;
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--accent);
    font-size: 12px;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    margin-bottom: 18px;
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
    margin-bottom: 26px;
  }
  p.msg code.path {
    display: inline-block;
    padding: 2px 8px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    font-family: "SF Mono", Menlo, Consolas, monospace;
    font-size: 0.92em;
    color: var(--text);
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
  }
  a.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 22px;
    border-radius: 12px;
    background: var(--accent);
    color: var(--bg);
    font-weight: 600;
    font-size: 0.98rem;
    text-decoration: none;
    box-shadow: 0 8px 22px -10px color-mix(in srgb, var(--accent) 70%, transparent);
    transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
  }
  a.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 28px -12px color-mix(in srgb, var(--accent) 80%, transparent);
    filter: brightness(1.05);
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
  <div class="box" role="alert">
    <span class="badge"><?= $eyebrow ?></span>
    <h1><?= $headline ?></h1>
    <p class="msg"><?= $bodyText ?></p>
    <a class="btn" href="/"><?= $buttonLabel ?></a>
    <div class="site"><strong><?= $pageTitle ?></strong></div>
  </div>
</body>
</html>
