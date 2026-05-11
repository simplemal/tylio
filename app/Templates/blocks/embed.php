<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 */
$url = (string)($data['url'] ?? '');
$provider = (string)($data['provider'] ?? 'iframe');
$aspect = (string)($data['aspect'] ?? '16:9');
$title = (string)($data['title'] ?? '');

$src = $renderer->embedUrl($provider, $url);
?>
<?php if ($title): ?><h3 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h3><?php endif; ?>
<?php if ($src): ?>
<div class="m-embed" data-aspect="<?= $renderer->escape($aspect) ?>">
  <iframe src="<?= $renderer->escape($src) ?>" loading="lazy" allow="accelerometer; encrypted-media; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
</div>
<?php else: ?>
<p class="m-muted"><?= $renderer->escape($renderer->t('public.embed.invalid_url')) ?></p>
<?php endif; ?>
