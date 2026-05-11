<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useStorage } from '@vueuse/core'
import draggable from 'vuedraggable'
import { api } from '../api'
import type { Block, BlockKind, BlockType } from '../types'
import AddBlockSheet from '../components/AddBlockSheet.vue'
import BlockPreview from '../components/BlockPreview.vue'
import PreviewPanel from '../components/PreviewPanel.vue'
import { useConfirm } from '../composables/useConfirm'

const { t } = useI18n()
const { confirm } = useConfirm()

const blocks = ref<Block[]>([])
const types = ref<BlockType[]>([])
const loading = ref(true)
const showAdd = ref(false)
const showPreview = ref(false)
// Persist the view mode in localStorage (default: rich preview).
const viewMode = useStorage<'compact' | 'preview'>('tylio:dashboardView', 'preview')
const router = useRouter()

const typeMap = computed(() => Object.fromEntries(types.value.map((t) => [t.id, t])))

// Compute the grid class for each block: 'full' (span 2) or 'half'
// (span 1). Must stay aligned with Renderer::blockSpan in the PHP
// backend: per-block override (`block.style.span`) > per-type default
// from BlockRegistry.
//
// Note: historically an "orphan" half (not paired) was stretched to 2
// columns to avoid grid gaps. Current design: the gap stays — a half is
// always a half, even when alone. The 'orphan' value lives in the union
// for legacy-CSS compat but is no longer assigned.
const placement = computed<Record<number, 'full' | 'half' | 'orphan'>>(() => {
  const out: Record<number, 'full' | 'half' | 'orphan'> = {}
  const blockSpan = (b: Block): 1 | 2 => {
    const override = (b.style as Record<string, unknown> | undefined)?.span
    if (override === 'half') return 1
    if (override === 'full') return 2
    return (typeMap.value[b.type]?.span || 'full') === 'full' ? 2 : 1
  }
  for (const b of blocks.value) {
    out[b.id] = blockSpan(b) === 2 ? 'full' : 'half'
  }
  return out
})

onMounted(async () => {
  await refresh()
  loading.value = false
})

async function refresh() {
  const [b, t] = await Promise.all([api.listBlocks(), api.types()])
  blocks.value = b.blocks
  types.value = t.types
}

async function persistOrder() {
  await api.reorder(blocks.value.map((b) => b.id))
}

async function toggleEnabled(b: Block) {
  const r = await api.updateBlock(b.id, { enabled: !b.enabled })
  Object.assign(b, r.block)
}

/**
 * Toggle tile width (half ↔ full) right from the dashboard without
 * entering the edit view. Computes the current span with the same
 * priority as `placement` (override > per-type default) and ALWAYS
 * writes an explicit override on the block — so the user's choice is
 * unambiguous, independent of the per-type default.
 */
async function toggleSpan(b: Block) {
  const styleNow = (b.style ?? {}) as Record<string, unknown>
  const override = styleNow.span
  const typeDefault = typeMap.value[b.type]?.span || 'full'
  const current: 'half' | 'full' =
    override === 'half' || override === 'full' ? override : (typeDefault as 'half' | 'full')
  const next: 'half' | 'full' = current === 'half' ? 'full' : 'half'
  const r = await api.updateBlock(b.id, { style: { ...styleNow, span: next } })
  Object.assign(b, r.block)
}

async function remove(b: Block) {
  const label = typeMap.value[b.type]?.label ?? b.type
  if (
    !(await confirm({
      message: t('dashboard.deleteConfirm', { label }),
      confirmLabel: t('dashboard.deleteConfirmLabel'),
      destructive: true,
    }))
  )
    return
  await api.deleteBlock(b.id)
  blocks.value = blocks.value.filter((x) => x.id !== b.id)
}

async function add(type: BlockKind) {
  const r = await api.createBlock(type)
  blocks.value.push(r.block)
  showAdd.value = false
  router.push({ name: 'edit-block', params: { id: r.block.id } })
}

/**
 * "Human" title of the block — what the user typed in the `data.title`
 * field. Used as the main heading of each dashboard card so a "Favorite
 * links" section is distinguishable from "Useful resources" at a glance,
 * without clicking. When the block has no title (e.g. divider, footer,
 * quote, cta) or the user left it empty, we fall back to the type label
 * as heading. Block's discriminated union doesn't expose `title` on
 * every type, so we access via Record/string.
 */
/** True when the block has `style.no_bg = true` → renders "transparent" in the Dashboard. */
function isNoBg(b: Block): boolean {
  const s = b.style as Record<string, unknown> | undefined
  return Boolean(s && s.no_bg)
}

function blockTitle(b: Block): string {
  const raw = (b.data as Record<string, unknown>).title
  return typeof raw === 'string' ? raw.trim() : ''
}

