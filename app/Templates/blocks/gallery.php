<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 */
$items = $data['items'] ?? [];
$title = $data['title'] ?? '';
$layout = $data['layout'] ?? 'mosaic';
?>
<?php if ($title): ?><h2 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h2><?php endif; ?>
<div class="m-gallery" data-layout="<?= $renderer->escape($layout) ?>">
<?php foreach ($items as $it):
    $img = (string)($it['image'] ?? '');
    if ($img === '') continue;
    $alt = (string)($it['alt'] ?? '');
    $cap = (string)($it['caption'] ?? '');
    $link = $renderer->safeUrl((string)($it['link'] ?? ''), '');
?>
  <figure class="m-gallery__item">
    <?php if ($link !== ''): ?>
      <a href="<?= $renderer->escape($link) ?>" target="_blank" rel="noopener">
        <img src="<?= $renderer->escape($img) ?>" alt="<?= $renderer->escape($alt) ?>" loading="lazy" decoding="async">
      </a>
    <?php else: ?>
      <a href="<?= $renderer->escape($img) ?>" data-lightbox="<?= $renderer->escape($img) ?>" data-alt="<?= $renderer->escape($alt) ?>">
        <img src="<?= $renderer->escape($img) ?>" alt="<?= $renderer->escape($alt) ?>" loading="lazy" decoding="async">
      </a>
    <?php endif; ?>
    <?php if ($cap): ?><figcaption class="m-gallery__caption"><?= $renderer->escape($cap) ?></figcaption><?php endif; ?>
  </figure>
<?php endforeach; ?>
</div>
