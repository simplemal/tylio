<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 * @var array $style
 */
$title = trim((string)($data['title'] ?? ''));
$subtitle = trim((string)($data['subtitle'] ?? ''));
$items = is_array($data['items'] ?? null) ? $data['items'] : [];

// Group by category preserving the categories' order of appearance.
// Skills without a category end up in a special '' (empty) group, which
// we render WITHOUT a heading → it behaves like a flat list.
$groups = [];
foreach ($items as $it) {
    if (!is_array($it)) continue;
    $name = trim((string)($it['name'] ?? ''));
    if ($name === '') continue;
    $cat = trim((string)($it['category'] ?? ''));
    $groups[$cat] ??= [];
    $groups[$cat][] = [
        'name' => $name,
        'level' => trim((string)($it['level'] ?? '')),
        'icon' => trim((string)($it['icon'] ?? '')),
    ];
}
if (empty($groups)) return;

// Order: groups with a category in their appearance order, then the
// "uncategorized" skills at the end (so organized skills get visual
// priority and free-form skills act as an appendix).
$ordered = [];
foreach ($groups as $cat => $list) {
    if ($cat !== '') $ordered[$cat] = $list;
}
if (isset($groups[''])) $ordered[''] = $groups[''];
?>
<?php if ($title !== ''): ?><h2 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h2><?php endif; ?>
<?php if ($subtitle !== ''): ?><p class="m-muted m-skills__subtitle"><?= $renderer->escape($subtitle) ?></p><?php endif; ?>
<div class="m-skills">
<?php foreach ($ordered as $cat => $list): ?>
  <div class="m-skills__group">
    <?php if ($cat !== ''): ?>
      <h3 class="m-skills__cat"><?= $renderer->escape($cat) ?></h3>
    <?php endif; ?>
    <ul class="m-skills__list">
      <?php foreach ($list as $sk): ?>
        <li class="m-skill">
          <?php if ($sk['icon'] !== ''): ?>
            <iconify-icon class="m-skill__icon" icon="<?= $renderer->escape($sk['icon']) ?>" width="14"></iconify-icon>
          <?php endif; ?>
          <span class="m-skill__name"><?= $renderer->escape($sk['name']) ?></span>
          <?php if ($sk['level'] !== ''): ?>
            <span class="m-skill__level"><?= $renderer->escape($sk['level']) ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endforeach; ?>
</div>
