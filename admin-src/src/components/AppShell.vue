<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuth } from '../stores/auth'
import { useInbox } from '../stores/inbox'
import { useSite } from '../stores/site'
// Logo imported as an asset → Vite hashes the filename and handles
// cache-busting automatically on each build (no more "logo.svg cached
// forever" by CDNs). Same pattern as Login.vue.
import logoUrl from '@/assets/logo.svg'
import { api } from '../api'

const { t } = useI18n()
const auth = useAuth()
const inbox = useInbox()
const site = useSite()
const router = useRouter()
const route = useRoute()
const menuOpen = ref(false)

/**
 * Sidebar "active" state. `<router-link active-class>` would only flag
 * `/` when path equals `/`, which loses the highlight as soon as the
 * user enters block-edit (`/blocks/:id`) — conceptually still the
 * "Tessere" section. We add a custom rule: Tessere is active for `/`
 * AND for any `/blocks/...` sub-path. Other entries use a startsWith
 * match (no entry overlaps another, so this is unambiguous).
 */
function isNavActive(to: string): boolean {
  const path = route.path
  if (to === '/') return path === '/' || path.startsWith('/blocks/')
  return path === to || path.startsWith(to + '/')
}

// Subdomain slug — shown under the brand as a pill (handy for forks
// that host several sites). Stays empty for single-user OSS.
const tenantSlug = computed<string>(() => {
  const host = (typeof window !== 'undefined' ? window.location.hostname : '').toLowerCase()
  const m = host.match(/^([a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?)\.tylio\.app$/)
  return m ? m[1] : ''
})

// OSS fallback pill: on a self-hosted install we don't have a tenant
// slug, but the user still benefits from a quick "which site is this"
// hint under the brand — especially on multi-environment workflows
// (staging.example.com vs example.com). We show site.title if set;
// otherwise we fall back to the bare hostname (no www. prefix).
const ossHeaderLabel = computed<string>(() => {
  if (tenantSlug.value) return '' // SaaS path: not used
  if (site.title) return site.title
  const host = (typeof window !== 'undefined' ? window.location.hostname : '').toLowerCase()
  return host.replace(/^www\./, '')
})

const nav = computed(() => [
  { to: '/', label: t('nav.dashboard'), icon: 'lucide:layout-grid' },
  { to: '/theme', label: t('nav.theme'), icon: 'lucide:palette' },
  { to: '/media', label: t('nav.media'), icon: 'lucide:image' },
  { to: '/settings', label: t('nav.settings'), icon: 'lucide:settings' },
  // Maintenance: placed right under Settings since it's "site
  // configuration" too, but on its own row (and with the `badge:
  // 'maintenance'` flag) so the user notices the amber dot when the
  // site is offline. Discoverable + non-destructive distance from
  // the main flow.
  {
    to: '/maintenance',
    label: t('nav.maintenance'),
    icon: 'lucide:wrench',
    badge: 'maintenance' as const,
  },
  { to: '/submissions', label: t('nav.submissions'), icon: 'lucide:mail', badge: 'inbox' as const },
  { to: '/stats', label: t('nav.stats'), icon: 'lucide:trending-up' },
])

async function logout() {
  await api.logout().catch(() => {})
  auth.user = null
  auth.csrf = null
  inbox.clear()
  site.clear()
  router.push({ name: 'login' })
}

// Unread badge + maintenance banner: refresh on mount + on every
// tab-return (visibility change) + after navigation. The maintenance
// flag rarely changes, but we piggyback on the same triggers because
// the cost is negligible (one tiny COUNT + one settings table read).
function refreshShellState() {
  if (!auth.isLogged) return
  inbox.refresh()
  // Only refresh from network if we haven't loaded yet OR the page is
  // being entered: Settings.vue's save() pushes the new value directly
  // via store.setFromSettings, so a subsequent route change doesn't
  // need to re-fetch.
  if (!site.loaded) site.refresh()
}
onMounted(refreshShellState)
const onVis = () => { if (!document.hidden) refreshShellState() }
document.addEventListener('visibilitychange', onVis)
onUnmounted(() => document.removeEventListener('visibilitychange', onVis))
router.afterEach(() => refreshShellState())
</script>

<template>
  <div class="min-h-screen flex flex-col md:flex-row">
    <!-- Sidebar (desktop) / Topbar (mobile).
         Bg = surface (solid ink-900, no blur/translucency) to avoid the
         page background filtering through and changing the contrast of
         the items. Right border: ink-100/10 (10% of text) — always
         visible. -->
    <header
      class="md:w-64 md:fixed md:inset-y-0 md:left-0 md:flex md:flex-col bg-ink-900 border-r border-ink-100/10 z-30"
    >
      <div class="flex items-center justify-between px-4 md:px-5 py-4 md:py-6">
        <router-link to="/" class="flex items-center gap-3 group min-w-0">
          <img :src="logoUrl" alt="tylio.app" class="brand-logo shrink-0" width="28" height="28" />
          <span class="flex flex-col min-w-0">
            <span class="font-display text-xl font-semibold tracking-tight leading-tight"
              >tylio<span class="text-ink-300 font-normal">.app</span></span
            >
            <span
              v-if="tenantSlug"
              class="text-xs text-ink-300 font-medium truncate"
              :title="tenantSlug + '.tylio.app'"
            >
              <span class="font-semibold text-ink-100">{{ tenantSlug }}</span>
            </span>
            <span
              v-else-if="ossHeaderLabel"
              class="text-xs text-ink-300 font-medium truncate"
              :title="ossHeaderLabel"
            >
              <span class="font-semibold text-ink-100">{{ ossHeaderLabel }}</span>
            </span>
          </span>
        </router-link>
        <button class="md:hidden btn-icon" :aria-label="t('common.menu')" @click="menuOpen = !menuOpen">
          <iconify-icon :icon="menuOpen ? 'lucide:x' : 'lucide:menu'" width="20"></iconify-icon>
        </button>
      </div>

      <nav
        class="px-3 pb-4 md:flex-1 md:overflow-y-auto"
        :class="menuOpen ? 'block' : 'hidden md:block'"
      >
        <ul class="flex flex-col gap-1">
          <li v-for="n in nav" :key="n.to">
            <!-- Main menu colors:
                 - Default: text+icon = ink-100 (primary text)
                 - Hover: text+icon = ink-300 (secondary text)
                 - Active: bg = --backend-accent, text+icon = its companion
                   foreground (--backend-accent-fg). The two vars are
                   driven by theme.ts → pickBackendAccent: tries `accent`,
                   then `accent_alt`, then falls back to `text` so the
                   contrast against the sidebar surface is guaranteed in
                   every palette (the previous logic was hardcoded to the
                   text/surface inversion, which always worked but never
                   showed off the user's palette colors). -->
            <router-link
              :to="n.to"
              class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-ink-100 hover:bg-ink-100/[0.07] transition"
              :class="{ 'nav-active': isNavActive(n.to) }"
              @click="menuOpen = false"
            >
              <span class="relative inline-flex">
                <iconify-icon
                  :icon="n.icon"
                  width="20"
                  class="nav-item__icon"
                ></iconify-icon>
                <!-- "Unread messages" dot: fixed rose-500 (semantic
                     "notification/attention"). We do NOT use accent-alt: on
                     extreme palettes like Pink Lady · light, accent-alt=#fff
                     on a white sidebar → invisible. Rose is universal. -->
                <span
                  v-if="n.badge === 'inbox' && inbox.unread > 0"
                  class="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-rose-500 ring-2 ring-ink-900"
                  :title="t('shell.unreadCountTitle', { n: inbox.unread })"
                  :aria-label="t('shell.unreadCountAria')"
                ></span>
                <!-- "Site under maintenance" dot: theme-aware warning
                     hue (--warning-rgb), pulsing so the eye catches it
                     at a glance. The `.warn-dot` utility renders the
                     full-saturation amber regardless of light/dark
                     palette. -->
                <span
                  v-if="n.badge === 'maintenance' && site.maintenance"
                  class="warn-dot absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full ring-2 ring-ink-900 animate-pulse"
                  :title="t('shell.maintenanceTitle')"
                  :aria-label="t('shell.maintenanceTitle')"
                ></span>
              </span>
              <span class="text-sm">{{ n.label }}</span>
            </router-link>
          </li>
        </ul>

        <!-- Secondary menu: text/icon = ink-300 (secondary text),
             hover → ink-100 (primary text). Never has active state. -->
        <div class="mt-6 pt-4 border-t border-ink-100/10 space-y-2">
          <a
            href="/"
            target="_blank"
            rel="noopener"
            class="flex items-center gap-3 px-3 py-2 rounded-xl text-ink-300 hover:text-ink-100 text-sm transition"
          >
            <iconify-icon icon="lucide:external-link" width="18"></iconify-icon>
            {{ t('nav.openSite') }}
          </a>
          <button
            class="flex items-center gap-3 px-3 py-2 rounded-xl text-ink-300 hover:text-ink-100 text-sm transition w-full"
            @click="logout"
          >
            <iconify-icon icon="lucide:log-out" width="18"></iconify-icon>
            {{ t('nav.logout') }} ({{ auth.user?.username }})
          </button>
        </div>
      </nav>
    </header>

    <!-- Main content -->
    <main class="flex-1 md:ml-64 p-5 md:p-8 max-w-6xl">
      <!-- Admin email banner: shown until the admin sets AND verifies
           an email. Two messages share the same warn-box chrome:
             - `needsEmailSet`     → "Non è stata impostata una email…"
             - `needsEmailVerify`  → "Non è stata verificata l'email…"
           Both link to Settings (anchor `#email`) so the user can act
           in one click. Persistent (no dismiss) by design — the email
           is the only password-reset / 2FA-fallback channel. -->
      <router-link
        v-if="site.needsEmailSet || site.needsEmailVerify"
        :to="{ name: 'settings', hash: '#email' }"
        class="warn-box mb-5 rounded-xl px-4 py-3 flex items-start gap-3 hover:no-underline"
        role="alert"
      >
        <iconify-icon icon="lucide:mail-warning" width="20" class="warn-icon mt-0.5 shrink-0"></iconify-icon>
        <div class="text-sm leading-snug flex-1">
          <p class="warn-strong font-medium">
            <template v-if="site.needsEmailSet">{{ t('shell.emailMissingTitle') }}</template>
            <template v-else>{{ t('shell.emailUnverifiedTitle', { email: site.adminEmail }) }}</template>
          </p>
          <p class="mt-0.5 opacity-90">{{ t('shell.emailBannerBody') }}</p>
        </div>
        <span class="warn-strong self-center text-xs underline-offset-2 underline whitespace-nowrap">
          {{ t('shell.emailBannerAction') }}
        </span>
      </router-link>

      <!-- Maintenance banner: only shown when the site is in maintenance
           mode. Uses the theme-aware `.warn-box` utility so the text /
           border / background all stay readable on every palette (the
           old bare `bg-amber-400/[0.08] text-amber-200` was invisible
           on Nordic light & friends — amber on cream-white). -->
      <div
        v-if="site.maintenance"
        class="warn-box mb-5 rounded-xl px-4 py-3 flex items-start gap-3"
        role="status"
        aria-live="polite"
      >
        <iconify-icon icon="lucide:wrench" width="20" class="warn-icon mt-0.5 shrink-0"></iconify-icon>
        <div class="text-sm leading-snug">
          <p class="warn-strong font-medium">{{ t('shell.maintenanceTitle') }}</p>
          <p class="mt-0.5 opacity-90">{{ t('shell.maintenanceBody') }}</p>
        </div>
        <router-link
          to="/maintenance"
          class="warn-strong ml-auto self-center text-xs underline-offset-2 hover:underline whitespace-nowrap"
        >
          {{ t('shell.maintenanceManage') }}
        </router-link>
      </div>
      <slot />
    </main>
  </div>
</template>

<style scoped>
/* Force the active item's icon to follow the text color (ink-900 =
   surface). Without this override, the icon would stay ink-100 because
   `.nav-item__icon` has no responsive utility on active. */
.nav-item.is-active .nav-item__icon {
  color: rgb(var(--ink-900-rgb));
}
</style>
