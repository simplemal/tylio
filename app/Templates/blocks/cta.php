<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 */
$title = trim((string)($data['title'] ?? ''));
$subtitle = trim((string)($data['subtitle'] ?? ''));
$btnLabel = trim((string)($data['button_label'] ?? ''));
$btnUrl = $renderer->safeUrl((string)($data['button_url'] ?? ''), '');
$icon = (string)($data['icon'] ?? '');
$style = (string)($data['style'] ?? 'gradient');
if ($title === '' && $btnLabel === '') return;
$external = (bool)preg_match('#^https?://#i', $btnUrl);
?>
<div class="m-cta m-cta--<?= $renderer->escape($style) ?>">
  <?php if ($title !== ''): ?>
    <h2 class="m-cta__title"><?= $renderer->escape($title) ?></h2>
  <?php endif; ?>
  <?php if ($subtitle !== ''): ?>
    <p class="m-cta__sub"><?= $renderer->escape($subtitle) ?></p>
  <?php endif; ?>
  <?php if ($btnLabel !== '' && $btnUrl !== ''): ?>
    <a class="m-cta__btn"
       href="<?= $renderer->escape($btnUrl) ?>"
       <?= $external ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
      <?php if ($icon !== ''): ?>
        <iconify-icon icon="<?= $renderer->escape($icon) ?>" width="18" height="18"></iconify-icon>
      <?php endif; ?>
      <span><?= $renderer->escape($btnLabel) ?></span>
      <svg class="m-cta__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
    </a>
  <?php elseif ($btnLabel !== ''): ?>
    <span class="m-cta__btn m-cta__btn--disabled" aria-disabled="true"><?= $renderer->escape($btnLabel) ?></span>
  <?php endif; ?>
</div>
