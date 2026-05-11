<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 */
$items = $data['items'] ?? [];
$title = $data['title'] ?? '';
$subtitle = $data['subtitle'] ?? '';
$cols = (string)($data['columns'] ?? '2');
$ctaLabel = (string)($data['cta_label'] ?? $renderer->t('public.products.default_cta'));
?>
<div class="m-products">
  <?php if ($title || $subtitle): ?>
  <header class="m-products__head">
    <?php if ($title): ?><h2 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h2><?php endif; ?>
    <?php if ($subtitle): ?><p class="m-muted m-block__subtitle"><?= $renderer->escape($subtitle) ?></p><?php endif; ?>
  </header>
  <?php endif; ?>
  <div class="m-products__grid" data-cols="<?= $renderer->escape($cols) ?>">
    <?php foreach ($items as $p):
        $name = (string)($p['name'] ?? '');
        if ($name === '') continue;
        $url = $renderer->safeUrl((string)($p['url'] ?? ''), '');
        $img = (string)($p['image'] ?? '');
        $desc = (string)($p['description'] ?? '');
        $price = (string)($p['price'] ?? '');
        $code = (string)($p['discount_code'] ?? '');
        $codeLabel = (string)($p['discount_label'] ?? '');
    ?>
    <article class="m-product">
      <?php if ($img): ?>
        <div class="m-product__image-wrap">
          <img class="m-product__image" src="<?= $renderer->escape($img) ?>" alt="<?= $renderer->escape($name) ?>" loading="lazy">
          <?php if ($code !== ''): ?>
            <span class="m-product__discount">
              <?php if ($codeLabel): ?><strong><?= $renderer->escape($codeLabel) ?></strong><?php endif; ?>
            </span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="m-product__body">
        <h3 class="m-product__name"><?= $renderer->escape($name) ?></h3>
        <?php if ($desc): ?><p class="m-product__desc"><?= $renderer->escape($desc) ?></p><?php endif; ?>

        <?php if ($code !== ''): ?>
          <!-- Code pill + "copy" icon as an outer sibling (no bg).
               The whole wrap is clickable (it IS the button). On click the
               JS swaps the icon from copy → check and applies `is-copied`. -->
          <button type="button" class="m-product__code-wrap" data-code="<?= $renderer->escape($code) ?>" title="<?= $renderer->escape($renderer->t('public.products.copy_code')) ?>">
            <span class="m-product__code">
              <iconify-icon icon="lucide:ticket" width="16"></iconify-icon>
              <span class="m-product__code-value"><?= $renderer->escape($code) ?></span>
            </span>
            <span class="m-product__code-action" aria-hidden="true">
              <iconify-icon icon="lucide:copy" width="14"></iconify-icon>
            </span>
          </button>
        <?php endif; ?>

        <div class="m-product__footer">
          <?php if ($price): ?><span class="m-product__price"><?= $renderer->escape($price) ?></span><?php endif; ?>
          <?php if ($url !== ''): ?>
            <a class="m-product__cta" href="<?= $renderer->escape($url) ?>" target="_blank" rel="noopener nofollow sponsored">
              <?= $renderer->escape($ctaLabel) ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </a>
          <?php endif; ?>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</div>
<script>
// Copy discount codes on click. The copy → check swap is handled by
// mutating the iconify-icon `icon` attribute (leaner than two SVGs
// ping-ponging visibility).
(() => {
  document.querySelectorAll('[data-block-id="<?= (int)$blockId ?>"] .m-product__code-wrap').forEach(btn => {
    btn.addEventListener('click', async () => {
      const code = btn.dataset.code || '';
      try {
        await navigator.clipboard.writeText(code);
        const icon = btn.querySelector('.m-product__code-action iconify-icon');
        btn.classList.add('is-copied');
        if (icon) icon.setAttribute('icon', 'lucide:check');
        setTimeout(() => {
          btn.classList.remove('is-copied');
          if (icon) icon.setAttribute('icon', 'lucide:copy');
        }, 1600);
      } catch {}
    });
  });
})();
</script>