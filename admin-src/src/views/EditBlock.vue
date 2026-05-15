<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api } from '../api'
import type { Block, BlockType } from '../types'
import Field from '../components/Field.vue'
import { useConfirm } from '../composables/useConfirm'

const { t } = useI18n()
const { confirm } = useConfirm()

const props = defineProps<{ id: string }>()
const router = useRouter()

const block = ref<Block | null>(null)
const type = ref<BlockType | null>(null)
// Snapshot of the block as it is in the DB. `isDirty` is derived from
// JSON.stringify(block.value) !== savedSnapshot.value — same pattern as
// Theme.vue. Avoids the `watch + dirty=true` bug that fired on the
// initial `block.value = b` assignment (the initial assign tripped the
// watch and marked the page "modified" before the user touched anything).
// Also makes "discard changes" trivial: re-parse the saved JSON.
const savedSnapshot = ref<string>('')
const saving = ref(false)
const cacheBust = ref(Date.now())

const isDirty = computed(() => {
  if (!block.value) return false
  return JSON.stringify(block.value) !== savedSnapshot.value
})

/**
 * Forward-compatibility migration applied to a block's `data` after the
 * load but BEFORE the dirty snapshot. Patches legacy items that lack
 * fields introduced later, so they show up correctly in the editor
 * without tripping `isDirty` on open.
 *
 * Per-type rules:
 *   - links: each item gets `icon_mode = 'custom'` if it has a non-empty
 *     `icon` string, 'favicon' otherwise. Older blocks were saved before
 *     `icon_mode` existed.
 */
function normalizeLegacyData(b: Block): void {
  if (b.type === 'links') {
    const data = b.data as { items?: Array<Record<string, unknown>> }
    const items = Array.isArray(data.items) ? data.items : []
    for (const item of items) {
      if (item.icon_mode === undefined) {
        item.icon_mode = typeof item.icon === 'string' && item.icon !== '' ? 'custom' : 'favicon'
      }
    }
  }
}

onMounted(async () => {
  const [{ blocks }, { types }] = await Promise.all([api.listBlocks(), api.types()])
  const b = blocks.find((x) => x.id === Number(props.id))
  if (!b) {
    router.push('/')
    return
  }
  normalizeLegacyData(b)
  block.value = b
  type.value = types.find((tt) => tt.id === b.type) || null
  savedSnapshot.value = JSON.stringify(b)
})

async function save() {
  if (!block.value || saving.value) return
  saving.value = true
  try {
    const r = await api.updateBlock(block.value.id, {
      data: block.value.data,
      enabled: block.value.enabled,
      style: block.value.style as Record<string, unknown>,
    })
    block.value = r.block
    savedSnapshot.value = JSON.stringify(r.block)
    cacheBust.value = Date.now()
  } finally {
    saving.value = false
  }
}

function discardChanges() {
  if (!savedSnapshot.value) return
  block.value = JSON.parse(savedSnapshot.value)
}

// Width of the tile in the desktop mosaic (2 columns).
// `block.style.span` is a per-block override; when absent, falls back
// to the per-type default declared in BlockRegistry. The setter always
// writes the override (the user explicitly picks Half or Full).
const currentSpan = computed<'half' | 'full'>({
  get() {
    const s = block.value?.style as Record<string, unknown> | undefined
    const override = s?.span
    if (override === 'half' || override === 'full') return override
    return type.value?.span ?? 'full'
  },
  set(v: 'half' | 'full') {
    if (!block.value) return
    const current = (block.value.style ?? {}) as Record<string, unknown>
    block.value.style = { ...current, span: v }
  },
})

// True if the user has chosen a value different from the type's default.
// Used only to show a small "(override)" hint next to the control.
const isSpanOverride = computed<boolean>(() => {
  const s = block.value?.style as Record<string, unknown> | undefined
  const override = s?.span
  if (override !== 'half' && override !== 'full') return false
  return override !== type.value?.span
})

