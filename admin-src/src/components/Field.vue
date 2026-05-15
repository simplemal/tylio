<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import draggable from 'vuedraggable'
import type { FieldDef } from '../types'
import MediaPicker from './MediaPicker.vue'
import AvatarPicker from './AvatarPicker.vue'
import IconPicker from './IconPicker.vue'

const { t } = useI18n()
const iconPickerOpen = ref(false)

// Field is inherently polymorphic: the value type depends on `def.type`
// (text=string, number=number, toggle=boolean, repeat=array of records,
// etc.). We use `unknown` and narrow casts instead of letting `any`
// leak everywhere.
type RepeatItem = Record<string, unknown>

const props = withDefaults(
  defineProps<{
    def: FieldDef
    modelValue: unknown
    /**
     * Values already used by other items for the same key, offered as
     * autocomplete via an HTML5 <datalist>. Populated by the parent
     * Field when handling a repeat and the sub-field has
     * `autocomplete_from: 'siblings'`.
     */
    suggestions?: string[]
  }>(),
  { suggestions: () => [] },
)
const emit = defineEmits<{ 'update:modelValue': [unknown] }>()

// Stable id for this Field's <datalist>: generated once at mount and
// kept for the component's lifetime. Only emitted in the DOM when there
// are actual suggestions.
const datalistId = ref(`dl-${props.def.key}-${Math.floor(Math.random() * 1e9)}`)

const value = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

// Typed computed properties for v-model bindings that require a
// specific shape. They all write back into `value` (which is `unknown`),
// so the runtime model stays the same.
const stringValue = computed({
  get: () => (typeof value.value === 'string' ? value.value : ''),
  set: (v: string) => {
    value.value = v
  },
})
// Variant for selects: if the current value is empty and `def` has a
// string `default`, show that default. Needed for blocks created BEFORE
// the field was added to the registry — their saved JSON has no such
// key. On the public side the template uses `?? default`, but a Vue
// <select> with v-model='' renders an empty row because no option
// matches. The value isn't pushed back to the server until the user
// interacts — so no spurious dirty state on edit-open.
const selectValue = computed({
  get: () => {
    const raw = typeof value.value === 'string' ? value.value : ''
    if (raw !== '') return raw
    return typeof props.def.default === 'string' ? props.def.default : ''
  },
  set: (v: string) => {
    value.value = v
  },
})
const booleanValue = computed({
  get: () => Boolean(value.value),
  set: (v: boolean) => {
    value.value = v
  },
})

function asArray(): RepeatItem[] {
  return Array.isArray(value.value) ? (value.value as RepeatItem[]) : []
}

function setItem(idx: number, key: string, v: unknown) {
  const arr = [...asArray()]
  arr[idx] = { ...(arr[idx] || {}), [key]: v }
  value.value = arr
}

// Recursively walks a field tree and collects default values into a flat
// object. inline_group fields don't introduce a data path of their own —
// their `of` children write directly to the parent item — so we descend
// into them. Used by `addItem` so newly-created repeat rows already
// reflect the schema defaults (incl. fields inside an inline_group).
function collectDefaults(fields: FieldDef[], out: RepeatItem) {
  for (const f of fields) {
    if (f.type === 'inline_group') {
      collectDefaults(f.of || [], out)
      continue
    }
    out[f.key] = f.default ?? (f.type === 'toggle' ? false : f.type === 'repeat' ? [] : '')
  }
}

function addItem() {
  const arr = [...asArray()]
  const empty: RepeatItem = {}
  collectDefaults(props.def.of || [], empty)
  arr.push(empty)
  value.value = arr
}

/**
 * `show_when` predicate: returns true if this sub-field is visible for
 * the given item. Evaluated only inside repeat rows (no `show_when` at
 * top-level). Missing keys count as not-equal, except when the field has
 * a `default` value matching `equals` and the item never explicitly set
 * the key — handled implicitly by the value comparison below (`undefined
 * === 'custom'` → false → field hidden, which is what we want).
 */
