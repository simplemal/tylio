import { defineStore } from 'pinia'
import { api } from '../api'

/**
 * Global state for the "unread messages" badge shown on the Messages menu
 * item in AppShell. Refreshed on demand: the call is ~10ms (a COUNT(*)
 * on a small table) and we don't want to poll, so we refresh:
 *   - after login (AppShell mount)
 *   - when entering/leaving the Messages view (router-aware)
 *   - when the tab comes back from background (visibility change)
 */
export const useInbox = defineStore('inbox', {
  state: () => ({
    unread: 0,
    loaded: false,
  }),
  actions: {
    async refresh() {
      try {
        const r = await api.unreadSubmissionsCount()
        this.unread = r.count
        this.loaded = true
      } catch {
        // silent: the badge isn't critical, we don't want to block the admin
      }
    },
    setRead(delta: number) {
      this.unread = Math.max(0, this.unread - delta)
    },
    clear() {
      this.unread = 0
    },
  },
})
