<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 */
$items = array_values(array_filter(
    $data['items'] ?? [],
    static fn($it) => !empty($it['question'])
));
if (!$items) return;
$title = trim((string)($data['title'] ?? ''));
?>
<?php if ($title !== ''): ?><h2 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h2><?php endif; ?>
<div class="m-faq" itemscope itemtype="https://schema.org/FAQPage">
  <?php foreach ($items as $it):
      $q = trim((string)($it['question'] ?? ''));
      $a = trim((string)($it['answer'] ?? ''));
      if ($q === '') continue;
  ?>
    <details class="m-faq__item" itemprop="mainEntity" itemscope itemtype="https://schema.org/Question">
      <summary class="m-faq__q">
        <span itemprop="name"><?= $renderer->escape($q) ?></span>
        <svg class="m-faq__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
      </summary>
      <?php if ($a !== ''): ?>
        <div class="m-faq__a" itemprop="acceptedAnswer" itemscope itemtype="https://schema.org/Answer">
          <div itemprop="text"><?= $renderer->markdown($a) ?></div>
        </div>
      <?php endif; ?>
    </details>
  <?php endforeach; ?>
</div>