function resetSpan() {
  if (!block.value) return
  const { span: _, ...rest } = (block.value.style ?? {}) as Record<string, unknown>
  block.value.style = rest
}

// "No background" — per-block override that ignores the theme's
// tile-style: the tile becomes transparent (no bg, border, shadow),
// content goes edge-to-edge. Useful for dividers, hero, blocks that
// want to blend with the page. Stored in `style.no_bg` (boolean).
const noBg = computed<boolean>({
  get() {
    return Boolean((block.value?.style as Record<string, unknown> | undefined)?.no_bg)
  },
  set(v: boolean) {
    if (!block.value) return
    const cur = (block.value.style ?? {}) as Record<string, unknown>
    if (v) {
      block.value.style = { ...cur, no_bg: true }
    } else {
      const { no_bg: _, ...rest } = cur
      block.value.style = rest
    }
  },
})

// Section-title size: small / standard (default) / large. Per-block
// override in `style.title_size`. Rendered as a segmented control near
// "Width" because both are presentation settings that don't belong to
// the block's data fields.
type TitleSize = 'small' | 'standard' | 'large'
const currentTitleSize = computed<TitleSize>({
  get() {
    const s = block.value?.style as Record<string, unknown> | undefined
    const v = s?.title_size
    if (v === 'small' || v === 'standard' || v === 'large') return v
    return 'standard'
  },
  set(v: TitleSize) {
    if (!block.value) return
    const current = (block.value.style ?? {}) as Record<string, unknown>
    if (v === 'standard') {
      // Standard is the default → don't pollute `style` with an equivalent value.
      const { title_size: _, ...rest } = current
      block.value.style = rest
    } else {
      block.value.style = { ...current, title_size: v }
    }
  },
})

// Types that do NOT have a "title" field don't show this control.
// Hero has m-hero__title handled separately (not a section title);
// divider/footer/cta have no section title. The list is conservative:
// blocks not in BlockKind (e.g. quote/faq/stats declared server-side
// but not in the frontend types) still hit the "show control" path
// because they all have a title field in the registry.
const NO_SECTION_TITLE = new Set(['hero', 'divider', 'footer', 'cta'])
const showTitleSizeControl = computed(() => {
  const id = type.value?.id
  if (!id) return false
  return !NO_SECTION_TITLE.has(id)
})

async function remove() {
  if (!block.value) return
  if (
    !(await confirm({
      message: t('editBlock.deleteConfirmNamed', { label: type.value?.label ?? block.value.type }),
      confirmLabel: t('editBlock.deleteConfirmLabel'),
      destructive: true,
    }))
  )
    return
  await api.deleteBlock(block.value.id)
  router.push('/')
}

// Apply current data+style of the separator to ALL separators on the
// page, then go back to the dashboard. Works even with unsaved changes
// (the call itself is the save: the current block is included in the
// UPDATE). Afterwards the user lands on the admin home, avoiding an
// inconsistent edit state.
const applyingToAll = ref(false)
async function applyDividerToAll() {
  if (!block.value || applyingToAll.value) return
  if (
    !(await confirm({
      message: t('editBlock.applyDividerConfirmLong'),
      confirmLabel: t('editBlock.applyDividerToAllShort'),
    }))
  )
    return
  applyingToAll.value = true
  try {
    await api.applyToSameType(
      block.value.id,
      block.value.data as Record<string, unknown>,
      (block.value.style ?? {}) as Record<string, unknown>,
    )
    // Back to the admin home: clean state, no residual dirty to handle.
    router.push('/')
  } catch {
    applyingToAll.value = false
  }
}
</script>

