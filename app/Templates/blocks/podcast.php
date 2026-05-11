<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 * @var array $style
 */
$title = trim((string)($data['title'] ?? ''));
$subtitle = trim((string)($data['subtitle'] ?? ''));
$showName = trim((string)($data['show_name'] ?? ''));
$description = trim((string)($data['description'] ?? ''));
$preferred = (string)($data['preferred_player'] ?? 'auto');

$appleUrl = trim((string)($data['apple_url'] ?? ''));
$spotifyUrl = trim((string)($data['spotify_url'] ?? ''));
$siteUrl = trim((string)($data['site_url'] ?? ''));

// At least one link is required: if all are empty, render nothing
// (consistent with other blocks: no useful data → no tile).
if ($appleUrl === '' && $spotifyUrl === '' && $siteUrl === '') return;

// Parse each platform URL — even for card-buttons we need the right
// icon + label. `kind` (show vs episode) is auto-derived from the URL
// shape, no extra parameters needed.
$apple = $appleUrl !== '' ? \Tylio\Util\PodcastEmbed::parse($appleUrl) : null;
$spotify = $spotifyUrl !== '' ? \Tylio\Util\PodcastEmbed::parse($spotifyUrl) : null;

// Apple player theme = site theme (auto-derived, no user choice).
// Apple supports theme=dark|light|auto. If the user picked 'auto' for
// the site theme (follow device) we pass 'auto' to Apple too, so player
// and site react together to the visitor's preferences.
$siteThemeMode = (string)($theme['mode'] ?? 'auto');
if (!in_array($siteThemeMode, ['dark', 'light', 'auto'], true)) $siteThemeMode = 'auto';
if ($apple !== null && $apple['embed_url'] !== null) {
    $sep = str_contains($apple['embed_url'], '?') ? '&' : '?';
    $apple['embed_url'] .= $sep . 'theme=' . $siteThemeMode;
}

// Decide which player to embed. 'auto' prefers Spotify (more widely
// used), then Apple. 'none' = buttons only.
$playerInfo = null;
if ($preferred === 'spotify' && $spotify && $spotify['embed_url']) {
    $playerInfo = $spotify;
} elseif ($preferred === 'apple' && $apple && $apple['embed_url']) {
    $playerInfo = $apple;
} elseif ($preferred === 'auto') {
    if ($spotify && $spotify['embed_url']) $playerInfo = $spotify;
    elseif ($apple && $apple['embed_url']) $playerInfo = $apple;
}
// $preferred === 'none' or no player available → only buttons remain.

// Platform buttons, fixed order (Apple → Spotify → Site). We ALWAYS show
// every available link, including the one already used as the main
// player: users want to be able to "jump" to the native app to listen
// in their preferred client.
$buttons = [];
if ($appleUrl !== '') {
    $buttons[] = [
        'url' => $appleUrl,
        'icon' => 'simple-icons:applepodcasts',
        'label' => 'Apple Podcasts',
        'platform' => 'apple',
    ];
}
if ($spotifyUrl !== '') {
    $buttons[] = [
        'url' => $spotifyUrl,
        'icon' => 'simple-icons:spotify',
        'label' => 'Spotify',
        'platform' => 'spotify',
    ];
}
if ($siteUrl !== '') {
    $buttons[] = [
        'url' => $siteUrl,
        'icon' => 'lucide:globe',
        'label' => 'Sito',
        'platform' => 'site',
    ];
}
?>
<?php if ($title !== ''): ?><h2 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h2><?php endif; ?>
<?php if ($subtitle !== ''): ?><p class="m-muted m-podcast__subtitle"><?= $renderer->escape($subtitle) ?></p><?php endif; ?>
<?php if ($showName !== ''): ?><p class="m-podcast__show-name"><?= $renderer->escape($showName) ?></p><?php endif; ?>

<?php if ($playerInfo !== null && $playerInfo['embed_url'] !== null): ?>
  <?php $heightClass = 'm-podcast--' . $playerInfo['platform']; ?>
  <div class="m-podcast m-podcast--embed <?= $renderer->escape($heightClass) ?>" data-kind="<?= $renderer->escape($playerInfo['kind']) ?>">
    <iframe
      src="<?= $renderer->escape($playerInfo['embed_url']) ?>"
      title="<?= $renderer->escape($playerInfo['platform_label']) ?>"
      loading="lazy"
      allow="autoplay; clipboard-write; encrypted-media; picture-in-picture"
      allowfullscreen
      referrerpolicy="strict-origin-when-cross-origin"
    ></iframe>
  </div>
<?php endif; ?>

<?php if (!empty($buttons)): ?>
  <div class="m-podcast__buttons">
    <?php foreach ($buttons as $btn): ?>
      <a
        class="m-podcast-btn m-podcast-btn--<?= $renderer->escape($btn['platform']) ?>"
        href="<?= $renderer->escape($renderer->safeUrl($btn['url'], '#')) ?>"
        target="_blank"
        rel="noopener"
      >
        <iconify-icon icon="<?= $renderer->escape($btn['icon']) ?>" width="20"></iconify-icon>
        <span><?= $renderer->escape($btn['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($description !== ''): ?>
  <p class="m-podcast__desc"><?= nl2br($renderer->escape($description), false) ?></p>
<?php endif; ?>
