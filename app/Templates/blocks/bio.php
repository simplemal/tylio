<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 */
?>
<?php if (!empty($data['title'])): ?><h2 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($data['title']) ?></h2><?php endif; ?>
<div class="m-md"><?= $renderer->markdown((string)($data['body'] ?? '')) ?></div>
