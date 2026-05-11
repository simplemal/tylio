import { onBeforeUnmount, watch, type Ref } from 'vue'

/**
 * Accessible dialog/modal helper: handles focus-trap, Escape to close,
 * body scroll lock and restoring focus to the starting element on close.
 *
 * Usage pattern:
 *   const root = ref<HTMLElement | null>(null)
 *   const isOpen = ref(false)
 *   useDialog({ isOpen, rootEl: root, onClose: () => isOpen.value = false })
 *   <div v-if="isOpen" ref="root" role="dialog" aria-modal="true" aria-labelledby="x">…</div>
 */
export interface UseDialogOptions {
  isOpen: Ref<boolean>
  rootEl: Ref<HTMLElement | null>
  onClose: () => void
}

const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',')

export function useDialog({ isOpen, rootEl, onClose }: UseDialogOptions): void {
  let prevFocus: HTMLElement | null = null
  let prevBodyOverflow: string | null = null

  function focusable(): HTMLElement[] {
    if (!rootEl.value) return []
    return Array.from(rootEl.value.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
      (el) => !el.hasAttribute('disabled') && el.offsetParent !== null,
    )
  }

  function onKeydown(e: KeyboardEvent): void {
    if (!isOpen.value) return
    if (e.key === 'Escape') {
      e.preventDefault()
      onClose()
      return
    }
    if (e.key !== 'Tab') return
    const items = focusable()
    if (items.length === 0) return
    const first = items[0]
    const last = items[items.length - 1]
    const active = document.activeElement as HTMLElement | null
    if (e.shiftKey && (active === first || !rootEl.value?.contains(active))) {
      e.preventDefault()
      last.focus()
    } else if (!e.shiftKey && (active === last || !rootEl.value?.contains(active))) {
      e.preventDefault()
      first.focus()
    }
  }

  watch(
    isOpen,
    (open) => {
      if (open) {
        prevFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
        prevBodyOverflow = document.body.style.overflow
        document.body.style.overflow = 'hidden'
        document.addEventListener('keydown', onKeydown)
        // wait for the dialog to be mounted + rendered before focusing
        requestAnimationFrame(() => {
          if (!rootEl.value) return
          const auto = rootEl.value.querySelector<HTMLElement>('[autofocus]')
          const target = auto ?? focusable()[0] ?? rootEl.value
          target?.focus()
        })
      } else {
        document.removeEventListener('keydown', onKeydown)
        if (prevBodyOverflow !== null) document.body.style.overflow = prevBodyOverflow
        prevBodyOverflow = null
        prevFocus?.focus()
        prevFocus = null
      }
    },
    // immediate: needed when the dialog component is created already "open" (e.g. with parent v-if)
    { immediate: true, flush: 'post' },
  )

  onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown)
    if (prevBodyOverflow !== null) document.body.style.overflow = prevBodyOverflow
  })
}
