# Extending tylio — adding a new block type

> Audience: developers who cloned the OSS and want a new tile type
> (e.g. `pricing`, `testimonial`, `chart`, …) **without forking**.

A block in tylio is a self-contained "tile" rendered on the public
home and editable in the admin SPA. Tylio ships ~19 block types out
of the box; adding more is the single most common extension path.

## TL;DR — six files

| # | File                                                       | Purpose                                              |
|---|------------------------------------------------------------|------------------------------------------------------|
| 1 | `app/Services/BlockRegistry.php`                           | Schema (label, fields, defaults)                     |
| 2 | `app/Locales/en.php` + `it.php`                            | i18n strings under `blocks.<type>.*`                 |
| 3 | `app/Templates/blocks/<type>.php`                          | Server-side HTML output                              |
| 4 | `app/Services/Renderer.php` — `blockHasContent()`          | "Is the tile non-empty?" predicate                   |
| 5 | `admin-src/src/types.ts`                                   | TS types: `BlockKind`, `<Type>Data`, `Block` union   |
| 6 | `admin-src/src/components/BlockPreview.vue`                | Optional: per-type preview in the admin Dashboard    |

> Steps 1–4 are **required** (without them the tile can't be created,
> rendered, or persisted). Steps 5–6 are **strongly recommended**:
> without them the admin SPA falls back to a generic preview and the
> TypeScript compiler narrows the block's `data` to `unknown`.

The admin form-editor is 100% data-driven (`Field.vue`), so **no Vue
component is needed** to make the block editable — the registry
schema is enough. The SPA fetches `GET /api/types` at runtime and
renders fields from there.

---

## A complete example: a `pricing` tile

Throughout this guide we'll add a hypothetical `pricing` tile that
shows a list of plans (label, price, features, CTA URL). Replace
`pricing` with your own type name in every snippet.

### 1. Register the schema

Open `app/Services/BlockRegistry.php` and add a new entry to the
returned array in `definitions()`. The schema is pure data — no
imperative code, no per-type class:

```php
'pricing' => [
    'id' => 'pricing',
    'label' => 'blocks.pricing.label',
    'icon' => 'lucide:tag',                       // any Iconify icon
    'category' => 'blocks.categories.action',
    'description' => 'blocks.pricing.description',
    'span' => 'full',                             // 'half' | 'full'
    'fields' => [
        ['key' => 'title', 'label' => 'blocks.pricing.fields.title.label', 'type' => 'text', 'default' => ''],
        ['key' => 'plans', 'label' => 'blocks.pricing.fields.plans.label', 'type' => 'repeat', 'default' => [],
            'of' => [
                ['key' => 'name',     'label' => 'blocks.pricing.fields.plans.of.name.label',     'type' => 'text'],
                ['key' => 'price',    'label' => 'blocks.pricing.fields.plans.of.price.label',    'type' => 'text'],
                ['key' => 'features', 'label' => 'blocks.pricing.fields.plans.of.features.label', 'type' => 'textarea'],
                ['key' => 'cta_url',  'label' => 'blocks.pricing.fields.plans.of.cta_url.label',  'type' => 'url'],
                ['key' => 'highlight','label' => 'blocks.pricing.fields.plans.of.highlight.label','type' => 'toggle', 'default' => false],
            ],
        ],
    ],
],
```

#### Available field types

`text`, `textarea`, `markdown`, `url`, `email`, `image`, `avatar`,
`color`, `number`, `toggle`, `select`, `repeat`, `icon`, `range`.

Each is rendered by `admin-src/src/components/Field.vue` — see that
file for the exact UX of each type.

#### Field options

| Key                  | Purpose                                                                    |
|----------------------|----------------------------------------------------------------------------|
| `default`            | Initial value when the block is created                                    |
| `placeholder`        | Hint shown inside the input                                                |
| `help`               | Helper text under the input                                                |
| `options`            | For `select`: `[['value' => 'x', 'label' => 'blocks…']]`                   |
| `of`                 | For `repeat`: sub-schema for each item                                     |
| `max`                | Cap on repeat items / number max / max length                              |
| `required`           | Visual hint in the admin (no server-side enforcement — see *Validation*)   |
| `autocomplete_from`  | `'siblings'` for `repeat`: autocompletes from values in other items        |

