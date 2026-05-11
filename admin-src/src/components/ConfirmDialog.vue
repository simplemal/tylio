<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirm } from '../composables/useConfirm'
import { useDialog } from '../composables/useDialog'

const { t } = useI18n()
const { isOpen, opts, resolve } = useConfirm()
const root = ref<HTMLElement | null>(null)

const title = computed(() => opts.value?.title ?? t('confirmDialog.defaultTitle'))
const message = computed(() => opts.value?.message ?? '')
const confirmLabel = computed(() => opts.value?.confirmLabel ?? t('common.confirm'))
const cancelLabel = computed(() => opts.value?.cancelLabel ?? t('common.cancel'))
const destructive = computed(() => Boolean(opts.value?.destructive))

useDialog({
  isOpen,
  rootEl: root,
  onClose: () => resolve(false),
})
</script>

<template>
  <Teleport to="body">
    <div v-if="isOpen" class="confirm-backdrop" aria-hidden="false" @click.self="resolve(false)">
      <div
        ref="root"
        class="confirm-dialog"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="confirm-title"
        aria-describedby="confirm-message"
        tabindex="-1"
      >
        <h2 id="confirm-title" class="confirm-title">{{ title }}</h2>
        <p id="confirm-message" class="confirm-message">{{ message }}</p>
        <div class="confirm-actions">
          <button type="button" class="btn btn-ghost" @click="resolve(false)">
            {{ cancelLabel }}
          </button>
          <button
            type="button"
            class="btn"
            :class="destructive ? 'btn-danger' : 'btn-primary'"
            autofocus
            @click="resolve(true)"
          >
            {{ confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.confirm-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  backdrop-filter: blur(4px);
  display: grid;
  place-items: center;
  z-index: 100;
  padding: 1rem;
  animation: fade-in 120ms ease-out;
}

.confirm-dialog {
  background: var(--ink-800, #1a1a1f);
  border: 1px solid var(--ink-600, #2c2c34);
  border-radius: 14px;
  padding: 1.5rem;
  max-width: 28rem;
  width: 100%;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
  animation: pop-in 160ms cubic-bezier(0.2, 0.9, 0.3, 1.2);
}

.confirm-title {
  font-family: var(--font-display, serif);
  font-size: 1.25rem;
  margin: 0 0 0.5rem;
  color: var(--ink-50, #f5f5f7);
}

.confirm-message {
  margin: 0 0 1.25rem;
  color: var(--ink-300, #b6b6c0);
  line-height: 1.5;
}

.confirm-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}

@keyframes fade-in {
  from {
    opacity: 0;
  }
}

@keyframes pop-in {
  from {
    opacity: 0;
    transform: translateY(8px) scale(0.96);
  }
}

@media (prefers-reduced-motion: reduce) {
  .confirm-backdrop,
  .confirm-dialog {
    animation: none;
  }
}
</style>
