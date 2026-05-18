<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useStorage } from '@vueuse/core'
import draggable from 'vuedraggable'
import Sortable, { type SortableEvent } from 'sortablejs'
import { api } from '../api'
import type { Block, BlockKind, BlockType } from '../types'
import AddBlockSheet from '../components/AddBlockSheet.vue'
import MoveExistingToGroupSheet from '../components/MoveExistingToGroupSheet.vue'
import BlockPreview from '../components/BlockPreview.vue'
import PreviewPanel from '../components/PreviewPanel.vue'
import { useConfirm } from '../composables/useConfirm'

const { t } = useI18n()
const { confirm } = useConfirm()

const blocks = ref<Block[]>([])
const types = ref<BlockType[]>([])
const loading = ref(true)
const showAdd = ref(false)
// True while a drag is in flight (set by vuedraggable's @start, cleared
// on @end). Used by CSS to highlight group drop zones and to suspend
// click-to-edit so a click that started a drag doesn't navigate.
const dragging = ref(false)
// When non-null, `Add tile` will create the new block inside this
// group instead of at the top level. Set by the per-group `+` button.
const addInsideGroup = ref<number | null>(null)
const showMoveExisting = ref(false)
const moveExistingTargetGroup = ref<number | null>(null)
const showPreview = ref(false)
// Persist the view mode in localStorage (default: rich preview).
const viewMode = useStorage<'compact' | 'preview'>('tylio:dashboardView', 'preview')
const router = useRouter()

const typeMap = computed(() => Object.fromEntries(types.value.map((t) => [t.id, t])))

// Top-level blocks (parent_id falsy). Writable so vuedraggable can mutate
// it on drag. Synced from `blocks` after every server roundtrip.
const topLevel = ref<Block[]>([])
// Per-group children buckets, keyed by group id. Each bucket is its own
// writable list so a nested <draggable> can v-model directly against it.
const childrenByParent = ref<Record<number, Block[]>>({})

function syncBuckets() {
  topLevel.value = blocks.value.filter((b) => !b.parent_id)
  const byParent: Record<number, Block[]> = {}
  for (const b of blocks.value) {
    if (b.parent_id) (byParent[b.parent_id] ??= []).push(b)
  }
  for (const b of blocks.value) {
    if (b.type === 'group' && !byParent[b.id]) byParent[b.id] = []
  }
  childrenByParent.value = byParent
}

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
  syncBuckets()
}

async function onTopLevelUpdate() {
  try {
    await api.reorder(topLevel.value.map((b) => b.id))
  } catch (e) {
    console.error('top reorder failed', e)
    await refresh()
  }
}

function canDropInto(parentId: number | null, evt: { draggedContext: { element: Block } }): boolean {
  const item = evt.draggedContext.element
  if (parentId !== null && (item.type === 'group' || item.type === 'footer')) {
    return false
  }
  return true
}

function onTopLevelMove(evt: {
  draggedContext: { element: Block }
  relatedContext?: { element?: Block }
}): boolean {
  const dragged = evt.draggedContext.element
  const related = evt.relatedContext?.element
  if (related && related.type === 'group' && dragged.type !== 'group') {
    return false
  }
  return canDropInto(null, evt)
}

const groupSortables = new Map<number, Sortable>()

function readOrderedIds(container: HTMLElement): number[] {
  return Array.from(container.children)
    .map((node) => Number((node as HTMLElement).getAttribute('data-block-id') || 0))
    .filter((n) => n > 0)
}

