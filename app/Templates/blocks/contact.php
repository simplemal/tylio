<?php
/**
 * @var \Tylio\Services\Renderer $renderer
 * @var array $data
 * @var int $blockId
 */
$title = $data['title'] ?? $renderer->t('public.contact.default_title');
$subtitle = $data['subtitle'] ?? '';
$success = $data['success_message'] ?? $renderer->t('public.contact.default_success');
$fields = $data['fields'] ?? [];
$formId = 'm-form-' . $blockId;
$challenge = $renderer->captchaChallenge();
?>
<h3 class="<?= $renderer->titleClass($style) ?>"><?= $renderer->escape($title) ?></h3>
<?php if ($subtitle): ?><p class="m-muted"><?= $renderer->escape($subtitle) ?></p><?php endif; ?>
<?php
// Localized strings consumed by the inline JS below. JSON-encoded for
// safe embedding in a `data-` attribute; the JS reads them once.
$i18nStrings = [
    'captcha_working' => $renderer->t('public.contact.captcha_working'),
    'captcha_working_sec' => $renderer->t('public.contact.captcha_working_sec'),
    'captcha_ok' => $renderer->t('public.contact.captcha_ok'),
    'captcha_failed' => $renderer->t('public.contact.captcha_failed'),
    'captcha_not_ready' => $renderer->t('public.contact.captcha_not_ready'),
    'error_generic' => $renderer->t('public.contact.error_generic'),
    'error_network' => $renderer->t('public.contact.error_network'),
];
?>
<form
  class="m-form"
  id="<?= $formId ?>"
  data-block-id="<?= (int)$blockId ?>"
  data-success="<?= $renderer->escape($success) ?>"
  data-pow-token="<?= $renderer->escape($challenge['token']) ?>"
  data-pow-difficulty="<?= (int)$challenge['difficulty'] ?>"
  data-i18n="<?= $renderer->escape(json_encode($i18nStrings, JSON_UNESCAPED_UNICODE)) ?>"
>
  <input type="text" name="nickname" class="m-honey" tabindex="-1" autocomplete="off" aria-hidden="true">
  <input type="hidden" name="_tok" value="<?= $renderer->escape($challenge['token']) ?>">
  <input type="hidden" name="_nonce" value="">
  <input type="hidden" name="_h" value="">
  <?php foreach ($fields as $f):
      $key = (string)($f['key'] ?? '');
      if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) continue;
      if ($key === 'nickname' || $key[0] === '_') continue;
      $type = (string)($f['type'] ?? 'text');
      $label = (string)($f['label'] ?? $key);
      $req = !empty($f['required']);
  ?>
    <label>
      <span><?= $renderer->escape($label) ?><?php if ($req): ?> *<?php endif; ?></span>
      <?php if ($type === 'textarea'): ?>
        <textarea name="<?= $renderer->escape($key) ?>"<?= $req ? ' required' : '' ?>></textarea>
      <?php else: ?>
        <input type="<?= $renderer->escape($type) ?>" name="<?= $renderer->escape($key) ?>"<?= $req ? ' required' : '' ?>>
      <?php endif; ?>
    </label>
  <?php endforeach; ?>
  <div class="m-form__row">
    <!-- Hidden until the PoW starts (on first focus/click) so it doesn't
         distract the user before it's relevant. -->
    <div class="m-form__pow" data-state="idle" aria-live="polite" hidden>
      <span class="m-form__pow-spinner" aria-hidden="true"></span>
      <span class="m-form__pow-label"><?= $renderer->escape($renderer->t('public.contact.captcha_working')) ?></span>
    </div>
    <button type="submit" disabled><?= $renderer->escape($renderer->t('public.contact.send')) ?></button>
  </div>
  <p class="m-form__msg" aria-live="polite"></p>
