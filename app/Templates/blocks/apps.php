<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 */
$items = $data['items'] ?? [];
$title = $data['title'] ?? '';
$subtitle = $data['subtitle'] ?? '';
$cols = $data['columns'] ?? '2';
?>
<div class="m-apps">
  <?php if ($title || $subtitle): ?>
  <div class="m-apps__head">
    <div>
      <?php if ($title): ?><h2 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h2><?php endif; ?>
      <?php if ($subtitle): ?><p class="m-muted m-block__subtitle"><?= $renderer->escape($subtitle) ?></p><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <div class="m-apps__grid" data-cols="<?= $renderer->escape($cols) ?>">
  <?php foreach ($items as $app):
      $name = (string)($app['name'] ?? '');
      if ($name === '') continue;
      // Validated as a CSS color (hex/rgb). null when unparseable →
      // prevents CSS injection.
      $accent = $renderer->cssColor((string)($app['accent'] ?? ''));
      $appUrl = $renderer->safeUrl((string)($app['url'] ?? ''), '');
      $hasUrl = $appUrl !== '';
      // Tag: a single-string text field, but we support multiple
      // comma-separated tags so the user can type "web, open source,
      // design" and see them as distinct badges. Trim + dedupe + max 4
      // to avoid saturating the card.
      $tagsRaw = strip_tags((string)($app['tag'] ?? ''));
      $tags = array_values(array_unique(array_filter(array_map(
          static fn($t) => trim($t),
          explode(',', $tagsRaw)
      ), static fn($t) => $t !== '')));
      $tags = array_slice($tags, 0, 4);
      // Inline: --app-accent + --app-accent-fg (auto WCAG-contrast text on the badge)
      $styleParts = [];
      if ($accent !== null) {
          $styleParts[] = '--app-accent:' . $accent;
          $styleParts[] = '--app-accent-fg:' . $renderer->contrastFg($accent);
      }
      $accentStyle = $styleParts ? ' style="' . $renderer->escape(implode(';', $styleParts)) . '"' : '';
      $appStoreUrl = $renderer->safeUrl((string)($app['app_store'] ?? ''), '');
      $playStoreUrl = $renderer->safeUrl((string)($app['play_store'] ?? ''), '');
  ?>
    <?= $hasUrl ? '<a' : '<div' ?> class="m-app"<?= $accentStyle ?><?= $hasUrl ? ' href="' . $renderer->escape($appUrl) . '" target="_blank" rel="noopener"' : '' ?>>
      <?php if (!empty($app['cover_image'])): ?>
        <img class="m-app__cover" src="<?= $renderer->escape($app['cover_image']) ?>" alt="" aria-hidden="true" loading="lazy">
      <?php endif; ?>
      <div class="m-app__inner">
        <?php if ($tags): ?>
          <div class="m-app__tags">
            <?php foreach ($tags as $t): ?>
              <span class="m-app__tag"><?= $renderer->escape($t) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="m-app__head">
          <?php if (!empty($app['icon_image'])): ?>
            <img class="m-app__icon" src="<?= $renderer->escape($app['icon_image']) ?>" alt="" loading="lazy">
          <?php endif; ?>
          <div>
            <h3 class="m-app__name"><?= $renderer->escape($name) ?></h3>
            <?php if (!empty($app['tagline'])): ?>
              <p class="m-app__tagline"><?= $renderer->escape($app['tagline']) ?></p>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($app['description'])): ?>
          <p class="m-app__desc"><?= $renderer->escape($app['description']) ?></p>
        <?php endif; ?>
        <?php if ($appStoreUrl !== '' || $playStoreUrl !== ''): ?>
          <div class="m-app__stores">
            <?php if ($appStoreUrl !== ''): ?>
              <a class="m-store" href="<?= $renderer->escape($appStoreUrl) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M16.4 12.6c0-2.3 1.9-3.4 2-3.4-1.1-1.6-2.7-1.8-3.3-1.9-1.4-.1-2.7.8-3.5.8-.7 0-1.8-.8-3-.8-1.5 0-2.9.9-3.7 2.3-1.6 2.7-.4 6.7 1.1 8.9.8 1.1 1.6 2.3 2.8 2.2 1.1 0 1.6-.7 3-.7 1.4 0 1.8.7 3 .7 1.3 0 2.1-1.1 2.8-2.2.9-1.3 1.3-2.5 1.3-2.6-.1 0-2.5-1-2.5-3.3M14 5.4c.6-.7 1-1.7.9-2.7-.9.1-1.9.6-2.5 1.3-.5.6-1 1.6-.9 2.6 1 .1 2-.5 2.5-1.2"/></svg>
                <span>App Store</span>
              </a>
            <?php endif; ?>
            <?php if ($playStoreUrl !== ''): ?>
              <a class="m-store" href="<?= $renderer->escape($playStoreUrl) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3.6 2.3C3.2 2.6 3 3.1 3 3.7v16.6c0 .6.2 1.1.6 1.4l9-9-9-9.4M14.3 13l2.5 2.5L5 21.7l9.3-8.7M14.3 11L5 2.3l11.8 6.2L14.3 11M21 12c0 .8-.4 1.5-1.1 1.9l-2.4 1.4-2.7-2.7L17.5 10l2.4 1.4c.7.4 1.1 1.1 1.1 1.6"/></svg>
                <span>Google Play</span>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?= $hasUrl ? '</a>' : '</div>' ?>
  <?php endforeach; ?>
  </div>
</div>
