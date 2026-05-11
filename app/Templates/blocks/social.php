<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 */
$items = $data['items'] ?? [];
$title = $data['title'] ?? '';
$display = $data['display'] ?? 'icon_platform';
$align = ($data['align'] ?? 'left') === 'center' ? 'center' : 'left';

// Map: platform → [iconify icon name, display name]
// Icons are loaded dynamically via <iconify-icon> from the Iconify CDN.
// For platforms where the "simple-icons" collection lacks the icon, we
// use a geometric Phosphor fallback (cube for 3D, globe for generics).
$map = [
    // Main social
    'twitter'      => ['simple-icons:x',           'X / Twitter'],
    'instagram'    => ['simple-icons:instagram',   'Instagram'],
    'youtube'      => ['simple-icons:youtube',     'YouTube'],
    'tiktok'       => ['simple-icons:tiktok',      'TikTok'],
    'threads'      => ['simple-icons:threads',     'Threads'],
    'bluesky'      => ['simple-icons:bluesky',     'Bluesky'],
    'mastodon'     => ['simple-icons:mastodon',    'Mastodon'],
    'facebook'     => ['simple-icons:facebook',    'Facebook'],
    'linkedin'     => ['simple-icons:linkedin',    'LinkedIn'],
    'pinterest'    => ['simple-icons:pinterest',   'Pinterest'],
    'snapchat'     => ['simple-icons:snapchat',    'Snapchat'],
    // Dev / open source
    'github'       => ['simple-icons:github',      'GitHub'],
    'gitlab'       => ['simple-icons:gitlab',      'GitLab'],
    'codepen'      => ['simple-icons:codepen',     'CodePen'],
    'stackoverflow'=> ['simple-icons:stackoverflow','Stack Overflow'],
    // Design / portfolio
    'dribbble'     => ['simple-icons:dribbble',    'Dribbble'],
    'behance'      => ['simple-icons:behance',     'Behance'],
    'figma'        => ['simple-icons:figma',       'Figma'],
    // 3D / Maker
    'makerworld'   => ['simple-icons:bambulab',    'MakerWorld'],
    'printables'   => ['simple-icons:printables',  'Printables'],
    'thingiverse'  => ['simple-icons:thingiverse', 'Thingiverse'],
    'cults3d'      => ['lucide:box',          'Cults3D'],
    'thangs'       => ['lucide:box',          'Thangs'],
    'sketchfab'    => ['simple-icons:sketchfab',   'Sketchfab'],
    'cgtrader'     => ['simple-icons:cgtrader',    'CGTrader'],
    // Video / streaming
    'twitch'       => ['simple-icons:twitch',      'Twitch'],
    'vimeo'        => ['simple-icons:vimeo',       'Vimeo'],
    // Audio
    'spotify'      => ['simple-icons:spotify',     'Spotify'],
    'soundcloud'   => ['simple-icons:soundcloud',  'SoundCloud'],
    'applemusic'   => ['simple-icons:applemusic',  'Apple Music'],
    'bandcamp'     => ['simple-icons:bandcamp',    'Bandcamp'],
    // Writing
    'medium'       => ['simple-icons:medium',      'Medium'],
    'substack'     => ['simple-icons:substack',    'Substack'],
    'devto'        => ['simple-icons:devdotto',    'dev.to'],
    'hashnode'     => ['simple-icons:hashnode',    'Hashnode'],
    // Community / chat
    'discord'      => ['simple-icons:discord',     'Discord'],
    'telegram'     => ['simple-icons:telegram',    'Telegram'],
    'whatsapp'     => ['simple-icons:whatsapp',    'WhatsApp'],
    'reddit'       => ['simple-icons:reddit',      'Reddit'],
    'matrix'       => ['simple-icons:matrix',      'Matrix'],
    // Support
    'patreon'      => ['simple-icons:patreon',     'Patreon'],
    'kofi'         => ['simple-icons:kofi',        'Ko-fi'],
    'buymeacoffee' => ['simple-icons:buymeacoffee','Buy Me a Coffee'],
    'liberapay'    => ['simple-icons:liberapay',   'Liberapay'],
    // Generic — labels go through I18n so they follow the site locale.
    'website'      => ['lucide:globe', $renderer->t('public.social.website')],
    'email'        => ['lucide:mail',  $renderer->t('public.social.email')],
    'phone'        => ['lucide:phone', $renderer->t('public.social.phone')],
    'rss'          => ['lucide:rss',   $renderer->t('public.social.rss')],
    'other'        => ['lucide:link',  $renderer->t('public.social.other')],
];

