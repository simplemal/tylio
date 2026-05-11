<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 */
$items = $data['items'] ?? [];
$title = $data['title'] ?? '';
?>
<?php if ($title): ?><h3 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h3><?php endif; ?>
<ul class="m-links">
<?php foreach ($items as $it):
    $rawUrl = (string)($it['url'] ?? '');
    $url = $renderer->safeUrl($rawUrl, '');
    if ($url === '') continue;
    $label = (string)($it['label'] ?? $url);
    $external = (bool)preg_match('#^https?://#i', $url);
?>
  <li>
    <a class="m-link" href="<?= $renderer->escape($url) ?>"<?= $external ? ' rel="noopener noreferrer" target="_blank"' : '' ?>>
      <span class="m-link__icon" aria-hidden="true">
        <?php if (!empty($it['icon'])): ?>
          <iconify-icon icon="<?= $renderer->escape($it['icon']) ?>" width="20" height="20"></iconify-icon>
        <?php else: ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
        <?php endif; ?>
      </span>
      <span class="m-link__body">
        <span class="m-link__label">
          <span class="m-link__title"><?= $renderer->escape($label) ?></span>
          <?php if (!empty($it['badge'])): ?><span class="m-badge"><?= $renderer->escape($it['badge']) ?></span><?php endif; ?>
        </span>
        <?php if (!empty($it['description'])): ?>
          <span class="m-link__desc"><?= $renderer->escape($it['description']) ?></span>
        <?php endif; ?>
      </span>
      <span class="m-link__chev" aria-hidden="true">›</span>
    </a>
  </li>
<?php endforeach; ?>
</ul>