function blockSummary(b: Block): string {
  const ty = typeMap.value[b.type]
  if (!ty) return ''
  switch (b.type) {
    case 'hero':
      return String(b.data.title || b.data.subtitle || '').slice(0, 60) || ty.description
    case 'links':
      return b.data.items?.length ? t('dashboard.countLinks', { n: b.data.items.length }) : ty.description
    case 'apps':
      return b.data.items?.length ? t('dashboard.countProjects', { n: b.data.items.length }) : ty.description
    case 'gallery':
      return b.data.items?.length ? t('dashboard.countImages', { n: b.data.items.length }) : ty.description
    case 'social':
      return b.data.items?.length ? t('dashboard.countProfiles', { n: b.data.items.length }) : ty.description
    case 'products':
      return b.data.items?.length ? t('dashboard.countProducts', { n: b.data.items.length }) : ty.description
    case 'bio':
      return (b.data.body || '').slice(0, 60).replace(/\n/g, ' ') || ty.description
    case 'embed':
      return b.data.title || b.data.url || ty.description
    case 'youtube':
      return b.data.source_url || ty.description
    case 'podcast': {
      const platforms: string[] = []
      if (b.data.apple_url) platforms.push('Apple')
      if (b.data.spotify_url) platforms.push('Spotify')
      if (b.data.site_url) platforms.push(t('dashboard.podcastPlatformSite'))
      return (
        b.data.show_name || (platforms.length ? platforms.join(' · ') : ty.description)
      )
    }
    case 'contact':
      return b.data.title || ty.description
    case 'skills':
      return b.data.items?.length ? t('dashboard.countSkills', { n: b.data.items.length }) : ty.description
    case 'divider':
    case 'footer':
      return ty.description
  }
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div>
      <p class="eyebrow">{{ t('dashboard.eyebrow') }}</p>
      <h1 class="heading">{{ t('dashboard.title') }}</h1>
    </div>
    <div class="flex items-center gap-2">
      <!-- "View" toggle (compact vs contents): secondary choice, NOT an
           action. EXPLICITLY small height (h-7 = 28px) to visually
           distinguish from action buttons (~40px) — also with
           `items-center` on the parent so the wrapper isn't stretched. -->
      <div
        class="flex h-7 bg-ink-800 rounded-full p-0.5 border border-ink-300/40"
        :title="t('dashboard.view')"
      >
        <button
          type="button"
          class="h-full px-2.5 rounded-full text-[11px] leading-none flex items-center gap-1 transition"
          :class="
            viewMode === 'compact'
              ? 'bg-ink-100 text-ink-900 font-medium'
              : 'text-ink-300 hover:text-ink-100'
          "
          :title="t('dashboard.viewCompactTitle')"
          @click="viewMode = 'compact'"
        >
          <iconify-icon icon="lucide:list" width="11"></iconify-icon>
          {{ t('dashboard.viewCompact') }}
        </button>
        <button
          type="button"
          class="h-full px-2.5 rounded-full text-[11px] leading-none flex items-center gap-1 transition"
          :class="
            viewMode === 'preview'
              ? 'bg-ink-100 text-ink-900 font-medium'
              : 'text-ink-300 hover:text-ink-100'
          "
          :title="t('dashboard.viewPreviewTitle')"
          @click="viewMode = 'preview'"
        >
          <iconify-icon icon="lucide:layout-grid" width="11"></iconify-icon>
          {{ t('dashboard.viewPreview') }}
        </button>
      </div>
      <button class="btn btn-ghost" @click="showPreview = true">
        <iconify-icon icon="lucide:eye" width="18"></iconify-icon>
        {{ t('dashboard.preview') }}
      </button>
      <button class="btn btn-primary" @click="showAdd = true">
        <iconify-icon icon="lucide:plus" width="18"></iconify-icon>
        {{ t('dashboard.addTile') }}
      </button>
    </div>
  </div>

  <div v-if="loading" class="text-ink-300">{{ t('dashboard.loading') }}</div>

  <div v-else-if="blocks.length === 0" class="tile text-center py-16">
    <iconify-icon
      icon="lucide:layout-grid"
      width="48"
      class="text-ink-100 mx-auto"
    ></iconify-icon>
    <h3 class="font-display text-2xl mt-4">{{ t('dashboard.emptyTitle') }}</h3>
    <p class="text-ink-300 mt-2">{{ t('dashboard.emptyHint') }}</p>
    <button class="btn btn-primary mt-4 inline-flex" @click="showAdd = true">
      <iconify-icon icon="lucide:plus" width="18"></iconify-icon>
      {{ t('dashboard.addFirstTile') }}
    </button>
  </div>

  <draggable
    v-else
    v-model="blocks"
    item-key="id"
    handle=".grip"
    class="dash-grid"
    @end="persistOrder"
  >
    <template #item="{ element: b }">
      <!-- `dash-tile--no-bg` reflects block.style.no_bg → the tile in the
           Dashboard appears without bg/border (light dashed border, solid
           hover) to match the "No background" override of the public site. -->
      <article
        class="tile group relative cursor-pointer hover:border-ink-100/40 transition"
        :class="[
          { 'opacity-60': !b.enabled },
          { 'dash-tile--no-bg': isNoBg(b) },
          placement[b.id] === 'full'
            ? 'dash-tile--full'
            : placement[b.id] === 'orphan'
              ? 'dash-tile--orphan'
              : 'dash-tile--half',
        ]"
        @click="router.push({ name: 'edit-block', params: { id: b.id } })"
      >
        <div class="flex items-center gap-3 mb-2">
          <button
            class="grip btn-icon !w-8 !h-8 !cursor-grab active:!cursor-grabbing"
            :aria-label="t('dashboard.dragHandle')"
            :title="t('dashboard.dragHandle')"
            @click.stop
          >
            <iconify-icon icon="lucide:grip-vertical" width="18" class="text-ink-300"></iconify-icon>
          </button>
          <iconify-icon
            :icon="typeMap[b.type]?.icon || 'lucide:square'"
            width="20"
            class="text-ink-100 flex-shrink-0"
          ></iconify-icon>
          <!-- Two-line header: eyebrow with the TYPE (always, so the user
               immediately distinguishes link/social/apps/...) + heading
               with the TITLE typed by the user (e.g. "My favorite links").
               If the block has no title or the user left it empty, the
               eyebrow disappears and the type label becomes the main
               heading. -->
          <div class="flex-1 min-w-0">
            <template v-if="blockTitle(b)">
              <p class="text-[10px] uppercase tracking-widest text-ink-300 leading-tight">
                {{ typeMap[b.type]?.label || b.type }}
              </p>
              <h3 class="font-display text-base leading-tight truncate">{{ blockTitle(b) }}</h3>
            </template>
            <h3 v-else class="font-display text-lg leading-tight truncate">
              {{ typeMap[b.type]?.label || b.type }}
            </h3>
          </div>
          <span v-if="!b.enabled" class="text-[10px] uppercase tracking-widest text-ink-300 ml-auto flex-shrink-0"
            >{{ t('dashboard.tileHidden') }}</span
          >
        </div>
        <BlockPreview v-if="viewMode === 'preview'" :block="b" :type="typeMap[b.type]" />
        <p v-else class="text-sm text-ink-300 line-clamp-2">{{ blockSummary(b) }}</p>

        <div class="absolute top-3 right-3 flex gap-1 opacity-0 group-hover:opacity-100 transition">
          <!-- Width toggle: the icon "matches" the current tile shape
               (horizontal rectangle = full, vertical = half), so the user
               identifies the state at a glance. Click toggles — the
               tooltip title explains the action. Hidden for
               footer/divider which are always full at the registry level. -->
          <button
            v-if="b.type !== 'footer' && b.type !== 'divider'"
            class="btn-icon !w-8 !h-8"
            :title="
              placement[b.id] === 'full'
                ? t('dashboard.spanFullToHalf')
                : t('dashboard.spanHalfToFull')
            "
            @click.stop="toggleSpan(b)"
          >
            <iconify-icon
              :icon="
                placement[b.id] === 'full'
                  ? 'lucide:stretch-horizontal'
                  : 'lucide:columns-2'
              "
              width="16"
            ></iconify-icon>
          </button>
          <button
            class="btn-icon !w-8 !h-8"
            :title="b.enabled ? t('dashboard.hide') : t('dashboard.show')"
            @click.stop="toggleEnabled(b)"
          >
            <iconify-icon
              :icon="b.enabled ? 'lucide:eye-off' : 'lucide:eye'"
              width="16"
            ></iconify-icon>
          </button>
          <button
            class="btn-icon !w-8 !h-8 hover:!bg-red-500/20 hover:!text-red-200"
            :title="t('dashboard.delete')"
            @click.stop="remove(b)"
          >
            <iconify-icon icon="lucide:trash-2" width="16"></iconify-icon>
          </button>
        </div>
      </article>
    </template>
  </draggable>

  <AddBlockSheet v-if="showAdd" :types="types" @close="showAdd = false" @pick="add" />
  <PreviewPanel v-if="showPreview" @close="showPreview = false" />
</template>

<style scoped>
/* WYSIWYG layout: mirrors the public side EXACTLY.
   1 column mobile, 2 columns desktop, span 1/2 based on type.
   NO grid-auto-flow: dense — we keep the strict block order exactly as
   it will appear on the home. If two halves aren't adjacent, you'll see
   gaps (and can drag to put them side by side). */
.dash-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}
@media (min-width: 768px) {
  .dash-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .dash-tile--full {
    grid-column: span 2;
  }
  .dash-tile--half {
    grid-column: span 1;
  }
  /* Half without a partner → stretch to the full row (as on the public side) */
  .dash-tile--orphan {
    grid-column: span 2;
  }
}

/* "No background" — reflects the public site's override. Transparent
   background + dashed border (to indicate that the tile is there and
   clickable, but on the public side it will be rendered without a
   panel). Hover: solid border (=text) to indicate interaction. */
.dash-tile--no-bg {
  background: transparent;
  border: 1px dashed rgb(var(--ink-100-rgb) / 0.25);
  box-shadow: none;
}
.dash-tile--no-bg:hover {
  border-style: solid;
  border-color: rgb(var(--ink-100-rgb) / 0.40);
}
</style>
