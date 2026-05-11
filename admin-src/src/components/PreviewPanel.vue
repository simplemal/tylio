<script setup lang="ts">
import { ref } from 'vue'
import { useDialog } from '../composables/useDialog'

const emit = defineEmits<{ close: [] }>()
const device = ref<'mobile' | 'desktop'>('mobile')
const cacheBust = ref(Date.now())
const dialogRoot = ref<HTMLElement | null>(null)
const isOpen = ref(true)
useDialog({ isOpen, rootEl: dialogRoot, onClose: () => emit('close') })
function reload() {
  cacheBust.value = Date.now()
}
</script>

<template>
  <div
    ref="dialogRoot"
    role="dialog"
    aria-modal="true"
    aria-labelledby="preview-title"
    tabindex="-1"
    class="fixed inset-0 z-50 bg-ink-950/90 backdrop-blur-sm flex flex-col"
  >
    <header class="flex items-center gap-3 p-4 border-b border-white/5">
      <h2 id="preview-title" class="font-display text-xl">Anteprima</h2>
      <div class="ml-auto flex items-center gap-2">
        <div class="flex bg-ink-800 rounded-full p-1 border border-white/5">
          <button
            type="button"
            class="px-3 py-1.5 rounded-full text-sm flex items-center gap-1.5"
            :class="device === 'mobile' ? 'bg-ink-100 text-ink-900' : 'text-ink-300'"
            @click="device = 'mobile'"
          >
            <iconify-icon icon="lucide:smartphone" width="16"></iconify-icon> Mobile
          </button>
          <button
            type="button"
            class="px-3 py-1.5 rounded-full text-sm flex items-center gap-1.5"
            :class="device === 'desktop' ? 'bg-ink-100 text-ink-900' : 'text-ink-300'"
            @click="device = 'desktop'"
          >
            <iconify-icon icon="lucide:monitor" width="16"></iconify-icon> Desktop
          </button>
        </div>
        <button type="button" class="btn-icon" title="Ricarica" @click="reload">
          <iconify-icon icon="lucide:refresh-cw" width="18"></iconify-icon>
        </button>
        <button type="button" class="btn-icon" aria-label="Chiudi" @click="emit('close')">
          <iconify-icon icon="lucide:x" width="18"></iconify-icon>
        </button>
      </div>
    </header>
    <div class="flex-1 grid place-items-center p-4 overflow-auto">
      <!-- Preview container: bg ink-950 (= user page bg, theme-adaptive)
           instead of hardcoded bg-black which on a light theme produced a
           black frame + black scrollbar of the parent iframe. Border
           ink-100/10 likewise. -->
      <div
        class="bg-ink-950 rounded-2xl shadow-2xl overflow-hidden border border-ink-100/10"
        :style="
          device === 'mobile'
            ? { width: '390px', height: '780px', maxHeight: '85vh' }
            : { width: 'min(1200px, 100%)', height: '85vh' }
        "
      >
        <iframe
          :src="`/api/preview?t=${cacheBust}`"
          class="w-full h-full border-0"
          title="Anteprima sito"
          loading="lazy"
        />
      </div>
    </div>
  </div>
</template>