<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuth } from '../stores/auth'
import { useInbox } from '../stores/inbox'
// Logo imported as an asset → Vite hashes the filename and handles
// cache-busting automatically on each build (no more "logo.svg cached
// forever" by CDNs). Same pattern as Login.vue.
import logoUrl from '@/assets/logo.svg'
import { api } from '../api'

const { t } = useI18n()
const auth = useAuth()
const inbox = useInbox()
const router = useRouter()
const menuOpen = ref(false)

// Subdomain slug — shown under the brand as a pill (handy for forks
// that host several sites). Stays empty for single-user OSS.
const tenantSlug = computed<string>(() => {
  const host = (typeof window !== 'undefined' ? window.location.hostname : '').toLowerCase()
  const m = host.match(/^([a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?)\.tylio\.app$/)
  return m ? m[1] : ''
})

const nav = computed(() => [
  { to: '/', label: t('nav.dashboard'), icon: 'lucide:layout-grid' },
  { to: '/theme', label: t('nav.theme'), icon: 'lucide:palette' },
  { to: '/media', label: t('nav.media'), icon: 'lucide:image' },
  { to: '/settings', label: t('nav.settings'), icon: 'lucide:settings' },
  { to: '/submissions', label: t('nav.submissions'), icon: 'lucide:mail', badge: 'inbox' as const },
  { to: '/stats', label: t('nav.stats'), icon: 'lucide:trending-up' },
])

async function logout() {
  await api.logout().catch(() => {})
  auth.user = null
  auth.csrf = null
  inbox.clear()
  router.push({ name: 'login' })
}

// Unread badge: refresh on mount + on every tab-return (visibility
// change) + after navigation (so leaving /submissions clears the badge
// without reloading the page).
function refreshBadge() {
  if (!auth.isLogged) return
  inbox.refresh()
}
onMounted(refreshBadge)
const onVis = () => { if (!document.hidden) refreshBadge() }
document.addEventListener('visibilitychange', onVis)
onUnmounted(() => document.removeEventListener('visibilitychange', onVis))
router.afterEach(() => refreshBadge())
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
              class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-ink-100"
              active-class="nav-active"
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