function registerGroupEl(groupId: number, el: HTMLElement | null) {
  const existing = groupSortables.get(groupId)
  if (!el) {
    if (existing) {
      existing.destroy()
      groupSortables.delete(groupId)
    }
    return
  }
  if (existing) return
  const s = Sortable.create(el, {
    group: { name: 'dash', pull: true, put: true },
    handle: '.grip',
    animation: 150,
    swapThreshold: 0.6,
    invertSwap: true,
    emptyInsertThreshold: 40,
    ghostClass: 'dash-ghost',
    chosenClass: 'dash-chosen',
    onStart: () => { dragging.value = true },
    onEnd: () => { dragging.value = false },
    onAdd: async (evt: SortableEvent) => {
      const id = Number(evt.item.getAttribute('data-block-id') || 0)
      if (!id) return
      try {
        await api.updateBlock(id, { parent_id: groupId })
        await api.reorder(readOrderedIds(el))
        await refresh()
      } catch (e) {
        console.error('drag-in failed', e)
        await refresh()
      }
    },
    onRemove: async (evt: SortableEvent) => {
      const id = Number(evt.item.getAttribute('data-block-id') || 0)
      if (!id) return
      const target = evt.to as HTMLElement | null
      const goesToTopLevel = !!target && target.classList.contains('dash-grid')
      const goesToOtherGroup = !!target && target.classList.contains('dash-group__children') && target !== el
      if (!goesToTopLevel && !goesToOtherGroup) {
        await refresh()
        return
      }
      if (goesToOtherGroup) return
      try {
        await api.updateBlock(id, { parent_id: null })
        await api.reorder(readOrderedIds(target as HTMLElement))
        await refresh()
      } catch (e) {
        console.error('drag-out failed', e)
        await refresh()
      }
    },
    onUpdate: async () => {
      try {
        await api.reorder(readOrderedIds(el))
        await refresh()
      } catch (e) {
        console.error('group reorder failed', e)
        await refresh()
      }
    },
  })
  groupSortables.set(groupId, s)
}

onBeforeUnmount(() => {
  for (const s of groupSortables.values()) s.destroy()
  groupSortables.clear()
})

/**
 * Class object for the per-item <article>. We use a single root element
 * for both group and regular tiles because vuedraggable's #item slot
 * MUST resolve to a stable DOM node so it can attach the
 * `__draggable_context` (drag-source metadata). With a v-if/v-else
 * fragment, the resolved `node.el` was undefined on some renders and
 * the cross-list `onAdd` handler bailed out before persisting — the
 * exact "drag adds to UI but doesn't save" bug we hit.
 */
function tileClassFor(b: Block): Record<string, boolean> {
  const isGroup = b.type === 'group'
  const isFull = placement.value[b.id] === 'full'
  const isOrphan = placement.value[b.id] === 'orphan'
  return {
    'dash-group': isGroup,
    'dash-group--dragging': isGroup && dragging.value,
    'cursor-pointer': !isGroup,
    'hover:border-ink-100/40': !isGroup,
    'transition': !isGroup,
    'dash-tile--no-bg': !isGroup && isNoBg(b),
    'opacity-60': !b.enabled,
    'dash-tile--full': isFull,
    'dash-tile--orphan': !isGroup && isOrphan,
    'dash-tile--half': !isFull && !(isOrphan && !isGroup),
  }
}

/**
 * Click on a top-level tile. Groups don't navigate (their visible
 * surface is "container chrome", not an edit target) — the user
 * interacts with the children inside. Other tiles open the edit view.
 */
function onTileClick(b: Block) {
  if (b.type === 'group') return
  router.push({ name: 'edit-block', params: { id: b.id } })
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
  // Group delete: server applied ON DELETE SET NULL, so the children
  // (if any) are now top-level. Re-fetch instead of just splicing the
  // group so the buckets reflect the detached kids.
  if (b.type === 'group') {
    await refresh()
  } else {
    blocks.value = blocks.value.filter((x) => x.id !== b.id)
    syncBuckets()
  }
}

async function add(type: BlockKind) {
  const parentId = addInsideGroup.value
  // Server rejects nested groups (422). Guard here too so the user
  // sees nothing weird if they somehow click `Group` from a `+` that
  // was opened inside another group's card.
  if (parentId !== null && type === 'group') {
    showAdd.value = false
    addInsideGroup.value = null
    return
  }
  const r = await api.createBlock(type, undefined, { parent_id: parentId ?? undefined })
  blocks.value.push(r.block)
  syncBuckets()
  showAdd.value = false
  addInsideGroup.value = null
  // Groups have no own fields → no point opening the edit page, just
  // stay on the dashboard so the user can drop tiles into the new group.
  if (type !== 'group') {
    router.push({ name: 'edit-block', params: { id: r.block.id } })
  }
}

function openAddInsideGroup(groupId: number) {
  addInsideGroup.value = groupId
  showAdd.value = true
}

