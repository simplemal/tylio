<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 */
$items = array_values(array_filter(
    $data['items'] ?? [],
    static fn($it) => !empty($it['title']) || !empty($it['date'])
));
if (!$items) return;
$title = trim((string)($data['title'] ?? ''));
?>
<?php if ($title !== ''): ?><h2 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h2><?php endif; ?>
<ol class="m-timeline">
  <?php foreach ($items as $it):
      $date = trim((string)($it['date'] ?? ''));
      $iTitle = trim((string)($it['title'] ?? ''));
      $desc = trim((string)($it['description'] ?? ''));
      $icon = (string)($it['icon'] ?? '');
      $highlight = !empty($it['highlight']);
  ?>
    <li class="m-timeline__item<?= $highlight ? ' m-timeline__item--hl' : '' ?>">
      <div class="m-timeline__marker" aria-hidden="true">
        <?php if ($icon !== ''): ?>
          <iconify-icon icon="<?= $renderer->escape($icon) ?>" width="14" height="14"></iconify-icon>
        <?php else: ?>
          <span class="m-timeline__dot"></span>
        <?php endif; ?>
      </div>
      <div class="m-timeline__body">
        <?php if ($date !== ''): ?>
          <time class="m-timeline__date"><?= $renderer->escape($date) ?></time>
        <?php endif; ?>
        <?php if ($iTitle !== ''): ?>
          <h3 class="m-timeline__title"><?= $renderer->escape($iTitle) ?></h3>
        <?php endif; ?>
        <?php if ($desc !== ''): ?>
          <p class="m-timeline__desc"><?= $renderer->escape($desc) ?></p>
        <?php endif; ?>
      </div>
    </li>
  <?php endforeach; ?>
</ol>