### 2. Add i18n strings

The registry is intentionally **brand-and-language neutral**: every
user-facing string is a translation key. Add the keys to both locale
files. Missing keys would render literally (`"blocks.pricing.label"`
shown in the admin), so this step is not optional.

`app/Locales/en.php`:

```php
'blocks.pricing.label' => 'Pricing',
'blocks.pricing.description' => 'A pricing table with plans, features and CTAs.',
'blocks.pricing.fields.title.label' => 'Section title',
'blocks.pricing.fields.plans.label' => 'Plans',
'blocks.pricing.fields.plans.of.name.label' => 'Name',
'blocks.pricing.fields.plans.of.price.label' => 'Price',
'blocks.pricing.fields.plans.of.features.label' => 'Features (one per line)',
'blocks.pricing.fields.plans.of.cta_url.label' => 'CTA link',
'blocks.pricing.fields.plans.of.highlight.label' => 'Featured plan',
```

`app/Locales/it.php`:

```php
'blocks.pricing.label' => 'Listino',
'blocks.pricing.description' => 'Una tabella prezzi con piani, feature e CTA.',
// …same keys, Italian translations…
```

Always update **both files together**: when a key is missing from
the active locale, `I18n::t()` returns the key string itself, which
is the same failure mode as forgetting to add it at all.

### 3. Server-side template

Create `app/Templates/blocks/pricing.php`. This is plain PHP (no
template engine) and is included by `Renderer::renderBlock()`. The
following variables are in scope:

- `$block['data']` — the tile's content (validated against the schema by the admin)
- `$theme` — full theme array (palette, fonts, …)
- `$this` — the `Renderer` instance, useful for `$this->md($text)` to convert Markdown

```php
<?php
/** @var array $block @var array $theme */
$d = $block['data'] ?? [];
$plans = is_array($d['plans'] ?? null) ? $d['plans'] : [];
?>
<section class="block-pricing">
    <?php if (!empty($d['title'])): ?>
        <h2 class="block-pricing__title"><?= htmlspecialchars((string)$d['title']) ?></h2>
    <?php endif; ?>
    <div class="block-pricing__grid">
        <?php foreach ($plans as $plan): ?>
            <article class="block-pricing__plan<?= !empty($plan['highlight']) ? ' is-highlight' : '' ?>">
                <h3><?= htmlspecialchars((string)($plan['name'] ?? '')) ?></h3>
                <p class="price"><?= htmlspecialchars((string)($plan['price'] ?? '')) ?></p>
                <ul><?php foreach (preg_split('/\r?\n/', (string)($plan['features'] ?? '')) as $line):
                    $line = trim($line);
                    if ($line === '') continue; ?>
                    <li><?= htmlspecialchars($line) ?></li>
                <?php endforeach; ?></ul>
                <?php if (!empty($plan['cta_url'])): ?>
                    <a class="block-pricing__cta" href="<?= htmlspecialchars((string)$plan['cta_url']) ?>">→</a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
```

**Always escape with `htmlspecialchars()`** — the registry does not
sanitize HTML, and an authenticated admin could otherwise inject
script tags. `Renderer::md($s)` returns sanitized Markdown HTML for
markdown fields.

If you need new CSS classes, add them to `app/Templates/public.css`.

### 4. "Has content" predicate

Public pages skip empty tiles. Add a case to
`Renderer::blockHasContent()`:

```php
case 'pricing':
    foreach (($data['plans'] ?? []) as $p) {
        if (!empty($p['name']) && !empty($p['price'])) return true;
    }
    return false;
```

> The `default: return true` branch covers "structural" blocks
> (`hero`, `divider`, `footer`) that should always render even when
> minimal. If you forget the `case` your block will follow that
> default — useful for structural tiles, **wrong** for content tiles
> (an empty `pricing` would publish empty plans).

### 5. TypeScript types (recommended)

Open `admin-src/src/types.ts` and:

1. add `'pricing'` to the `BlockKind` union,
2. declare the `PricingData` interface (and any nested item shape),
3. add the discriminator arm to the `Block` union,
4. add the entry to `BlockDataMap`.