function openAddTopLevel() {
  addInsideGroup.value = null
  showAdd.value = true
}

const movableTopLevelTiles = computed<Block[]>(() =>
  blocks.value.filter((b) => !b.parent_id && b.type !== 'group' && b.type !== 'footer'),
)

function openMoveExistingToGroup(groupId: number) {
  moveExistingTargetGroup.value = groupId
  showMoveExisting.value = true
}

function closeMoveExisting() {
  showMoveExisting.value = false
  moveExistingTargetGroup.value = null
}

async function pickMoveExisting(blockId: number) {
  const groupId = moveExistingTargetGroup.value
  closeMoveExisting()
  if (!groupId) return
  try {
    await api.updateBlock(blockId, { parent_id: groupId })
    await refresh()
  } catch (e) {
    console.error('[dash] move existing into group FAILED', e)
    await refresh()
  }
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
    case 'quote':
      return (b.data.text || b.data.title || ty.description).slice(0, 60).replace(/\n/g, ' ')
    case 'stats':
      return b.data.items?.length ? t('dashboard.countStats', { n: b.data.items.length }) : ty.description
    case 'cta':
      return b.data.title || b.data.button_label || ty.description
    case 'faq':
      return b.data.items?.length ? t('dashboard.countFaq', { n: b.data.items.length }) : ty.description
    case 'timeline':
      return b.data.items?.length ? t('dashboard.countTimeline', { n: b.data.items.length }) : ty.description
    case 'divider':
    case 'footer':
    case 'group':
      // Groups don't carry their own summary text — they're pure
      // layout primitives. The card surfaces its children instead.
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
      <button class="btn btn-primary" @click="openAddTopLevel">
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
    <button class="btn btn-primary mt-4 inline-flex" @click="openAddTopLevel">
      <iconify-icon icon="lucide:plus" width="18"></iconify-icon>
      {{ t('dashboard.addFirstTile') }}
    </button>
  </div>

  <!-- Top-level mosaic. `:group="'dash'"` lets tiles be dragged between
       the top level and any inner group's stack (and vice versa). The
       `:move` predicate rejects illegal drops (group-in-group, footer-in-group). -->
  <draggable
    v-else
    v-model="topLevel"
    :group="'dash'"
    item-key="id"
    handle=".grip"
    class="dash-grid"
    :swap-threshold="0.6"
    :invert-swap="true"
    :animation="150"
    ghost-class="dash-ghost"
    chosen-class="dash-chosen"
    :move="onTopLevelMove"
    @start="dragging = true"
    @end="dragging = false"
    @update="onTopLevelUpdate"
  >
    <template #item="{ element: b }">
      <!-- SINGLE-ROOT <article> for every top-level item. The branching
           between "group container" and "regular tile" happens INSIDE,
           never via v-if at the root — vuedraggable needs a stable DOM
           node per slot entry to attach drag-source metadata. -->
      <article
        class="tile group relative"
        :class="tileClassFor(b)"
        :data-block-id="b.id"
        @click="onTileClick(b)"
      >
        <!-- ============ GROUP container ============ -->
        <template v-if="b.type === 'group'">
        <div class="dash-group__header">
          <button
            class="grip btn-icon !w-8 !h-8 !cursor-grab active:!cursor-grabbing"
            :aria-label="t('dashboard.dragHandle')"
            :title="t('dashboard.dragHandle')"
            @click.stop
          >
            <iconify-icon icon="lucide:grip-vertical" width="18" class="text-ink-300"></iconify-icon>
          </button>
          <iconify-icon
            :icon="typeMap[b.type]?.icon || 'lucide:layers'"
            width="18"
            class="text-backend-accent flex-shrink-0"
          ></iconify-icon>
          <span class="text-[11px] uppercase tracking-widest text-ink-300 flex-1">{{
            typeMap[b.type]?.label || b.type
          }}</span>
          <span
            v-if="!b.enabled"
            class="text-[10px] uppercase tracking-widest text-ink-300 flex-shrink-0"
            >{{ t('dashboard.tileHidden') }}</span
          >
          <button
            class="btn-icon !w-8 !h-8 opacity-0 group-hover:opacity-100 transition"
            :title="b.enabled ? t('dashboard.hide') : t('dashboard.show')"
            @click.stop="toggleEnabled(b)"
          >
            <iconify-icon
              :icon="b.enabled ? 'lucide:eye-off' : 'lucide:eye'"
              width="16"
            ></iconify-icon>
          </button>
          <button
            class="btn-icon !w-8 !h-8 hover:!bg-red-500/20 hover:!text-red-200 opacity-0 group-hover:opacity-100 transition"
            :title="t('dashboard.delete')"
            @click.stop="remove(b)"
          >
            <iconify-icon icon="lucide:trash-2" width="16"></iconify-icon>
          </button>
        </div>
        <div
          :ref="(el) => registerGroupEl(b.id, el as HTMLElement | null)"
          class="dash-group__children"
        >
            <article
              v-for="child in childrenByParent[b.id] || []"
              :key="child.id"
              class="tile dash-group__child group/child relative cursor-pointer hover:border-ink-100/40 transition"
              :class="[{ 'opacity-60': !child.enabled }, { 'dash-tile--no-bg': isNoBg(child) }]"
              :data-block-id="child.id"
              @click="router.push({ name: 'edit-block', params: { id: child.id } })"
            >
              <div class="flex items-center gap-3 mb-2">
                <button
                  class="grip btn-icon !w-8 !h-8 !cursor-grab active:!cursor-grabbing"
                  :aria-label="t('dashboard.dragHandle')"
                  :title="t('dashboard.dragHandle')"
                  @click.stop
                >
                  <iconify-icon
                    icon="lucide:grip-vertical"
                    width="18"
                    class="text-ink-300"
                  ></iconify-icon>
                </button>
                <iconify-icon
                  :icon="typeMap[child.type]?.icon || 'lucide:square'"
                  width="20"
                  class="text-backend-accent flex-shrink-0"
                ></iconify-icon>
                <div class="flex-1 min-w-0">
                  <template v-if="blockTitle(child)">
                    <p
                      class="text-[10px] uppercase tracking-widest text-ink-300 leading-tight"
                    >
                      {{ typeMap[child.type]?.label || child.type }}
                    </p>
                    <h3 class="font-display text-base leading-tight truncate">
                      {{ blockTitle(child) }}
                    </h3>
                  </template>
                  <h3 v-else class="font-display text-base leading-tight truncate">
                    {{ typeMap[child.type]?.label || child.type }}
                  </h3>
                </div>
                <span
                  v-if="!child.enabled"
                  class="text-[10px] uppercase tracking-widest text-ink-300 ml-auto flex-shrink-0"
                  >{{ t('dashboard.tileHidden') }}</span
                >
              </div>
              <BlockPreview
                v-if="viewMode === 'preview'"
                :block="child"
                :type="typeMap[child.type]"
              />
              <p v-else class="text-sm text-ink-300 line-clamp-2">{{ blockSummary(child) }}</p>
              <div
                class="absolute top-3 right-3 flex gap-1 opacity-0 group-hover/child:opacity-100 transition"
              >
                <button
                  class="btn-icon !w-8 !h-8"
                  :title="child.enabled ? t('dashboard.hide') : t('dashboard.show')"
                  @click.stop="toggleEnabled(child)"
                >
                  <iconify-icon
                    :icon="child.enabled ? 'lucide:eye-off' : 'lucide:eye'"
                    width="16"
                  ></iconify-icon>
                </button>
                <button
                  class="btn-icon !w-8 !h-8 hover:!bg-red-500/20 hover:!text-red-200"
                  :title="t('dashboard.delete')"
                  @click.stop="remove(child)"
                >
                  <iconify-icon icon="lucide:trash-2" width="16"></iconify-icon>
                </button>
              </div>
            </article>
        </div>
        <div class="dash-group__actions">
          <button
            class="dash-group__add btn btn-ghost flex-1 justify-center"
            @click.stop="openMoveExistingToGroup(b.id)"
          >
            <iconify-icon icon="lucide:folder-input" width="16"></iconify-icon>
            {{ t('dashboard.addToGroup') }}
          </button>
          <button
            class="btn btn-primary flex-1 justify-center"
            @click.stop="openAddInsideGroup(b.id)"
          >
            <iconify-icon icon="lucide:plus" width="16"></iconify-icon>
            {{ t('dashboard.addTile') }}
          </button>
        </div>
        </template>

        <!-- ============ Regular tile (non-group) ============ -->
        <template v-else>
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
            class="text-backend-accent flex-shrink-0"
          ></iconify-icon>
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
        </template>
      </article>
    </template>
  </draggable>

  <AddBlockSheet v-if="showAdd" :types="types" @close="showAdd = false; addInsideGroup = null" @pick="add" />
  <MoveExistingToGroupSheet
    v-if="showMoveExisting"
    :tiles="movableTopLevelTiles"
    :types="types"
    @close="closeMoveExisting"
    @pick="pickMoveExisting"
  />
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

/* Group container card — visible ONLY in the dashboard so the user can
   see + manipulate the structure. On the public site groups render no
   chrome at all (zero margin/padding/border). Dashed outline + lighter
   bg distinguish a group from a regular tile. The card itself is still
   a grid item (half/full), so it sits alongside other top-level tiles. */
.dash-group {
  display: flex;
  flex-direction: column;
  gap: 10px;
  background: rgb(var(--ink-800-rgb) / 0.4);
  border: 1px dashed rgb(var(--ink-100-rgb) / 0.25);
  cursor: default; /* the card itself doesn't navigate on click — the children do */
}
.dash-group:hover {
  border-color: rgb(var(--ink-100-rgb) / 0.4);
}
.dash-group__header {
  display: flex;
  align-items: center;
  gap: 8px;
}
.dash-group__children {
  display: flex;
  flex-direction: column;
  gap: 10px;
  /* Drop-zone visual: a faint inner ring + a min height so an empty
     group still presents a target the user can drop a tile into. */
  min-height: 60px;
  padding: 2px;
  border-radius: 10px;
  transition: background 0.15s ease;
}
.dash-group__children:empty::before {
  content: '';
  flex: 1;
  min-height: 56px;
  border: 1px dashed rgb(var(--ink-100-rgb) / 0.15);
  border-radius: 10px;
  display: block;
}
.dash-group__child {
  /* Inside a group the child tile is rendered with the same chrome as
     a top-level tile, just no grid placement (stacked by the parent). */
  width: 100%;
}
.dash-group__add {
  /* Match the dashed-border feel of the container so it reads as a
     "drop zone augmentation" rather than a primary action. */
  border: 1px dashed rgb(var(--ink-100-rgb) / 0.2);
  background: transparent;
}
.dash-group__add:hover {
  border-color: rgb(var(--ink-100-rgb) / 0.4);
  background: rgb(var(--ink-100-rgb) / 0.04);
}
.dash-group__actions {
  display: flex;
  gap: 8px;
  align-items: stretch;
}
@media (max-width: 480px) {
  .dash-group__actions {
    flex-direction: column;
  }
}

/* While ANY drag is in flight, every group's drop-zone visually
   "lights up" so the user sees where they can drop. This counters the
   default sortable feel where the group's chrome looks identical to
   every other tile and the user has no signal that THIS is a container.
   Combined with the `:move` veto on top-level swaps over groups, the
   group also stays put as the user approaches it instead of dodging. */
.dash-group--dragging {
  border-color: rgb(var(--backend-accent-rgb) / 0.5);
}
.dash-group--dragging .dash-group__children {
  background: rgb(var(--backend-accent-rgb) / 0.06);
  outline: 2px dashed rgb(var(--backend-accent-rgb) / 0.4);
  outline-offset: -2px;
}
.dash-group--dragging .dash-group__children:empty::before {
  border-color: rgb(var(--backend-accent-rgb) / 0.5);
}

/* Sortable's "chosen" + "ghost" tiles. ghost = the placeholder slot in
   the source list while dragging; chosen = the original element under
   the cursor (the one the user is actually moving). */
.dash-ghost {
  opacity: 0.4;
  background: rgb(var(--backend-accent-rgb) / 0.1);
  border: 2px dashed rgb(var(--backend-accent-rgb));
}
.dash-chosen {
  cursor: grabbing !important;
}
</style>