function shouldShow(sub: FieldDef, item: RepeatItem): boolean {
  if (!sub.show_when) return true
  const observed = item?.[sub.show_when.key]
  return observed === sub.show_when.equals
}

function removeItem(idx: number) {
  const arr = [...asArray()]
  arr.splice(idx, 1)
  value.value = arr
}

function moveItems(arr: RepeatItem[]) {
  value.value = arr
}

/**
 * Collects values already used for `key` across the other items of the
 * repeat — used by Field.vue (recursive) when a sub-field declares
 * `autocomplete_from: 'siblings'`. Returns trimmed, unique, locale-
 * sorted strings.
 */
function siblingValues(key: string): string[] {
  const items = asArray()
  const seen = new Set<string>()
  for (const it of items) {
    const v = it?.[key]
    if (typeof v === 'string') {
      const t = v.trim()
      if (t) seen.add(t)
    }
  }
  return [...seen].sort((a, b) => a.localeCompare(b, 'it'))
}
</script>

<template>
  <div class="field">
    <label v-if="def.type !== 'toggle'">{{ def.label }}</label>

    <!-- TEXT / URL / EMAIL / NUMBER. Datalist only for text when there are
         sibling suggestions (e.g. skill categories already entered). -->
    <template v-if="['text', 'url', 'email', 'number'].includes(def.type)">
      <input
        v-model="stringValue"
        :type="
          def.type === 'number'
            ? 'number'
            : def.type === 'url'
              ? 'url'
              : def.type === 'email'
                ? 'email'
                : 'text'
        "
        :placeholder="def.placeholder || ''"
        :list="def.type === 'text' && suggestions.length ? datalistId : undefined"
        autocomplete="off"
      />
      <datalist v-if="def.type === 'text' && suggestions.length" :id="datalistId">
        <option v-for="s in suggestions" :key="s" :value="s" />
      </datalist>
    </template>

    <!-- TEXTAREA -->
    <textarea
      v-else-if="def.type === 'textarea'"
      v-model="stringValue"
      :placeholder="def.placeholder || ''"
    ></textarea>

    <!-- MARKDOWN: large textarea + compact <details> legend below with the
         supported Markdown cheatsheet. No WYSIWYG toolbar (simplicity,
         no extra JS) — if the user needs an idea of the syntax, they
         open the legend. The textarea has `resize:vertical` (browser
         default) so the user can enlarge it. -->
    <template v-else-if="def.type === 'markdown'">
      <textarea
        v-model="stringValue"
        :placeholder="def.placeholder || ''"
        class="font-mono text-sm"
        style="min-height: 320px"
      ></textarea>
      <details class="md-help mt-2">
        <summary class="md-help__summary">
          <iconify-icon icon="lucide:help-circle" width="14"></iconify-icon>
          {{ t('field.mdHelpSummary') }}
        </summary>
        <div class="md-help__body">
          <ul>
            <li><code>**{{ t('field.mdHelpText') }}**</code> → <strong>{{ t('field.mdHelpBold') }}</strong></li>
            <li><code>*{{ t('field.mdHelpText') }}*</code> → <em>{{ t('field.mdHelpItalic') }}</em></li>
            <li><code>[{{ t('field.mdHelpLinkLabel') }}](https://link)</code> → <span class="md-help__link-sample">{{ t('field.mdHelpLink') }}</span></li>
            <li><code>`{{ t('field.mdHelpCode') }}`</code> → <code class="md-help__code-sample">{{ t('field.mdHelpCode') }}</code> {{ t('field.mdHelpCodeInline') }}</li>
            <li><code>- {{ t('field.mdHelpListItem') }}</code> · <code>1. {{ t('field.mdHelpListItem') }}</code> {{ t('field.mdHelpListHint') }}</li>
            <li><code>&gt; {{ t('field.mdHelpQuote') }}</code> {{ t('field.mdHelpQuoteHint') }}</li>
            <li>
              <code>## {{ t('field.mdHelpSubtitle') }}</code>
              <i18n-t keypath="field.mdHelpSubtitleHint" tag="span">
                <template #three><code>###</code></template>
              </i18n-t>
            </li>
            <li>{{ t('field.mdHelpParagraph') }}</li>
          </ul>
        </div>
      </details>
    </template>

    <!-- COLOR -->
    <div v-else-if="def.type === 'color'" class="flex gap-2 items-center">
      <input v-model="stringValue" type="color" class="!w-12 !h-10 !p-1" />
      <input
        v-model="stringValue"
        type="text"
        placeholder="#000000 / rgb(...) / oklch(...)"
        class="flex-1"
      />
    </div>

    <!-- TOGGLE: uses the same styled switch as Settings (settings-switch).
         Pattern: hidden input + visual track/thumb, click on the label
         triggers the checkbox. Consistent with all the panel's toggles. -->
    <label v-else-if="def.type === 'toggle'" class="!flex items-start gap-3 cursor-pointer !mb-0">
      <span class="settings-switch" :class="{ 'is-on': booleanValue }">
        <input
          type="checkbox"
          class="settings-switch__input"
          :checked="booleanValue"
          @change="booleanValue = ($event.target as HTMLInputElement).checked"
        />
        <span class="settings-switch__track" aria-hidden="true">
          <span class="settings-switch__thumb"></span>
        </span>
      </span>
      <span class="flex-1 min-w-0">
        <span class="block text-ink-100 text-sm font-medium">{{ def.label }}</span>
        <span v-if="def.help" class="block text-xs text-ink-300 mt-1 leading-relaxed">{{ def.help }}</span>
      </span>
    </label>

    <!-- SELECT -->
    <select v-else-if="def.type === 'select'" v-model="selectValue">
      <option v-for="o in def.options" :key="o.value" :value="o.value">{{ o.label }}</option>
    </select>

    <!-- RADIO_CARDS: bigger, card-style mutually-exclusive choices. Used
         when a select would be too quiet — typically a "mode" switch
         that visibly toggles which downstream fields are shown. The
         underlying value is still a plain string; this is just a
         render override. -->
    <div v-else-if="def.type === 'radio_cards'" class="radio-cards">
      <label
        v-for="o in def.options"
        :key="o.value"
        class="radio-cards__card"
        :class="{ 'is-active': selectValue === o.value }"
      >
        <input
          type="radio"
          :name="`rc-${def.key}-${datalistId}`"
          :value="o.value"
          :checked="selectValue === o.value"
          class="radio-cards__input"
          @change="selectValue = o.value"
        />
        <span class="radio-cards__dot" aria-hidden="true">
          <span class="radio-cards__dot-inner"></span>
        </span>
        <span class="radio-cards__body">
          <span class="radio-cards__title">{{ o.label }}</span>
          <span v-if="o.description" class="radio-cards__desc">{{ o.description }}</span>
        </span>
      </label>
    </div>

    <!-- ICON -->
    <div v-else-if="def.type === 'icon'" class="flex items-center gap-2">
      <button
        type="button"
        class="w-10 h-10 rounded-lg bg-ink-800 grid place-items-center border border-white/5 hover:border-ink-100/50 transition shrink-0"
        :title="stringValue ? t('field.iconChange') : t('field.iconSearch')"
        @click="iconPickerOpen = true"
      >
        <iconify-icon
          v-if="stringValue"
          :icon="stringValue"
          width="22"
          class="text-ink-100"
        ></iconify-icon>
        <iconify-icon
          v-else
          icon="lucide:search"
          width="20"
          class="text-ink-300"
        ></iconify-icon>
      </button>
      <input
        v-model="stringValue"
        type="text"
        placeholder="lucide:link"
        class="flex-1 min-w-0"
      />
      <button
        type="button"
        class="btn btn-ghost shrink-0"
        @click="iconPickerOpen = true"
      >
        <iconify-icon icon="lucide:search" width="16"></iconify-icon>
        <span class="hidden sm:inline">{{ t('common.browse') }}</span>
      </button>
      <IconPicker v-model:open="iconPickerOpen" v-model="stringValue" />
    </div>

    <!-- IMAGE -->
    <MediaPicker v-else-if="def.type === 'image'" v-model="stringValue" />

    <!-- AVATAR (image with circular preview) -->
    <AvatarPicker v-else-if="def.type === 'avatar'" v-model="stringValue" />

    <!-- REPEAT -->
    <div v-else-if="def.type === 'repeat'" class="space-y-2">
      <draggable
        :model-value="value || []"
        item-key="__id"
        handle=".grip"
        class="space-y-2"
        @update:model-value="moveItems"
      >
        <template #item="{ element: item, index }">
          <div class="bg-ink-800 border border-white/5 rounded-xl p-3">
            <div class="flex items-center gap-2 mb-3">
              <button
                class="grip btn-icon !w-7 !h-7 !cursor-grab active:!cursor-grabbing"
                type="button"
                :aria-label="t('field.dragToReorder')"
                :title="t('field.dragToReorder')"
              >
                <iconify-icon icon="lucide:grip-vertical" width="16"></iconify-icon>
              </button>
              <span class="text-xs uppercase tracking-widest text-ink-300"
                >{{ def.label }} #{{ index + 1 }}</span
              >
              <button
                type="button"
                class="ml-auto btn-icon !w-7 !h-7 hover:!text-red-300"
                :aria-label="t('field.removeItem')"
                @click="removeItem(index)"
              >
                <iconify-icon icon="lucide:trash-2" width="14"></iconify-icon>
              </button>
            </div>
            <template v-for="sub in def.of" :key="sub.key">
              <!-- inline_group: render its children side-by-side, each
                   still bound to the parent item (the group itself does
                   NOT introduce a data path). The container reserves room
                   for the text input first and lets the toggle settle on
                   the right at its natural width. -->
              <div v-if="sub.type === 'inline_group'" class="inline-group">
                <Field
                  v-for="ig in sub.of"
                  :key="ig.key"
                  :def="ig"
                  :model-value="item[ig.key]"
                  :class="ig.type === 'toggle' ? 'inline-group__toggle' : 'inline-group__main'"
                  @update:model-value="(v: unknown) => setItem(index, ig.key, v)"
                />
              </div>
              <!-- regular sub-field with show_when filter -->
              <Field
                v-else-if="shouldShow(sub, item)"
                :def="sub"
                :model-value="item[sub.key]"
                :suggestions="
                  sub.autocomplete_from === 'siblings' ? siblingValues(sub.key) : []
                "
                @update:model-value="(v: unknown) => setItem(index, sub.key, v)"
              />
            </template>
          </div>
        </template>
      </draggable>
      <button type="button" class="btn btn-ghost w-full justify-center" @click="addItem">
        <iconify-icon icon="lucide:plus" width="18"></iconify-icon>
        {{ t('field.addItem', { label: def.label.toLowerCase() }) }}
      </button>
    </div>

    <!-- Generic help line under non-toggle fields. Toggles render their
         own help inline (inside the label, right of the switch), so we
         skip it here to avoid the double-help duplication. radio_cards
         have a description PER option (not at the field level), so the
         field-level help still applies when present. -->
    <p v-if="def.help && def.type !== 'toggle'" class="text-xs text-ink-300 mt-1">{{ def.help }}</p>
  </div>
</template>

<style scoped>
/* Compact Markdown legend below markdown-type textareas.
   Uses native <details> for no-JS expansion. */
.md-help {
  font-size: 12px;
  color: rgb(var(--ink-300-rgb));
}
.md-help__summary {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  user-select: none;
  padding: 4px 0;
  list-style: none;
  color: rgb(var(--ink-300-rgb));
  transition: color 0.15s;
}
.md-help__summary::-webkit-details-marker {
  display: none;
}
.md-help__summary:hover {
  color: rgb(var(--ink-100-rgb));
}
.md-help[open] .md-help__summary {
  color: rgb(var(--ink-100-rgb));
}
.md-help__body {
  margin-top: 6px;
  padding: 10px 14px;
  background: rgb(var(--ink-800-rgb) / 0.6);
  border: 1px solid rgb(var(--ink-700-rgb));
  border-radius: 8px;
}
.md-help__body ul {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 6px;
}
.md-help__body li {
  line-height: 1.4;
  color: rgb(var(--ink-100-rgb) / 0.85);
}
.md-help__body code {
  background: rgb(var(--ink-900-rgb));
  border: 1px solid rgb(var(--ink-700-rgb));
  border-radius: 4px;
  padding: 1px 5px;
  font-size: 11.5px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  color: rgb(var(--ink-100-rgb));
}
.md-help__link-sample {
  color: rgb(var(--accent-rgb));
  border-bottom: 1px solid rgb(var(--accent-rgb) / 0.4);
}
.md-help__code-sample {
  /* Inherits .md-help__body code */
}

/* ----- radio_cards -------------------------------------------------- */
.radio-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 8px;
}
.radio-cards__card {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px;
  background: rgb(var(--ink-800-rgb));
  border: 1px solid rgb(var(--ink-700-rgb));
  border-radius: 12px;
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    background 0.15s ease;
}
.radio-cards__card:hover {
  border-color: rgb(var(--ink-700-rgb) / 1.2);
  background: rgb(var(--ink-800-rgb) / 1.4);
}
.radio-cards__card.is-active {
  /* Always use the admin's "backend-accent" (vivid, computed by theme.ts
     to guarantee contrast against the panel surface). The frontend
     `--accent-rgb` may be white/black/extreme on some palettes and would
     vanish here — see the same reasoning behind .btn-primary and the
     sidebar's active pill. */
  border-color: rgb(var(--backend-accent-rgb));
  background: rgb(var(--backend-accent-rgb) / 0.10);
}
.radio-cards__input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
  width: 0;
  height: 0;
}
.radio-cards__card:focus-within {
  outline: 2px solid rgb(var(--backend-accent-rgb));
  outline-offset: 2px;
}
.radio-cards__dot {
  flex-shrink: 0;
  width: 18px;
  height: 18px;
  border-radius: 999px;
  border: 2px solid rgb(var(--ink-300-rgb));
  display: grid;
  place-items: center;
  margin-top: 1px;
  transition: border-color 0.15s ease;
}
.radio-cards__card.is-active .radio-cards__dot {
  border-color: rgb(var(--backend-accent-rgb));
}
.radio-cards__dot-inner {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: transparent;
  transition: background 0.15s ease;
}
.radio-cards__card.is-active .radio-cards__dot-inner {
  background: rgb(var(--backend-accent-rgb));
}
.radio-cards__body {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.radio-cards__title {
  font-size: 13.5px;
  font-weight: 500;
  color: rgb(var(--ink-100-rgb));
  line-height: 1.3;
}
.radio-cards__desc {
  font-size: 11.5px;
  color: rgb(var(--ink-300-rgb));
  margin-top: 3px;
  line-height: 1.35;
}

/* ----- inline_group ------------------------------------------------- */
/* Children render side-by-side: main input takes the free space, the
   toggle settles on the right at its natural width. The container reuses
   `.field`'s bottom margin so spacing matches surrounding rows.

   Alignment is `flex-end`: the main field has a label above its input
   while the toggle field is label-less (the switch is itself the label
   row). Aligning bottoms puts the switch on the same baseline as the
   input — visually centered with the input box, not with the whole
   field (which would push it up by half the label's height). */
.inline-group {
  display: flex;
  align-items: flex-end;
  gap: 16px;
  margin-bottom: 1rem;
}
.inline-group :deep(.field) {
  margin-bottom: 0;
}
.inline-group :deep(.inline-group__main) {
  flex: 1 1 auto;
  min-width: 0;
}
.inline-group :deep(.inline-group__toggle) {
  flex: 0 0 auto;
  /* Drop the toggle to roughly the input's vertical midline. Inputs are
     ~42px tall (py-2.5 + border + line-height); the switch is ~22px, so
     a small bottom padding fine-tunes the optical center. */
  padding-bottom: 10px;
}
</style>