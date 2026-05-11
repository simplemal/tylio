<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BlockKind, BlockType } from '../types'
import { useDialog } from '../composables/useDialog'

const { t } = useI18n()
const props = defineProps<{ types: BlockType[] }>()
const emit = defineEmits<{ close: []; pick: [BlockKind] }>()
const search = ref('')
const dialogRoot = ref<HTMLElement | null>(null)
const isOpen = ref(true) // mounted = open; parent v-if controls the component lifetime
useDialog({ isOpen, rootEl: dialogRoot, onClose: () => emit('close') })

const groups = computed(() => {
  const filtered = props.types.filter((t) =>
    !search.value
      ? true
      : (t.label + ' ' + t.description + ' ' + t.id)
          .toLowerCase()
          .includes(search.value.toLowerCase()),
  )
  const out: Record<string, BlockType[]> = {}
  for (const t of filtered) {
    out[t.category] = out[t.category] || []
    out[t.category].push(t)
  }
  return out
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
      aria-labelledby="add-block-title"
      tabindex="-1"
      class="bg-ink-900 border border-white/5 rounded-tile w-full max-w-2xl max-h-[80vh] flex flex-col overflow-hidden"
    >
      <header class="px-5 py-4 border-b border-ink-100/10 flex items-center gap-3">
        <iconify-icon icon="lucide:plus-square" width="22" class="text-ink-100"></iconify-icon>
        <h2 id="add-block-title" class="font-display text-xl">{{ t('addBlock.title') }}</h2>
        <button class="btn-icon ml-auto" :aria-label="t('addBlock.close')" @click="emit('close')">
          <iconify-icon icon="lucide:x" width="18"></iconify-icon>
        </button>
      </header>
      <div class="px-5 py-3 border-b border-ink-100/10">
        <input v-model="search" :placeholder="t('addBlock.searchPlaceholder')" />
      </div>
      <!-- "Add tile" modal: uses ONLY ink-100 (card title) and ink-300
           (descriptions). No accent: the modal must be readable in any
           palette, even extreme ones like Pink Lady · light where
           accent=surface=#fff. -->
      <div class="flex-1 overflow-y-auto p-4 space-y-5">
        <section v-for="(items, cat) in groups" :key="cat">
          <p class="eyebrow mb-2">{{ cat }}</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <button
              v-for="t in items"
              :key="t.id"
              class="text-left p-4 rounded-xl border border-ink-100/10 bg-ink-800 hover:bg-ink-700 hover:border-ink-100/25 transition"
              @click="emit('pick', t.id)"
            >
              <div class="flex items-center gap-3">
                <iconify-icon :icon="t.icon" width="22" class="text-ink-100"></iconify-icon>
                <div>
                  <div class="font-medium">{{ t.label }}</div>
                  <div class="text-xs text-ink-300 mt-0.5">{{ t.description }}</div>
                </div>
              </div>
            </button>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>