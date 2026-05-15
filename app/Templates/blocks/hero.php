<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 * @var int   $blockId
 */
$subtitle = (string)($data['subtitle'] ?? '');
$titleText = trim((string)($data['title'] ?? ''));
$titleImage = trim((string)($data['title_image'] ?? ''));
// `align` mirrors the social block's option:
//   - 'left'   (default) → desktop split: large avatar left + text right
//   - 'center'           → desktop keeps the mobile vertical-stack layout
// Mobile (<780px) is always centered regardless.
$align = ($data['align'] ?? 'left') === 'center' ? 'center' : 'left';
?>
<div class="m-hero<?= $align === 'center' ? ' m-hero--center' : '' ?>">
  <?php if (!empty($data['avatar'])): ?>
    <img class="m-hero__avatar" src="<?= $renderer->escape($data['avatar']) ?>" alt="" loading="eager" decoding="async">
  <?php endif; ?>
  <div class="m-hero__texts">
    <?php /* When the user uploads a "graphic title" it replaces the text
            with the image; the Title field text becomes the image's
            alt/title so screen readers and crawlers still get the
            textual name string. The h1 stays (SEO / semantic heading
            structure), but inside there is only the <img>. */ ?>
    <h1 class="m-hero__title<?= $titleImage !== '' ? ' m-hero__title--image' : '' ?>">
      <?php if ($titleImage !== ''): ?>
        <img
          src="<?= $renderer->escape($titleImage) ?>"
          alt="<?= $renderer->escape($titleText) ?>"
          title="<?= $renderer->escape($titleText) ?>"
          loading="eager"
          decoding="async"
        >
      <?php else: ?>
        <?= $renderer->escape($titleText) ?>
      <?php endif; ?>
    </h1>
    <?php if (trim($subtitle) !== ''): ?>
      <div class="m-hero__subtitle m-md"><?= $renderer->markdown($subtitle) ?></div>
    <?php endif; ?>
  </div>
</div>