</form>
<script>
(() => {
  const form = document.getElementById('<?= $formId ?>');
  if (!form) return;
  const btn = form.querySelector('button[type="submit"]');
  const msg = form.querySelector('.m-form__msg');
  const pow = form.querySelector('.m-form__pow');
  const powLabel = form.querySelector('.m-form__pow-label');
  const nonceInput = form.querySelector('input[name="_nonce"]');
  const human = form.querySelector('input[name="_h"]');
  // Localized labels injected server-side as a JSON blob in `data-i18n`.
  // Single-shot read; defaults guard against missing keys.
  const I18N = (() => {
    try { return JSON.parse(form.dataset.i18n || '{}'); } catch (_) { return {}; }
  })();

  // Invisible layer on top of the PoW: _h=1 flag set on the first
  // human interaction with the form. Headless bots that simulate POST only
  // without real events leave _h empty.
  const markHuman = () => { if (human && !human.value) human.value = '1'; };
  ['focusin', 'pointerdown', 'keydown', 'touchstart'].forEach((ev) =>
    form.addEventListener(ev, markHuman, { once: false, passive: true }),
  );

  // Proof-of-work: search for a nonce such that SHA-256(token + ":" + nonce)
  // has `difficulty` leading zero bits. Async loop using subtle.digest so it
  // doesn't block rendering; the spinner shows progress (yields every 500
  // attempts → typically 300ms-1.5s on a modern client).
  async function solvePow() {
    const token = form.dataset.powToken;
    const difficulty = parseInt(form.dataset.powDifficulty, 10) || 17;
    if (!token) return null;
    const enc = new TextEncoder();
    const t0 = performance.now();
    let nonce = 0;
    while (nonce < 5000000) {
      for (let batch = 0; batch < 500; batch++, nonce++) {
        const data = enc.encode(token + ':' + nonce);
        const buf = await crypto.subtle.digest('SHA-256', data);
        if (leadingZeros(new Uint8Array(buf)) >= difficulty) return String(nonce);
      }
      // Yield to the browser — refreshes the approximate spinner.
      if (powLabel && nonce % 5000 === 0) {
        const sec = ((performance.now() - t0) / 1000).toFixed(1);
        const tpl = I18N.captcha_working_sec || 'Verifying you are human… ({sec}s)';
        powLabel.textContent = tpl.replace('{sec}', sec);
      }
      await new Promise((r) => setTimeout(r, 0));
    }
    throw new Error('pow_timeout');
  }
  function leadingZeros(arr) {
    let count = 0;
    for (const byte of arr) {
      if (byte === 0) { count += 8; continue; }
      for (let i = 7; i >= 0; i--) {
        if ((byte >> i) & 1) return count;
        count++;
      }
      return count;
    }
    return count;
  }

  // Start PoW at the first form "touch" (focusin/pointerdown) — not at
  // page-load, so we don't waste CPU on users who never interact.
  // The user doesn't notice: PoW typically finishes while they type.
  let powStarted = false;
  function startPow() {
    if (powStarted) return;
    powStarted = true;
    pow.hidden = false;
    pow.dataset.state = 'working';
    solvePow().then((nonce) => {
      if (nonce !== null) nonceInput.value = nonce;
      pow.dataset.state = 'ok';
      powLabel.textContent = I18N.captcha_ok || '✓ Verified';
      btn.disabled = false;
    }).catch(() => {
      pow.dataset.state = 'error';
      powLabel.textContent = I18N.captcha_failed || 'Verification failed. Reload the page.';
    });
  }
  ['focusin', 'pointerdown', 'touchstart'].forEach((ev) =>
    form.addEventListener(ev, startPow, { once: true, passive: true }),
  );

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    markHuman();
    if (!nonceInput.value) {
      msg.textContent = I18N.captcha_not_ready || 'Anti-bot check not finished, wait a moment…';
      msg.setAttribute('data-state', 'error');
      return;
    }
    btn.disabled = true; msg.textContent = '';
    const data = Object.fromEntries(new FormData(form));
    try {
      const r = await fetch('/submit/' + form.dataset.blockId, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(data),
      });
      const j = await r.json().catch(() => ({}));
      if (r.ok && j.ok) {
        form.reset();
        msg.textContent = form.dataset.success;
        msg.removeAttribute('data-state');
      } else {
        msg.textContent = I18N.error_generic || 'Something went wrong. Please try again.';
        msg.setAttribute('data-state', 'error');
      }
    } catch (err) {
      msg.textContent = I18N.error_network || 'Network error. Please try again.';
      msg.setAttribute('data-state', 'error');
    } finally {
      btn.disabled = !nonceInput.value;
    }
  });
})();
</script>
