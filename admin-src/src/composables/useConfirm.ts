import { ref } from 'vue'

/**
 * Drop-in replacement for native `confirm()` with a styled + accessible
 * dialog.
 *
 * State lives at module level (singleton) — there's ONE confirm dialog
 * mounted globally in App.vue; any view can open it via `useConfirm()`.
 *
 *   const { confirm } = useConfirm()
 *   if (!await confirm({ message: 'Delete?', destructive: true })) return
 *   // ... destructive action
 */
export interface ConfirmOptions {
  title?: string
  message: string
  confirmLabel?: string
  cancelLabel?: string
  destructive?: boolean
}

const isOpen = ref(false)
const opts = ref<ConfirmOptions | null>(null)
let resolver: ((v: boolean) => void) | null = null

export function useConfirm() {
  function confirm(o: ConfirmOptions): Promise<boolean> {
    // If a confirm is somehow already pending, resolve it as false
    // (the user implicitly "cancelled" by clicking another action).
    if (resolver) resolver(false)
    opts.value = o
    isOpen.value = true
    return new Promise<boolean>((res) => {
      resolver = res
    })
  }

  function resolve(value: boolean): void {
    if (!resolver) return
    isOpen.value = false
    const r = resolver
    resolver = null
    r(value)
  }

  return { confirm, isOpen, opts, resolve }
}