```ts
export type BlockKind =
  | 'hero' | 'links' | /* … */ | 'pricing'

export interface PricingPlan {
  name?: string
  price?: string
  features?: string
  cta_url?: string
  highlight?: boolean
}
export interface PricingData {
  title?: string
  plans?: PricingPlan[]
}

export type Block =
  | /* …existing arms… */
  | (BaseBlock & { type: 'pricing'; data: PricingData })

export interface BlockDataMap {
  /* …existing entries… */
  pricing: PricingData
}
```

Without this, `tsc --noEmit` keeps passing (the unknown type falls
back to `unknown`), but consumers that use `isBlockOfType()` or
generics over `BlockDataMap` lose type safety on your tile.

### 6. Dashboard preview (recommended)

`admin-src/src/components/BlockPreview.vue` has a `v-if/v-else-if`
chain per block type. Without your branch the fallback renders the
generic description from the registry. To show a real preview, add a
branch (and update the locales with any new strings):

```vue
<div v-else-if="block.type === 'pricing'">
  <div v-if="block.data.title" class="bp-section-title">{{ block.data.title }}</div>
  <p v-if="!block.data.plans?.length" class="bp-empty">{{ t('blockPreview.empty.pricing') }}</p>
  <div v-else class="bp-pills">
    <span v-for="(p, i) in block.data.plans.slice(0, 3)" :key="i" class="bp-pill">
      <strong>{{ p.name || '—' }}</strong>
      <span class="opacity-70">{{ p.price }}</span>
    </span>
  </div>
</div>
```

### 7. Test it

```bash
composer check                                       # PHPStan + PHPUnit on the PHP layer
cd admin-src && npx vue-tsc --noEmit && npm run build && cd ..
# Then in the admin:
#   1. open "Add tile"
#   2. find your category, click your new tile
#   3. fill the fields, save
#   4. open the public page → the tile renders with your template
```

If anything fails to render, check `data/logs/php-error.log` — the
Renderer logs missing templates and PHP warnings there.

---

## Validation (optional but recommended)

The default `BlocksController::update()` accepts any JSON payload
because the admin already constrained the shape via the editor. This
is fine for an admin-only endpoint, but if you want server-side
type-checking (e.g. URL fields are real URLs, numbers are in range,
required fields are present), override `validateBlockData()` in a
subclass — see the
[platform overlay](https://github.com/simplemal/tylio-platform) for a
working example of the same pattern applied to settings.

## Cross-cutting extensions (beyond block types)

| Question                                       | Where to look                                                  |
|------------------------------------------------|----------------------------------------------------------------|
| Add a route                                    | `app/routes.php` — Slim 4 syntax, controllers are PHP-DI bound |
| Override a controller / service                | Non-`final` + `protected` deps: extend + rebind in DI          |
| Theme presets                                  | `admin-src/src/presets.ts`                                     |
| New locale (`fr`, `es`, …)                     | Add `app/Locales/<code>.php` + `admin-src/src/locales/<code>.json` |
| Custom migrations                              | `app/Database/migrations/NNNN_<slug>.{sql,php}`                |

For the platform overlay (multi-tenant SaaS), see the
[tylio-platform README](https://github.com/simplemal/tylio-platform).

## Common pitfalls

1. **Forgot i18n keys** → admin shows `"blocks.pricing.label"`
   literally. Fix: add the missing keys to `en.php` + `it.php`.
2. **Forgot `blockHasContent` case** → empty tiles render publicly
   (because they fall into `default: return true`).
3. **No `htmlspecialchars()` in template** → authenticated XSS.
   Always escape, even though only the admin can write the content.
4. **Out-of-sync types.ts** → SPA still works (because the editor is
   data-driven), but TypeScript narrows your tile's `data` to
   `unknown`. Annoying for downstream consumers and for future you.
5. **Skipped the dashboard preview** → the admin Dashboard shows a
   generic description card instead of a custom preview. Tile still
   works; it just looks lazy.

## Reference: existing blocks

The 19 shipping types (`hero`, `links`, `apps`, `bio`, `products`,
`quote`, `stats`, `cta`, `faq`, `timeline`, `social`, `gallery`,
`embed`, `youtube`, `podcast`, `contact`, `skills`, `divider`,
`footer`) are all implemented exactly as described above. They are
the primary reference when in doubt — particularly `links` (simplest
repeating tile), `quote` (richest content type), and `gallery`
(image-handling patterns).
