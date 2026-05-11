<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../api'

const { t } = useI18n()
defineProps<{ modelValue: string }>()
const emit = defineEmits<{ 'update:modelValue': [string] }>()

const dragOver = ref(false)
const uploading = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const error = ref('')

async function uploadFile(file: File) {
  if (!file) return
  if (!file.type.startsWith('image/')) {
    error.value = t('common.imageRequired')
    return
  }
  if (file.size > 10 * 1024 * 1024) {
    error.value = t('avatarPicker.sizeLimit')
    return
  }
  uploading.value = true
  error.value = ''
  try {
    const r = await api.uploadMedia(file)
    emit('update:modelValue', r.media.url)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : t('common.uploadError')
  } finally {
    uploading.value = false
  }
}

function onDrop(e: DragEvent) {
  dragOver.value = false
  const f = e.dataTransfer?.files?.[0]
  if (f) uploadFile(f)
}

function onPick(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0]
  if (f) uploadFile(f)
}

function clear() {
  emit('update:modelValue', '')
}
</script>

<template>
  <div class="flex flex-col sm:flex-row items-start gap-5">
    <!-- Circular preview with mask that darkens the clipped areas -->
    <div
      class="avatar-frame group relative shrink-0"
      :class="{ 'is-dragover': dragOver }"
      @click="fileInput?.click()"
      @dragover.prevent="dragOver = true"
      @dragleave="dragOver = false"
      @drop.prevent="onDrop"
    >
      <div class="avatar-frame__inner">
        <img v-if="modelValue" :src="modelValue" alt="Avatar" class="avatar-frame__img" />
        <div v-else class="avatar-frame__placeholder">
          <iconify-icon icon="lucide:circle-user" width="56"></iconify-icon>
        </div>
        <!-- dark overlay: opaque outside the inscribed circle, transparent inside -->
        <div class="avatar-frame__mask" aria-hidden="true"></div>
        <!-- circle border to make clear where it will be clipped -->
        <div class="avatar-frame__ring" aria-hidden="true"></div>
      </div>
      <div class="avatar-frame__hint">
        <iconify-icon
          v-if="uploading"
          icon="lucide:loader-circle"
          width="22"
          class="animate-spin"
        ></iconify-icon>
        <iconify-icon v-else icon="lucide:upload" width="22"></iconify-icon>
        <span>{{ uploading ? t('avatarPicker.uploadingShort') : modelValue ? t('avatarPicker.replace') : t('avatarPicker.dragOrClick') }}</span>
      </div>
    </div>

    <!-- actions + info -->
    <div class="flex-1 min-w-0">
      <p class="text-sm text-ink-100">
        <span v-if="modelValue">{{ t('avatarPicker.previewCircular') }}</span>
        <span v-else>{{ t('avatarPicker.uploadHint') }}</span>
      </p>
      <p class="text-xs text-ink-300 mt-1">
        {{ t('avatarPicker.sizeRequirements') }}
      </p>
      <p v-if="error" class="text-xs text-red-300 mt-2">{{ error }}</p>

      <div class="flex flex-wrap gap-2 mt-3">
        <button
          type="button"
          class="btn btn-ghost"
          :disabled="uploading"
          @click="fileInput?.click()"
        >
          <iconify-icon icon="lucide:upload" width="16"></iconify-icon>
          {{ t('avatarPicker.uploadFile') }}
        </button>
        <button v-if="modelValue" type="button" class="btn btn-ghost" @click="clear">
          <iconify-icon icon="lucide:trash-2" width="16"></iconify-icon>
          {{ t('common.remove') }}
        </button>
      </div>

      <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onPick" />
    </div>
  </div>
</template>

<style scoped>
.avatar-frame {
  width: 168px;
  height: 168px;
  border-radius: 16px;
  background: rgb(var(--ink-800-rgb));
  border: 2px dashed rgb(var(--ink-700-rgb));
  cursor: pointer;
  transition:
    border-color 0.15s,
    background 0.15s,
    transform 0.15s;
  position: relative;
  overflow: hidden;
}
.avatar-frame:hover,
.avatar-frame.is-dragover {
  border-color: rgb(var(--accent-rgb));
  background: rgb(var(--ink-700-rgb));
}
.avatar-frame.is-dragover {
  transform: scale(1.02);
}
.avatar-frame__inner {
  position: absolute;
  inset: 0;
}
.avatar-frame__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.avatar-frame__placeholder {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  color: rgb(var(--ink-300-rgb));
}
/* Mask: darkens EVERYTHING except the inscribed circle.
   `closest-side` anchors the gradient radius to the nearest side of the
   container (= half of the square's side), so the "transparent" area
   coincides exactly with the `border-radius: 50%` used in the final
   rendering. Without `closest-side` the default is `farthest-corner`
   (diagonal/2) and the mask hides MUCH more than what will actually
   be clipped. */
.avatar-frame__mask {
  position: absolute;
  inset: 0;
  pointer-events: none;
  background: rgba(8, 11, 16, 0.55);
  -webkit-mask-image: radial-gradient(circle closest-side at center, transparent 99%, black 100%);
  mask-image: radial-gradient(circle closest-side at center, transparent 99%, black 100%);
  transition: background 0.15s;
}
.avatar-frame:hover .avatar-frame__mask {
  background: rgba(8, 11, 16, 0.65);
}
/* Indicator ring of the circle */
.avatar-frame__ring {
  position: absolute;
  inset: 0;
  pointer-events: none;
  border-radius: 50%;
  box-shadow:
    inset 0 0 0 1.5px rgba(255, 255, 255, 0.55),
    inset 0 0 0 3.5px rgba(0, 0, 0, 0.25);
}
.avatar-frame__hint {
  position: absolute;
  left: 50%;
  bottom: 8px;
  transform: translateX(-50%);
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 10px;
  border-radius: 999px;
  background: rgba(8, 11, 16, 0.75);
  color: #fff;
  font-size: 11px;
  font-weight: 500;
  backdrop-filter: blur(4px);
  white-space: nowrap;
  opacity: 0.9;
}
</style>