$onlyIcons = ($display === 'icon_only');
?>
<div class="m-social-wrap" data-align="<?= $renderer->escape($align) ?>">
<?php if ($title): ?><h3 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h3><?php endif; ?>
<div class="m-social" data-display="<?= $renderer->escape($display) ?>">
<?php foreach ($items as $it):
    $rawUrl = (string)($it['url'] ?? '');
    if ($rawUrl === '') continue;
    $platform = (string)($it['platform'] ?? 'other');
    [$iconName, $platformName] = $map[$platform] ?? $map['other'];
    $accountLabel = trim((string)($it['label'] ?? ''));

    $showPlatform = in_array($display, ['icon_platform', 'icon_full'], true);
    $showAccount  = in_array($display, ['icon_account', 'icon_full'], true) && $accountLabel !== '';
    $textPieces = [];
    if ($showPlatform) $textPieces[] = $platformName;
    if ($showAccount)  $textPieces[] = $accountLabel;
    if ($display === 'icon_account' && !$showAccount) {
        // fallback: without a label, show the platform name
        $textPieces[] = $platformName;
    }
    $accessibleLabel = $accountLabel !== '' ? "$platformName · $accountLabel" : $platformName;

    // Pre-fix scheme: email/phone have dedicated schemes, anything else
    // MUST have http(s)://. Users often type "google.com" without the
    // protocol — previously safeUrl() silently dropped that. Now we auto-
    // prepend https:// so the link still shows up, no "mysterious
    // disappearing" social entries.
    $href = trim($rawUrl);
    if ($platform === 'email' && !str_starts_with($href, 'mailto:')) {
        $href = 'mailto:' . $href;
    } elseif ($platform === 'phone' && !str_starts_with($href, 'tel:')) {
        $href = 'tel:' . $href;
    } elseif ($href !== '' && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
        // No scheme at all → assume https://. The regex matches any
        // scheme (http:, https:, ftp:, …) so we don't wrongly pre-pend
        // when the user types e.g. "mailto:foo@bar" into a "website"
        // slot. Strip leading slashes to avoid "https:///example.com".
        $href = 'https://' . ltrim($href, '/');
    }
    $href = $renderer->safeUrl($href, '');
    if ($href === '') continue;

    // Bug fix: with platform=website + a display that includes the
    // platform name, multiple website entries all showed the same "Sito
    // web" label, indistinguishable. We use the domain instead (e.g.
    // "tylio.app", "github.com/user") so each entry is recognizable.
    if ($platform === 'website') {
        $host = parse_url($href, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $platformName = preg_replace('/^www\./i', '', $host) ?? $platformName;
        }
    }
?>
  <a class="m-social__a"
     href="<?= $renderer->escape($href) ?>"
     target="_blank" rel="noopener"
     aria-label="<?= $renderer->escape($accessibleLabel) ?>"
     title="<?= $renderer->escape($accessibleLabel) ?>">
    <iconify-icon icon="<?= $renderer->escape($iconName) ?>" width="<?= $onlyIcons ? '20' : '18' ?>"></iconify-icon>
    <?php if (!empty($textPieces)): ?>
      <span class="m-social__text"><?= $renderer->escape(implode(' · ', $textPieces)) ?></span>
    <?php endif; ?>
  </a>
<?php endforeach; ?>
</div>
</div>