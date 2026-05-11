<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 */
$items = array_values(array_filter(
    $data['items'] ?? [],
    static fn($it) => !empty($it['value']) || !empty($it['label'])
));
if (!$items) return;
$title = trim((string)($data['title'] ?? ''));
$columns = (string)($data['columns'] ?? '3');
if (!in_array($columns, ['2', '3', '4'], true)) $columns = '3';
?>
<?php if ($title !== ''): ?><h2 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h2><?php endif; ?>
<div class="m-stats" data-cols="<?= $renderer->escape($columns) ?>">
  <?php foreach ($items as $it):
      $value = (string)($it['value'] ?? '');
      $label = (string)($it['label'] ?? '');
      $icon = (string)($it['icon'] ?? '');
  ?>
    <div class="m-stats__item">
      <?php if ($icon !== ''): ?>
        <iconify-icon class="m-stats__icon" icon="<?= $renderer->escape($icon) ?>" width="22" height="22"></iconify-icon>
      <?php endif; ?>
      <?php if ($value !== ''): ?>
        <div class="m-stats__value"><?= $renderer->escape($value) ?></div>
      <?php endif; ?>
      <?php if ($label !== ''): ?>
        <div class="m-stats__label"><?= $renderer->escape($label) ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
