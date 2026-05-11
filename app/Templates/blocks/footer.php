<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 */
$text = (string)($data['text'] ?? '');
$showPowered = !empty($data['show_powered_by']);
$links = is_array($data['links'] ?? null) ? $data['links'] : [];
?>
<?php /* Layout: two groups (left = copy + powered, right = menu links). On
        desktop (≥780px) they sit side-by-side via justify-content:space-between
        inherited from the .m-tile--footer container; on mobile they fall back
        to a centered column. "|" separator between menu items via CSS pseudo. */ ?>
<div class="m-footer__left">
  <?php if ($text !== ''): ?>
    <div class="m-footer__copy"><?= $renderer->escape($text) ?></div>
  <?php endif; ?>
  <?php if ($showPowered): ?>
    <div class="m-poweredby">powered by <a href="https://tylio.app" target="_blank" rel="noopener">tylio</a></div>
  <?php endif; ?>
</div>
<?php if (!empty($links)): ?>
<nav class="m-footer__links">
  <?php foreach ($links as $l):
      $url = $renderer->safeUrl((string)($l['url'] ?? ''), '');
      if ($url === '') continue;
  ?>
  <a href="<?= $renderer->escape($url) ?>"<?= str_starts_with($url, 'http') ? ' target="_blank" rel="noopener"' : '' ?>><?= $renderer->escape($l['label'] ?? $url) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
