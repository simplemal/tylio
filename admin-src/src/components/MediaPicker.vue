<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../api'
import type { MediaItem } from '../types'
import { useConfirm } from '../composables/useConfirm'
import { useDialog } from '../composables/useDialog'

const { t } = useI18n()
const { confirm } = useConfirm()
const dialogRoot = ref<HTMLElement | null>(null)

const props = defineProps<{ modelValue: string }>()
const emit = defineEmits<{ 'update:modelValue': [string] }>()

const showPicker = ref(false)
const items = ref<MediaItem[]>([])
const loading = ref(false)
const dragOver = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

useDialog({ isOpen: showPicker, rootEl: dialogRoot, onClose: () => (showPicker.value = false) })

async function open() {
  showPicker.value = true
  if (items.value.length === 0) await refresh()
}
async function refresh() {
  loading.value = true
  try {
    items.value = (await api.listMedia()).media
  } finally {
    loading.value = false
  }
}

async function uploadFiles(files: FileList | null) {
  if (!files || !files.length) return
  loading.value = true
  try {
    for (const f of Array.from(files)) {
      const r = await api.uploadMedia(f)
      items.value.unshift(r.media)
    }
  } finally {
    loading.value = false
  }
}

function pick(m: MediaItem) {
  emit('update:modelValue', m.url)
  showPicker.value = false
}

async function remove(m: MediaItem) {
  if (
    !(await confirm({
      message: t('media.deleteNamedConfirm', { name: m.original_name }),
      confirmLabel: t('common.delete'),
      destructive: true,
    }))
  )
    return
  await api.deleteMedia(m.id)
  items.value = items.value.filter((x) => x.id !== m.id)
  if (props.modelValue === m.url) emit('update:modelValue', '')
}
</script>

<template>
  <div>
    <div class="flex gap-2">
      <div class="flex-1 flex items-center gap-3 bg-ink-800 border border-white/5 rounded-xl p-2">
        <div
          class="w-12 h-12 rounded-lg bg-ink-900 grid place-items-center overflow-hidden border border-white/5"
        >
          <img v-if="modelValue" :src="modelValue" class="w-full h-full object-cover" alt="" />
          <iconify-icon
            v-else
            icon="lucide:image"
            width="22"
            class="text-ink-300"
          ></iconify-icon>
        </div>
        <input
          type="text"
          :value="modelValue"
          :placeholder="t('media.urlOrLibrary')"
          class="!bg-transparent !border-0 !p-0 flex-1"
          @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        />
      </div>
      <button type="button" class="btn btn-ghost" @click="open">
        <iconify-icon icon="lucide:images" width="18"></iconify-icon>
        {{ t('common.library') }}
      </button>
      <button
        v-if="modelValue"
        type="button"
        class="btn-icon"
        :title="t('mediaPicker.remove')"
        @click="emit('update:modelValue', '')"
      >
        <iconify-icon icon="lucide:x" width="16"></iconify-icon>
      </button>
    </div>

    <Teleport to="body">
      <div
        v-if="showPicker"
        class="fixed inset-0 z-50 bg-ink-950/85 backdrop-blur-sm flex items-end md:items-center justify-center p-4"
        @click.self="showPicker = false"
      >
        <div
          ref="dialogRoot"
          role="dialog"
          aria-modal="true"
          aria-labelledby="media-picker-title"
          tabindex="-1"
          class="bg-ink-900 border border-white/5 rounded-tile w-full max-w-3xl max-h-[80vh] flex flex-col overflow-hidden"
        >
          <header class="px-5 py-4 border-b border-white/5 flex items-center gap-3">
            <iconify-icon icon="lucide:images" width="22" class="text-ink-100"></iconify-icon>
            <h2 id="media-picker-title" class="font-display text-xl">{{ t('media.titleLong') }}</h2>
            <button
              type="button"
              class="btn-icon ml-auto"
              :aria-label="t('common.close')"
              @click="showPicker = false"
            >
              <iconify-icon icon="lucide:x" width="18"></iconify-icon>
            </button>
          </header>

          <div
            class="m-4 border-2 border-dashed rounded-xl p-6 text-center transition cursor-pointer bg-ink-100/5"
            :class="
              dragOver ? 'border-ink-100 bg-ink-100/10' : 'border-ink-300/50 hover:border-ink-100'
            "
            @dragover.prevent="dragOver = true"
            @dragleave="dragOver = false"
            @drop.prevent="
              (e) => {
                dragOver = false
                uploadFiles(e.dataTransfer?.files || null)
              }
            "
            @click="fileInput?.click()"
          >
            <iconify-icon
              icon="lucide:cloud-upload"
              width="36"
              class="text-ink-100 mx-auto"
            ></iconify-icon>
            <p class="mt-2 text-sm text-ink-100">{{ t('media.dragOrClick') }}</p>
            <p class="text-xs text-ink-300 mt-1">JPG, PNG, WebP, GIF, SVG</p>
            <input
              ref="fileInput"
              type="file"
              accept="image/*"
              multiple
              class="hidden"
              @change="uploadFiles(($event.target as HTMLInputElement).files)"
            />
          </div>

          <div class="flex-1 overflow-y-auto px-4 pb-4">
            <div v-if="loading" class="text-center text-ink-300 py-8">{{ t('media.loading') }}</div>
            <div v-else-if="items.length === 0" class="text-center text-ink-300 py-8">
              {{ t('media.emptyFiles') }}
            </div>
            <div v-else class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 gap-2">
              <div v-for="m in items" :key="m.id" class="relative group">
                <button
                  type="button"
                  class="block w-full aspect-square rounded-lg overflow-hidden bg-ink-800 border border-white/5 hover:border-ink-100 transition"
                  @click="pick(m)"
                >
                  <img
                    :src="m.url"
                    :alt="m.original_name"
                    class="w-full h-full object-cover"
                    loading="lazy"
                  />
                </button>
                <button
                  type="button"
                  class="absolute top-1 right-1 btn-icon !w-7 !h-7 opacity-0 group-hover:opacity-100 hover:!text-red-300 hover:!bg-red-500/20"
                  @click="remove(m)"
                >
                  <iconify-icon icon="lucide:trash-2" width="13"></iconify-icon>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>