<template>
  <template v-if="block && type">
    <div class="flex flex-wrap items-center gap-3 mb-6">
      <button class="btn-icon" :aria-label="t('editBlock.back')" @click="router.push('/')">
        <iconify-icon icon="lucide:arrow-left" width="18"></iconify-icon>
      </button>
      <!-- Block type icon with accent bg: same size as the "Back" btn-icon
           + background to make clear it's the type's "brand". Without a
           background the icon alone looked too small next to the 2 lines
           of text (eyebrow + heading) on the right. -->
      <span
        class="w-10 h-10 rounded-xl bg-ink-100/10 ring-1 ring-ink-100/60 grid place-items-center flex-shrink-0"
      >
        <iconify-icon :icon="type.icon" width="22" class="text-backend-accent"></iconify-icon>
      </span>
      <div>
        <p class="eyebrow">{{ type.category }}</p>
        <h1 class="heading">{{ type.label }}</h1>
      </div>
      <div class="ml-auto flex flex-wrap items-center gap-2">
        <!-- Visibility toggle: segmented control [Visible | Hidden] —
             same pattern as Compact/Contents in the Dashboard. More
             compact and consistent than "status badge + action button". -->
        <div
          class="flex h-7 bg-ink-800 rounded-full p-0.5 border border-ink-300/40"
          :title="t('editBlock.visibility')"
        >
          <button
            type="button"
            class="h-full px-2.5 rounded-full text-[11px] leading-none flex items-center gap-1 transition"
            :class="
              block.enabled
                ? 'bg-ink-100 text-ink-900 font-medium'
                : 'text-ink-300 hover:text-ink-100'
            "
            :title="t('editBlock.visibleTitle')"
            @click="block.enabled = true"
          >
            <iconify-icon icon="lucide:eye" width="11"></iconify-icon>
            {{ t('editBlock.visible') }}
          </button>
          <button
            type="button"
            class="h-full px-2.5 rounded-full text-[11px] leading-none flex items-center gap-1 transition"
            :class="
              !block.enabled
                ? 'bg-ink-100 text-ink-900 font-medium'
                : 'text-ink-300 hover:text-ink-100'
            "
            :title="t('editBlock.hiddenTitle')"
            @click="block.enabled = false"
          >
            <iconify-icon icon="lucide:eye-off" width="11"></iconify-icon>
            {{ t('editBlock.hidden') }}
          </button>
        </div>
        <button class="btn btn-danger" @click="remove">
          <iconify-icon icon="lucide:trash-2" width="18"></iconify-icon>
          {{ t('editBlock.deleteTile') }}
        </button>
        <!-- "Unsaved changes" indicator + Cancel — identical pattern to
             Theme.vue. Shown as soon as the user touches ANY field of the
             tile; disappears after Save or Cancel. -->
        <span
          v-if="isDirty"
          class="text-xs px-3 py-1.5 rounded-full bg-ink-100/10 text-ink-100 border border-ink-100/20 flex items-center gap-1.5"
        >
          <iconify-icon icon="lucide:circle" width="8"></iconify-icon>
          {{ t('editBlock.unsavedChanges') }}
        </span>
        <button v-if="isDirty" class="btn btn-ghost" @click="discardChanges">
          <iconify-icon icon="lucide:rotate-ccw" width="18"></iconify-icon>
          {{ t('editBlock.cancel') }}
        </button>
        <button class="btn btn-primary" :disabled="!isDirty || saving" @click="save">
          <iconify-icon
            :icon="saving ? 'lucide:loader-circle' : 'lucide:check'"
            width="18"
            :class="saving ? 'animate-spin' : ''"
          ></iconify-icon>
          {{ saving ? t('editBlock.saving') : t('editBlock.save') }}
        </button>
      </div>
    </div>

    <div class="grid lg:grid-cols-[1fr_420px] gap-6 items-start">
      <div class="tile">
        <p class="text-sm text-ink-300 mb-4">{{ type.description }}</p>

        <!-- Layout: width in the desktop mosaic (mobile is always 1 column).
             Per-block override; reset clears the override and returns to
             the default. -->
        <div
          v-if="type.id !== 'footer' && type.id !== 'divider'"
          class="flex items-center gap-3 pb-4 mb-4 border-b border-white/5 flex-wrap"
        >
          <span class="text-sm font-medium text-ink-100">{{ t('editBlock.spanLabel') }}</span>
          <div class="span-seg" role="group" :aria-label="t('editBlock.spanLabelAria')">
            <button
              type="button"
              class="span-seg__btn"
              :class="{ 'is-active': currentSpan === 'half' }"
              @click="currentSpan = 'half'"
            >
              <iconify-icon icon="lucide:columns-2" width="14"></iconify-icon>
              {{ t('editBlock.spanHalf') }}
            </button>
            <button
              type="button"
              class="span-seg__btn"
              :class="{ 'is-active': currentSpan === 'full' }"
              @click="currentSpan = 'full'"
            >
              <iconify-icon icon="lucide:stretch-horizontal" width="14"></iconify-icon>
              {{ t('editBlock.spanFull') }}
            </button>
          </div>
          <button
            v-if="isSpanOverride"
            type="button"
            class="text-xs text-ink-300 hover:text-ink-100 underline-offset-2 hover:underline"
            :title="
              t('editBlock.spanDefaultHint', {
                label: type.label,
                span: type.span === 'half' ? t('editBlock.spanHalf') : t('editBlock.spanFull'),
              })
            "
            @click="resetSpan"
          >
            {{ t('editBlock.spanReset') }}
          </button>
          <span class="text-xs text-ink-300 ml-auto">
            {{ t('editBlock.spanOnlyDesktop') }}
          </span>
        </div>

        <!-- Tile background: per-block override that beats the theme's
             global tile-style. "Default" = the block inherits the theme's
             tile style. "No background" = transparent, no border, no
             shadow, content edge-to-edge. -->
        <div class="flex items-center gap-3 pb-4 mb-4 border-b border-white/5 flex-wrap">
          <span class="text-sm font-medium text-ink-100">{{ t('editBlock.background') }}</span>
          <div class="span-seg" role="group" :aria-label="t('editBlock.background')">
            <button
              type="button"
              class="span-seg__btn"
              :class="{ 'is-active': !noBg }"
              :title="t('editBlock.backgroundDefaultTitle')"
              @click="noBg = false"
            >
              <iconify-icon icon="lucide:square" width="14"></iconify-icon>
              {{ t('editBlock.backgroundDefault') }}
            </button>
            <button
              type="button"
              class="span-seg__btn"
              :class="{ 'is-active': noBg }"
              :title="t('editBlock.backgroundNoneTitle')"
              @click="noBg = true"
            >
              <iconify-icon icon="lucide:square-dashed" width="14"></iconify-icon>
              {{ t('editBlock.backgroundNone') }}
            </button>
          </div>
          <span class="text-xs text-ink-300 ml-auto">
            {{ t('editBlock.backgroundHint') }}
          </span>
        </div>

        <!-- Section title size: applicable to all blocks that have a
             `title` field (links, social, apps, products, etc.). For
             blocks without a section title (hero, divider, footer, cta)
             the control is hidden. -->
        <div
          v-if="showTitleSizeControl"
          class="flex items-center gap-3 pb-4 mb-4 border-b border-white/5 flex-wrap"
        >
          <span class="text-sm font-medium text-ink-100">{{ t('editBlock.titleSize') }}</span>
          <div class="span-seg" role="group" :aria-label="t('editBlock.titleSizeAria')">
            <button
              type="button"
              class="span-seg__btn"
              :class="{ 'is-active': currentTitleSize === 'small' }"
              @click="currentTitleSize = 'small'"
            >
              <iconify-icon icon="lucide:type" width="12"></iconify-icon>
              {{ t('editBlock.titleSizeSmall') }}
            </button>
            <button
              type="button"
              class="span-seg__btn"
              :class="{ 'is-active': currentTitleSize === 'standard' }"
              @click="currentTitleSize = 'standard'"
            >
              <iconify-icon icon="lucide:type" width="14"></iconify-icon>
              {{ t('editBlock.titleSizeStandard') }}
            </button>
            <button
              type="button"
              class="span-seg__btn"
              :class="{ 'is-active': currentTitleSize === 'large' }"
              @click="currentTitleSize = 'large'"
            >
              <iconify-icon icon="lucide:type" width="18"></iconify-icon>
              {{ t('editBlock.titleSizeLarge') }}
            </button>
          </div>
        </div>

        <!-- Dynamic v-model by key: the editor is schema-driven, so we
             index `block.data` as a record. Vue still tracks the
             mutation (same object). -->
        <Field
          v-for="f in type.fields"
          :key="f.key"
          v-model="(block.data as Record<string, unknown>)[f.key]"
          :def="f"
        />

        <!-- "Apply to all separators" button — only for type=divider.
             ALWAYS visible (even with unsaved changes): the call itself
             serves as the save by propagating the current style to all
             divider rows. Afterwards, redirect to admin home → no edit
             with inconsistent state. See applyDividerToAll() in the
             script. -->
        <div v-if="type.id === 'divider'" class="mt-6 pt-4 border-t border-white/5">
          <p class="text-xs text-ink-300 mb-3">
            {{ t('editBlock.applyDividerHint') }}
          </p>
          <button
            type="button"
            class="btn btn-ghost"
            :disabled="applyingToAll"
            @click="applyDividerToAll"
          >
            <iconify-icon
              :icon="applyingToAll ? 'lucide:loader-circle' : 'lucide:copy-check'"
              :class="applyingToAll ? 'animate-spin' : ''"
              width="18"
            ></iconify-icon>
            {{
              applyingToAll
                ? t('editBlock.applyDividerApplying')
                : t('editBlock.applyDividerToAllAction')
            }}
          </button>
        </div>
      </div>

      <aside class="tile sticky top-6">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-2">
            <iconify-icon icon="lucide:smartphone" width="18" class="text-ink-100"></iconify-icon>
            <h3 class="font-display text-lg">{{ t('editBlock.previewTitle') }}</h3>
          </div>
          <button
            class="btn-icon"
            :title="t('editBlock.previewReload')"
            @click="cacheBust = Date.now()"
          >
            <iconify-icon icon="lucide:refresh-cw" width="16"></iconify-icon>
          </button>
        </div>
        <div
          class="rounded-xl overflow-hidden border border-white/5 bg-black"
          style="aspect-ratio: 9/16"
        >
          <iframe
            :src="`/api/preview?only=${block.id}&t=${cacheBust}`"
            class="w-full h-full border-0"
            :title="t('editBlock.previewIframeTitle')"
          />
        </div>
        <!-- When the block is "dirty", clearly indicate that the preview
             is the SAVED (old) version and doesn't reflect the current
             changes. Saving is the only way to see them. -->
        <p
          v-if="isDirty"
          class="text-xs mt-2 px-3 py-2 rounded-lg bg-ink-100/10 text-ink-100 border border-ink-100/20 flex items-start gap-2"
        >
          <iconify-icon icon="lucide:info" width="14" class="flex-shrink-0 mt-0.5"></iconify-icon>
          <i18n-t keypath="editBlock.previewSavedNotice" tag="span">
            <template #save
              ><strong>{{ t('editBlock.save') }}</strong></template
            >
          </i18n-t>
        </p>
        <p v-else class="text-xs text-ink-300 mt-2">
          {{ t('editBlock.previewHint') }}
        </p>
      </aside>
    </div>
  </template>
  <div v-else class="text-ink-300">{{ t('editBlock.loading') }}</div>
</template>

<style scoped>
.span-seg {
  display: inline-flex;
  background: rgb(var(--ink-800-rgb));
  border: 1px solid rgb(var(--ink-700-rgb));
  border-radius: 8px;
  padding: 2px;
  gap: 2px;
}
.span-seg__btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 6px;
  font-size: 13px;
  color: rgb(var(--ink-300-rgb));
  background: transparent;
  border: 0;
  cursor: pointer;
  transition:
    background 0.15s,
    color 0.15s;
}
.span-seg__btn:hover {
  color: rgb(var(--ink-100-rgb));
}
.span-seg__btn.is-active {
  background: rgb(var(--ink-100-rgb));
  color: rgb(var(--ink-900-rgb));
  font-weight: 500;
}
</style>
