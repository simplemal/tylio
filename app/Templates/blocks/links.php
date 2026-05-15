<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 * @var int $blockId
 * @var string $style
 */
$items = $data['items'] ?? [];
$title = $data['title'] ?? '';

$hasCopyable = false;
foreach ($items as $it) {
    if (!empty($it['badge_copyable']) && (string)($it['badge'] ?? '') !== '') {
        $hasCopyable = true;
        break;
    }
}
?>
<?php if ($title): ?><h3 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h3><?php endif; ?>
<ul class="m-links">
<?php foreach ($items as $it):
    $rawUrl = (string)($it['url'] ?? '');
    $url = $renderer->safeUrl($rawUrl, '');
    if ($url === '') continue;
    $label = (string)($it['label'] ?? '');
    if ($label === '') $label = $url;
    $external = (bool)preg_match('#^https?://#i', $url);
    // `icon_mode` is the user's explicit choice (favicon vs custom);
    // when missing on legacy items (saved before the field existed) we
    // infer 'custom' if there's an icon string, 'favicon' otherwise.
    $iconRaw = (string)($it['icon'] ?? '');
    $iconMode = isset($it['icon_mode'])
        ? (string)$it['icon_mode']
        : ($iconRaw !== '' ? 'custom' : 'favicon');
    $iconName = ($iconMode === 'custom') ? $iconRaw : '';
    // Favicon: only for absolute http(s) URLs with a real host.
    // DuckDuckGo's privacy-friendly favicon service avoids leaking visitor
    // IPs to Google. `referrerpolicy=no-referrer` further trims metadata,
    // and an inline SVG fallback covers domains the service can't resolve.
    $faviconHost = '';
    if ($iconName === '' && $external) {
        $h = parse_url($url, PHP_URL_HOST);
        if (is_string($h) && $h !== '') $faviconHost = $h;
    }
    $badge = (string)($it['badge'] ?? '');
    $badgeCopyable = !empty($it['badge_copyable']) && $badge !== '';
    $desc = (string)($it['description'] ?? '');
?>
  <li>
    <div class="m-link">
      <span class="m-link__icon" aria-hidden="true">
        <?php if ($iconName !== ''): ?>
          <iconify-icon icon="<?= $renderer->escape($iconName) ?>" width="20" height="20"></iconify-icon>
        <?php elseif ($faviconHost !== ''): ?>
          <img class="m-link__favicon" src="https://icons.duckduckgo.com/ip3/<?= $renderer->escape($faviconHost) ?>.ico" alt="" width="20" height="20" loading="lazy" referrerpolicy="no-referrer" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'m-link__icon-fallback',innerHTML:'<svg viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' width=\'20\' height=\'20\'><path d=\'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71\'/><path d=\'M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71\'/></svg>'}))">
        <?php else: ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
        <?php endif; ?>
      </span>
      <span class="m-link__body">
        <span class="m-link__label">
          <a class="m-link__title" href="<?= $renderer->escape($url) ?>"<?= $external ? ' rel="noopener noreferrer" target="_blank"' : '' ?>><?= $renderer->escape($label) ?></a>
          <?php if ($badge !== ''): ?>
            <?php if ($badgeCopyable): ?>
              <button type="button" class="m-badge m-badge--copy" data-copy="<?= $renderer->escape($badge) ?>" title="<?= $renderer->escape($renderer->t('public.links.copy_badge')) ?>" aria-label="<?= $renderer->escape($renderer->t('public.links.copy_badge')) ?>">
                <span class="m-badge__text"><?= $renderer->escape($badge) ?></span>
                <iconify-icon class="m-badge__icon" icon="lucide:copy" width="11" aria-hidden="true"></iconify-icon>
              </button>
            <?php else: ?>
              <span class="m-badge"><?= $renderer->escape($badge) ?></span>
            <?php endif; ?>
          <?php endif; ?>
        </span>
        <?php if ($desc !== ''): ?>
          <span class="m-link__desc"><?= $renderer->escape($desc) ?></span>
        <?php endif; ?>
      </span>
    </div>
  </li>
<?php endforeach; ?>
</ul>
<?php if ($hasCopyable): ?>
<script>
(() => {
  const liveMsg = <?= json_encode($renderer->t('public.links.copied'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  document.querySelectorAll('[data-block-id="<?= (int)$blockId ?>"] .m-badge--copy').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      const value = btn.dataset.copy || '';
      try {
        await navigator.clipboard.writeText(value);
        const icon = btn.querySelector('.m-badge__icon');
        btn.classList.add('is-copied');
        if (icon) icon.setAttribute('icon', 'lucide:check');
        btn.setAttribute('aria-label', liveMsg);
        setTimeout(() => {
          btn.classList.remove('is-copied');
          if (icon) icon.setAttribute('icon', 'lucide:copy');
        }, 1600);
      } catch {}
    });
  });
})();
</script>
<?php endif; ?>
