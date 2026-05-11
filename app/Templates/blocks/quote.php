<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 * @var array $style    block-level style (title_size, span, etc.)
 */
$title = trim((string)($data['title'] ?? ''));
$text = trim((string)($data['text'] ?? ''));
if ($text === '') return;
$author = trim((string)($data['author'] ?? ''));
$role = trim((string)($data['role'] ?? ''));
$avatar = trim((string)($data['avatar'] ?? ''));
// Visual variant (card/minimal/highlight) — distinct from the block's $style.
$variant = (string)($data['style'] ?? 'card');
// Whitelist validation to avoid injection in the data-attrs.
$textSize = (string)($data['text_size'] ?? 'md');
if (!in_array($textSize, ['sm', 'md', 'lg'], true)) $textSize = 'md';
$lineHeight = (string)($data['line_height'] ?? 'normal');
if (!in_array($lineHeight, ['compact', 'normal', 'relaxed'], true)) $lineHeight = 'normal';
$hasAuthor = ($author !== '' || $role !== '' || $avatar !== '');
?>
<?php if ($title !== ''): ?>
  <h2 class="<?= $renderer->titleClass($style) ?> m-quote__title"><?= $renderer->escape($title) ?></h2>
<?php endif; ?>
<figure
  class="m-quote m-quote--<?= $renderer->escape($variant) ?><?= $hasAuthor ? ' m-quote--has-author' : '' ?>"
  data-text-size="<?= $textSize ?>"
  data-line-height="<?= $lineHeight ?>"
>
  <svg class="m-quote__mark" viewBox="0 0 32 32" aria-hidden="true">
    <path d="M9 20q0-5 3-9t8-5l1 3q-4 1-6 4t-2 6h2v6H7v-5zm14 0q0-5 3-9t8-5l1 3q-4 1-6 4t-2 6h2v6h-8v-5z" fill="currentColor" opacity=".5"/>
  </svg>
  <blockquote class="m-quote__text"><?= $renderer->escape($text) ?></blockquote>
  <?php if ($hasAuthor): ?>
    <figcaption class="m-quote__author">
      <?php if ($avatar !== ''): ?>
        <img class="m-quote__avatar" src="<?= $renderer->escape($renderer->safeUrl($avatar, '')) ?>" alt="" loading="lazy" width="32" height="32">
      <?php endif; ?>
      <span class="m-quote__author-text">
        <?php if ($author !== ''): ?><cite class="m-quote__name"><?= $renderer->escape($author) ?></cite><?php endif; ?>
        <?php if ($role !== ''): ?><span class="m-quote__role"><?= $renderer->escape($role) ?></span><?php endif; ?>
      </span>
    </figcaption>
  <?php endif; ?>
</figure>
