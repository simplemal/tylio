<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../api'
import { ApiError } from '../types'
import type { MediaItem } from '../types'
import MediaLibraryDialog from './MediaLibraryDialog.vue'
import { useConfirm } from '../composables/useConfirm'

const { t } = useI18n()
const { confirm } = useConfirm()

const props = defineProps<{
  modelValue: string
  aspect?: 'og' | 'square' | 'wide' // default: og
  placeholder?: string
}>()
const emit = defineEmits<{ 'update:modelValue': [string] }>()

const fileInput = ref<HTMLInputElement | null>(null)
const dragOver = ref(false)
const busy = ref(false)
const optimizing = ref(false)
const error = ref('')
const showLibrary = ref(false)
const sizeBytes = ref<number | null>(null)

const SOCIAL_SIZE_LIMIT = 600 * 1024

const hasImage = computed(() => !!props.modelValue && props.modelValue !== '')
const isOgAspect = computed(() => (props.aspect ?? 'og') === 'og')
const oversized = computed(() => sizeBytes.value !== null && sizeBytes.value > SOCIAL_SIZE_LIMIT)
const sizeLabel = computed(() => {
  if (sizeBytes.value === null) return ''
  const kb = sizeBytes.value / 1024
  if (kb < 1024) return `${kb.toFixed(0)} KB`
  return `${(kb / 1024).toFixed(2)} MB`
})

async function refreshSize() {
  if (!hasImage.value) {
    sizeBytes.value = null
    return
  }
  try {
    const r = await fetch(props.modelValue, { method: 'HEAD', cache: 'no-store' })
    const len = r.headers.get('Content-Length')
    sizeBytes.value = len ? parseInt(len, 10) : null
  } catch {
    sizeBytes.value = null
  }
}

watch(
  () => props.modelValue,
  () => refreshSize(),
  { immediate: true },
)

async function uploadFile(file: File) {
  if (!file) return
  if (!file.type.startsWith('image/')) {
    error.value = t('media.errors.notImage')
    return
  }
  busy.value = true
  error.value = ''
  try {
    const r = await api.uploadMedia(file, isOgAspect.value ? 'og' : undefined)
    emit('update:modelValue', r.media.url)
  } catch (e: unknown) {
    // Prefer data.message (human-readable from the server) over
    // e.message (which is often just the error key like "upload_error").
    if (e instanceof ApiError && typeof e.data.message === 'string') {
      error.value = e.data.message
    } else if (e instanceof Error) {
      error.value = e.message
    } else {
      error.value = t('media.errors.uploadFailed')
    }
  } finally {
    busy.value = false
  }
}

function pickFromLibrary(m: MediaItem) {
  showLibrary.value = false
  emit('update:modelValue', m.url)
}

async function clear() {
  if (
    !(await confirm({
      message: t('media.removeImageConfirm'),
      confirmLabel: t('common.remove'),
    }))
  )
    return
  emit('update:modelValue', '')
}

function onPick(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0]
  if (f) uploadFile(f)
}

function onDrop(e: DragEvent) {
  dragOver.value = false
  const f = e.dataTransfer?.files?.[0]
  if (f) uploadFile(f)
}

async function optimizeExisting() {
  optimizing.value = true
  error.value = ''
  try {
    const r = await api.optimizeOgImage(props.modelValue)
    if (r.url !== props.modelValue) {
      emit('update:modelValue', r.url)
    } else {
      sizeBytes.value = r.bytes
    }
  } catch (e: unknown) {
    if (e instanceof ApiError && typeof e.data.message === 'string') {
      error.value = e.data.message
    } else if (e instanceof Error) {
      error.value = e.message
    } else {
      error.value = t('media.errors.uploadFailed')
    }
  } finally {
    optimizing.value = false
  }
}
</script>

<template>
  <div class="img-uploader">
    <!-- Drop / preview with configurable aspect -->
    <div
      class="img-uploader__drop"
      :class="[
        aspect === 'square'
          ? 'img-uploader__drop--square'
          : aspect === 'wide'
            ? 'img-uploader__drop--wide'
            : 'img-uploader__drop--og',
        { 'is-dragover': dragOver },
      ]"
      @click="!hasImage && fileInput?.click()"
      @dragover.prevent="dragOver = true"
      @dragleave="dragOver = false"
      @drop.prevent="onDrop"
    >
      <img
        v-if="hasImage"
        :src="modelValue"
        alt=""
        class="img-uploader__preview img-uploader__preview--contain"
      />
      <div v-else class="img-uploader__placeholder">
        <iconify-icon icon="lucide:image" width="36"></iconify-icon>
        <span>{{ placeholder || t('media.dropPlaceholder') }}</span>
      </div>
      <span v-if="busy" class="img-uploader__hint">
        <iconify-icon icon="lucide:loader-circle" width="14" class="animate-spin"></iconify-icon>
        {{ t('media.uploading') }}
      </span>
    </div>

    <div class="img-uploader__actions">
      <button type="button" class="btn btn-ghost" :disabled="busy" @click="showLibrary = true">
        <iconify-icon icon="lucide:images" width="16"></iconify-icon>
        {{ t('media.pickFromLibrary') }}
      </button>
      <button type="button" class="btn btn-ghost" :disabled="busy" @click="fileInput?.click()">
        <iconify-icon icon="lucide:upload" width="16"></iconify-icon>
        {{ t('media.upload') }}
      </button>
      <button v-if="hasImage" type="button" class="btn btn-ghost" :disabled="busy" @click="clear">
        <iconify-icon icon="lucide:trash-2" width="16"></iconify-icon>
        {{ t('common.remove') }}
      </button>
    </div>

    <p v-if="error" class="text-xs text-red-300 mt-2">{{ error }}</p>
    <p v-if="hasImage" class="text-xs text-ink-300 mt-2">
      URL: <code class="text-ink-100">{{ modelValue }}</code>
      <span v-if="sizeLabel"> · {{ sizeLabel }}</span>
    </p>
    <div
      v-if="hasImage && isOgAspect && oversized"
      class="img-uploader__oversized"
    >
      <iconify-icon icon="lucide:triangle-alert" width="16"></iconify-icon>
      <div class="img-uploader__oversized-body">
        <strong>{{ t('media.ogOversized.title', { size: sizeLabel }) }}</strong>
        <span>{{ t('media.ogOversized.message') }}</span>
      </div>
      <button
        type="button"
        class="btn btn-primary"
        :disabled="optimizing"
        @click="optimizeExisting"
      >
        <iconify-icon
          :icon="optimizing ? 'lucide:loader-circle' : 'lucide:wand-sparkles'"
          width="16"
          :class="optimizing ? 'animate-spin' : ''"
        ></iconify-icon>
        {{ optimizing ? t('media.ogOversized.optimizing') : t('media.ogOversized.optimize') }}
      </button>
    </div>

    <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onPick" />

    <MediaLibraryDialog v-if="showLibrary" @close="showLibrary = false" @pick="pickFromLibrary" />
  </div>
</template>