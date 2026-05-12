import { defineStore } from 'pinia'
import { api } from '../api'

/**
 * Global state for the "site is in maintenance mode" banner shown at
 * the top of the admin shell. Refreshed on demand:
 *   - on AppShell mount (after login)
 *   - when the tab returns from background (visibility change)
 *   - after the user saves Settings (cross-component update)
 *
 * Implemented as a Pinia store rather than a per-view fetch so the
 * banner in AppShell stays reactive when Settings.vue flips the flag,
 * without prop-drilling or window events.
 */
export const useSite = defineStore('site', {
  state: () => ({
    maintenance: false,
    loaded: false,
  }),
  actions: {
    async refresh() {
      try {
        const r = await api.getSettings()
        this.maintenance = Boolean(r.settings['site.maintenance'])
        this.loaded = true
      } catch {
        // silent: the banner isn't critical
      }
    },
    /**
     * Cross-component shortcut for views that already have the latest
     * settings object in memory (e.g. Settings.vue after save). Avoids
     * a redundant network round-trip.
     */
    setFromSettings(settings: Record<string, unknown>) {
      this.maintenance = Boolean(settings['site.maintenance'])
      this.loaded = true
    },
    clear() {
      this.maintenance = false
      this.loaded = false
    },
  },
})
