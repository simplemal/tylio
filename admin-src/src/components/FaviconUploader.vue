<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../api'
import { ApiError, type MediaItem } from '../types'
import MediaLibraryDialog from './MediaLibraryDialog.vue'
import { useConfirm } from '../composables/useConfirm'

const { t } = useI18n()
const { confirm } = useConfirm()

const props = defineProps<{ modelValue: string }>()
const emit = defineEmits<{ 'update:modelValue': [string] }>()

const fileInput = ref<HTMLInputElement | null>(null)
const dragOver = ref(false)
const busy = ref(false)
const error = ref('')
const showLibrary = ref(false)

const hasFavicon = computed(() => !!props.modelValue && props.modelValue !== '')
// Tenant slug from the subdomain — on platform, favicons live in
// /favicons/<slug>/icon-X.png (multi-tenant). On standalone OSS (custom
// domain, not *.tylio.app) we fall back to /favicons/icon-X.png. Same
// logic as AppShell.vue to identify the tenant client-side.
const tenantSlug = computed<string>(() => {
  const host = (typeof window !== 'undefined' ? window.location.hostname : '').toLowerCase()
  const m = host.match(/^([a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?)\.tylio\.app$/)
  return m ? m[1] : ''
})
const previewUrl = computed(() => {
  if (!hasFavicon.value) return ''
  const base = tenantSlug.value ? `/favicons/${tenantSlug.value}` : '/favicons'
  return `${base}/icon-180.png?v=${encodeURIComponent(props.modelValue)}`
})

async function uploadFile(file: File) {
  if (!file) return
  if (!file.type.startsWith('image/')) {
    error.value = t('faviconUploader.imageRequired')
    return
  }
  busy.value = true
  error.value = ''
  try {
    const r = await api.uploadFavicon(file)
    emit('update:modelValue', r.version)
  } catch (e: unknown) {
    error.value = errorMessage(e, t('common.uploadError'))
  } finally {
    busy.value = false
  }
}

async function pickFromLibrary(m: MediaItem) {
  showLibrary.value = false
  busy.value = true
  error.value = ''
  try {
    const r = await api.faviconFromMedia(m.id)
    emit('update:modelValue', r.version)
  } catch (e: unknown) {
    error.value = errorMessage(e, t('faviconUploader.uploadErrorFromMedia'))
  } finally {
    busy.value = false
  }
}

function errorMessage(e: unknown, fallback: string): string {
  // Server-side: FaviconController ritorna { ok:false, error: <code>,
  // detail: <human readable> }. Mostriamo `detail`, fallback su
  // `message` legacy + fallback locale.
  if (e instanceof ApiError) {
    const d = e.data?.detail
    if (typeof d === 'string' && d) return d
    const m = e.data?.message
    if (typeof m === 'string' && m) return m
    return fallback
  }
  if (e instanceof Error) return e.message
  return fallback
}

async function remove() {
  if (
    !(await confirm({
      message: t('faviconUploader.removeConfirm'),
      confirmLabel: t('faviconUploader.removeAction'),
      destructive: true,
    }))
  )
    return
  await api.deleteFavicon()
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
</script>

<template>
  <div class="img-uploader">
    <!-- Drop / preview -->
    <div
      class="img-uploader__drop"
      :class="{ 'is-dragover': dragOver }"
      @click="!hasFavicon && fileInput?.click()"
      @dragover.prevent="dragOver = true"
      @dragleave="dragOver = false"
      @drop.prevent="onDrop"
    >
      <img
        v-if="hasFavicon"
        :src="previewUrl"
        alt="Favicon"
        class="img-uploader__preview img-uploader__preview--square"
      />
      <div v-else class="img-uploader__placeholder">
        <iconify-icon icon="lucide:app-window" width="36"></iconify-icon>
        <span>{{ t('faviconUploader.dragSquareHere') }}</span>
      </div>
      <span v-if="busy" class="img-uploader__hint">
        <iconify-icon icon="lucide:loader-circle" width="14" class="animate-spin"></iconify-icon>
        {{ t('faviconUploader.generatingIcons') }}
      </span>
    </div>

    <!-- Actions -->
    <div class="img-uploader__actions">
      <button type="button" class="btn btn-ghost" :disabled="busy" @click="showLibrary = true">
        <iconify-icon icon="lucide:images" width="16"></iconify-icon>
        {{ t('faviconUploader.libraryButton') }}
      </button>
      <button type="button" class="btn btn-ghost" :disabled="busy" @click="fileInput?.click()">
        <iconify-icon icon="lucide:upload" width="16"></iconify-icon>
        {{ t('common.upload') }}
      </button>
      <button
        v-if="hasFavicon"
        type="button"
        class="btn btn-ghost"
        :disabled="busy"
        @click="remove"
      >
        <iconify-icon icon="lucide:trash-2" width="16"></iconify-icon>
        {{ t('faviconUploader.removeAction') }}
      </button>
    </div>

    <p v-if="error" class="text-xs text-red-300 mt-2">{{ error }}</p>
    <p v-if="hasFavicon" class="text-xs text-ink-300 mt-2">
      <i18n-t keypath="faviconUploader.faviconActiveHint" tag="span">
        <template #path><code class="text-ink-100">/favicons/</code></template>
      </i18n-t>
    </p>

    <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onPick" />

    <MediaLibraryDialog v-if="showLibrary" @close="showLibrary = false" @pick="pickFromLibrary" />
  </div>
</template>