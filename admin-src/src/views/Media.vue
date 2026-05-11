<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../api'
import type { MediaItem } from '../types'
import { useConfirm } from '../composables/useConfirm'

const { t } = useI18n()
const { confirm } = useConfirm()

const items = ref<MediaItem[]>([])
const loading = ref(false)
const dragOver = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

onMounted(refresh)

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

async function copy(url: string) {
  await navigator.clipboard.writeText(url)
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
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
      <p class="eyebrow">{{ t('media.eyebrow') }}</p>
      <h1 class="heading">{{ t('media.title') }}</h1>
    </div>
    <button class="btn btn-primary" @click="fileInput?.click()">
      <iconify-icon icon="lucide:upload" width="18"></iconify-icon>
      {{ t('media.upload') }}
    </button>
    <input
      ref="fileInput"
      type="file"
      accept="image/*"
      multiple
      class="hidden"
      @change="uploadFiles(($event.target as HTMLInputElement).files)"
    />
  </div>

  <div
    class="border-2 border-dashed rounded-tile p-8 text-center transition mb-6 bg-ink-100/5"
    :class="dragOver ? 'border-ink-100 bg-ink-100/10' : 'border-ink-300/50'"
    @dragover.prevent="dragOver = true"
    @dragleave="dragOver = false"
    @drop.prevent="
      (e) => {
        dragOver = false
        uploadFiles(e.dataTransfer?.files || null)
      }
    "
  >
    <iconify-icon
      icon="lucide:cloud-upload"
      width="48"
      class="text-ink-100 mx-auto"
    ></iconify-icon>
    <p class="mt-3">{{ t('media.dragHere') }}</p>
    <p class="text-xs text-ink-300 mt-1">{{ t('media.dragHint') }}</p>
  </div>

  <div v-if="loading" class="text-ink-300 text-center py-6">{{ t('media.loading') }}</div>
  <div v-else-if="items.length === 0" class="text-ink-300 text-center py-6">
    {{ t('media.emptyFiles') }}
  </div>
  <div v-else class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
    <article v-for="m in items" :key="m.id" class="tile !p-3 group relative">
      <div class="aspect-square rounded-lg overflow-hidden bg-ink-800 mb-2">
        <img
          :src="m.url"
          :alt="m.original_name"
          class="w-full h-full object-cover"
          loading="lazy"
        />
      </div>
      <p class="text-xs text-ink-100 truncate" :title="m.original_name">{{ m.original_name }}</p>
      <p class="text-[10px] text-ink-300 uppercase tracking-widest">
        {{ Math.round(m.size / 1024) }} KB
      </p>
      <div class="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition">
        <button class="btn-icon !w-7 !h-7" :title="t('media.copyUrl')" @click="copy(m.url)">
          <iconify-icon icon="lucide:copy" width="14"></iconify-icon>
        </button>
        <button
          class="btn-icon !w-7 !h-7 hover:!text-red-300 hover:!bg-red-500/20"
          :title="t('media.deleteFile')"
          @click="remove(m)"
        >
          <iconify-icon icon="lucide:trash-2" width="14"></iconify-icon>
        </button>
      </div>
    </article>
  </div>
</template>
