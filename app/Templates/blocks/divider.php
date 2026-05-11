<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 *
 * Separator styles: the `.m-divider` container has `data-style` which the CSS
 * uses to drive the representation. For the 4 decorative styles (tessera,
 * dots, chevrons, floral, stars, wave) the template emits the required
 * child nodes; for the 5 line-styles + space the container alone is enough
 * (CSS-only).
 */
$style = $data['style'] ?? 'tessera';
?>
<div class="m-divider" data-style="<?= $renderer->escape($style) ?>">
<?php if ($style === 'tessera' || $style === 'dots'): ?>
  <span></span><span></span><span></span>
<?php elseif ($style === 'chevrons'): ?>
  <iconify-icon icon="lucide:chevrons-right" width="16"></iconify-icon>
  <iconify-icon icon="lucide:chevrons-right" width="16"></iconify-icon>
  <iconify-icon icon="lucide:chevrons-right" width="16"></iconify-icon>
<?php elseif ($style === 'floral'): ?>
  <iconify-icon icon="lucide:flower-2" width="14"></iconify-icon>
  <iconify-icon icon="lucide:flower" width="20"></iconify-icon>
  <iconify-icon icon="lucide:flower-2" width="14"></iconify-icon>
<?php elseif ($style === 'stars'): ?>
  <iconify-icon icon="lucide:star" width="12"></iconify-icon>
  <iconify-icon icon="lucide:star" width="16"></iconify-icon>
  <iconify-icon icon="lucide:star" width="12"></iconify-icon>
<?php elseif ($style === 'wave'): ?>
  <svg viewBox="0 0 200 14" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" preserveAspectRatio="xMidYMid meet">
    <path d="M0 7 Q 12.5 0, 25 7 T 50 7 T 75 7 T 100 7 T 125 7 T 150 7 T 175 7 T 200 7" fill="none" stroke="currentColor" stroke-width="1.5"/>
  </svg>
<?php endif; ?>
</div>
