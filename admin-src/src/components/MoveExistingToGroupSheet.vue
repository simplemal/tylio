<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Block, BlockType } from '../types'
import { useDialog } from '../composables/useDialog'

const { t } = useI18n()
const props = defineProps<{ tiles: Block[]; types: BlockType[] }>()
const emit = defineEmits<{ close: []; pick: [number] }>()
const search = ref('')
const dialogRoot = ref<HTMLElement | null>(null)
const isOpen = ref(true)
useDialog({ isOpen, rootEl: dialogRoot, onClose: () => emit('close') })

const typeMap = computed(() => Object.fromEntries(props.types.map((ty) => [ty.id, ty])))

function tileLabel(b: Block): string {
  const ty = typeMap.value[b.type]
  const raw = (b.data as Record<string, unknown>).title
  const title = typeof raw === 'string' ? raw.trim() : ''
  return title || ty?.label || b.type
}

function tileTypeLabel(b: Block): string {
  return typeMap.value[b.type]?.label || b.type
}

function tileIcon(b: Block): string {
  return typeMap.value[b.type]?.icon || 'lucide:square'
}

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.tiles
  return props.tiles.filter((b) => {
    const hay = `${tileLabel(b)} ${tileTypeLabel(b)} ${b.type}`.toLowerCase()
    return hay.includes(q)
  })
})
</script>

<template>
  <div
    class="fixed inset-0 z-50 bg-ink-950/85 backdrop-blur-sm flex items-end md:items-center justify-center p-4"
    @click.self="emit('close')"
  >
    <div
      ref="dialogRoot"
      role="dialog"
      aria-modal="true"
      aria-labelledby="move-existing-title"
      tabindex="-1"
      class="bg-ink-900 border border-white/5 rounded-tile w-full max-w-2xl max-h-[80vh] flex flex-col overflow-hidden"
    >
      <header class="px-5 py-4 border-b border-ink-100/10 flex items-center gap-3">
        <iconify-icon icon="lucide:folder-input" width="22" class="text-ink-100"></iconify-icon>
        <h2 id="move-existing-title" class="font-display text-xl">{{ t('moveExistingSheet.title') }}</h2>
        <button class="btn-icon ml-auto" :aria-label="t('moveExistingSheet.close')" @click="emit('close')">
          <iconify-icon icon="lucide:x" width="18"></iconify-icon>
        </button>
      </header>
      <div v-if="tiles.length > 0" class="px-5 py-3 border-b border-ink-100/10">
        <input v-model="search" :placeholder="t('moveExistingSheet.searchPlaceholder')" />
      </div>
      <div class="flex-1 overflow-y-auto p-4 space-y-2">
        <div v-if="tiles.length === 0" class="text-center py-10 text-ink-300">
          {{ t('moveExistingSheet.empty') }}
        </div>
        <div v-else-if="filtered.length === 0" class="text-center py-10 text-ink-300">
          {{ t('moveExistingSheet.noResults') }}
        </div>
        <button
          v-for="b in filtered"
          :key="b.id"
          class="w-full text-left p-4 rounded-xl border border-ink-100/10 bg-ink-800 hover:bg-ink-700 hover:border-ink-100/25 transition flex items-center gap-3"
          @click="emit('pick', b.id)"
        >
          <iconify-icon :icon="tileIcon(b)" width="22" class="text-backend-accent flex-shrink-0"></iconify-icon>
          <div class="flex-1 min-w-0">
            <div class="font-medium truncate">{{ tileLabel(b) }}</div>
            <div class="text-xs text-ink-300 mt-0.5">{{ tileTypeLabel(b) }}</div>
          </div>
          <iconify-icon icon="lucide:chevron-right" width="18" class="text-ink-300 flex-shrink-0"></iconify-icon>
        </button>
      </div>
    </div>
  </div>
</template>
