<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 * @var array $style
 */
$title = trim((string)($data['title'] ?? ''));
$subtitle = trim((string)($data['subtitle'] ?? ''));
$source = trim((string)($data['source_url'] ?? ''));
$mode = (string)($data['mode'] ?? 'latest');
$aspect = (string)($data['aspect'] ?? '16:9');

// We pass $config to the parser so it can resolve "vanity" URLs
// (@handle, /c/, /user/, /<bare>) via HTML scraping.
$config = $renderer->config();
[$type, $id] = \Tylio\Util\YouTubeFeed::parseSource($source, $config);
if (!$id || !$type) {
    if ($source !== ''):
?>
<?php if ($title !== ''): ?><h2 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h2><?php endif; ?>
<p class="m-yt__fallback">
  <iconify-icon icon="lucide:alert-triangle" width="18"></iconify-icon>
  YouTube URL not recognized. Copy the link from YouTube using
  <strong>Share → Copy channel link</strong> or paste a playlist URL
  (https://www.youtube.com/playlist?list=…).
</p>
<?php
    endif;
    return;
}

// Load feed info (RSS — 1h cache): channel/playlist title, canonical
// link, latest video.
$feedInfo = \Tylio\Util\YouTubeFeed::fetchFeedInfo($config, $type, $id);
$videoId = $feedInfo && !empty($feedInfo['entries']) ? (string)$feedInfo['entries'][0]['video_id'] : null;

// For channels, also fetch extended metadata (avatar, banner,
// description) by scraping the HTML page. 7-day cache. Non-blocking: on
// failure we just fall back to the RSS feed name.
$channelMeta = null;
if ($type === 'channel') {
    $channelMeta = \Tylio\Util\YouTubeFeed::fetchChannelMeta($config, $id);
}

$embedUrl = null;
if ($mode === 'playlist' && $type === 'playlist') {
    $embedUrl = "https://www.youtube.com/embed/videoseries?list={$id}";
} elseif ($videoId !== null) {
    $embedUrl = "https://www.youtube.com/embed/{$videoId}";
}

$aspectMap = ['16:9' => '16/9', '9:16' => '9/16', '4:3' => '4/3', '1:1' => '1/1'];
$aspectCss = $aspectMap[$aspect] ?? '16/9';

// Header pill: channel avatar (if any) + name + link-out. For channels
// we do NOT add a "on YouTube" subtitle — the iframe below and the
// link-out icon already make the destination obvious. For playlists we
// keep "by [author]" because it's useful info not implied elsewhere.
$headerName = '';
$headerSubtext = '';
$headerAvatar = null;
$headerLink = '';
if ($type === 'channel') {
    $headerName = $channelMeta['name'] ?? ($feedInfo['feed_title'] ?? '');
    $headerAvatar = $channelMeta['avatar'] ?? null;
    $headerLink = "https://www.youtube.com/channel/{$id}";
} elseif ($type === 'playlist') {
    $headerName = $feedInfo['feed_title'] ?? 'Playlist';
    $headerLink = "https://www.youtube.com/playlist?list={$id}";
    if (!empty($feedInfo['author_name'])) {
        $headerSubtext = 'by ' . $feedInfo['author_name'];
    }
}

// Latest entry for the title pill + date under the player. YouTube
// truncates the title in its own overlay when space is tight: by
// rendering it OURSELVES in HTML we get the full text, the theme font,
// a clickable link, and a relative date ("3 days ago") the player
// doesn't provide.
$lastEntry = ($feedInfo && !empty($feedInfo['entries'])) ? $feedInfo['entries'][0] : null;

$fallbackUrl = $type === 'channel'
    ? "https://www.youtube.com/channel/{$id}"
    : "https://www.youtube.com/playlist?list={$id}";
?>
<?php if ($title !== ''): ?><h2 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h2><?php endif; ?>
<?php if ($subtitle !== ''): ?><p class="m-muted m-yt__subtitle"><?= $renderer->escape($subtitle) ?></p><?php endif; ?>

<?php /* Required order: video → video title+date → channel pill →
        description. The pill "belongs" to the description (whose channel
        you're reading the bio of), not to the tile title; above the video
        it looked like a redundant second heading. */ ?>
<?php if ($embedUrl !== null): ?>
  <div class="m-yt" style="aspect-ratio:<?= $renderer->escape($aspectCss) ?>">
    <iframe
      src="<?= $renderer->escape($embedUrl) ?>"
      title="YouTube video"
      loading="lazy"
      allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
      allowfullscreen
      referrerpolicy="strict-origin-when-cross-origin"
    ></iframe>
  </div>
<?php else: ?>
  <p class="m-yt__fallback">
    <iconify-icon icon="simple-icons:youtube" width="18"></iconify-icon>
    <?= $renderer->escape($renderer->t('public.youtube.unavailable')) ?> —
    <a href="<?= $renderer->escape($fallbackUrl) ?>" target="_blank" rel="noopener"><?= $renderer->escape($renderer->t('public.youtube.open_on_yt')) ?></a>
  </p>
<?php endif; ?>

<?php if ($lastEntry && $videoId !== null): ?>
  <div class="m-yt__last">
    <a class="m-yt__last-title" href="https://www.youtube.com/watch?v=<?= $renderer->escape($lastEntry['video_id']) ?>" target="_blank" rel="noopener">
      <?= $renderer->escape($lastEntry['title']) ?>
    </a>
    <?php if (!empty($lastEntry['published'])):
        $rel = $renderer->relativeDate($lastEntry['published']);
        if ($rel !== ''): ?>
      <time class="m-yt__last-date" datetime="<?= $renderer->escape($lastEntry['published']) ?>"><?= $renderer->escape($rel) ?></time>
        <?php endif;
    endif; ?>
  </div>
<?php endif; ?>

<?php if ($headerName !== ''): ?>
<a class="m-yt__header m-yt__header--bottom" href="<?= $renderer->escape($renderer->safeUrl($headerLink, '#')) ?>" target="_blank" rel="noopener">
  <?php if ($headerAvatar): ?>
    <img class="m-yt__avatar" src="<?= $renderer->escape($headerAvatar) ?>" alt="" loading="lazy" width="40" height="40" referrerpolicy="no-referrer">
  <?php else: ?>
    <span class="m-yt__avatar m-yt__avatar--placeholder">
      <iconify-icon icon="simple-icons:youtube" width="22"></iconify-icon>
    </span>
  <?php endif; ?>
  <span class="m-yt__header-text">
    <span class="m-yt__name"><?= $renderer->escape($headerName) ?></span>
    <?php if ($headerSubtext !== ''): ?>
      <span class="m-yt__handle"><?= $renderer->escape($headerSubtext) ?></span>
    <?php endif; ?>
  </span>
  <iconify-icon class="m-yt__header-chev" icon="lucide:external-link" width="14"></iconify-icon>
</a>
<?php endif; ?>

<?php if ($type === 'channel' && !empty($channelMeta['description'])): ?>
  <p class="m-yt__desc"><?= $renderer->linkify($channelMeta['description']) ?></p>
<?php endif; ?>
