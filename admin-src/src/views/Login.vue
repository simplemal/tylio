<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api } from '../api'
import { useAuth } from '../stores/auth'
import { ApiError } from '../types'
// Logo via Vite asset import → automatic hash, cache-bust for free. See
// AppShell.vue for the pattern.
import logoUrl from '@/assets/logo.svg'

const { t } = useI18n()

const username = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)
const route = useRoute()
const router = useRouter()
const auth = useAuth()

// 2FA state: after a successful login() call, if requires_2fa=true we
// show step 2. The temporary CSRF token is already stored in auth.csrf
// from step 1 (pending session).
const step = ref<'password' | '2fa'>('password')
const otpCode = ref('')
const useBackupCode = ref(false)

// Subdomain slug shown in the branding (handy for forks that host
// multiple sites — the user immediately sees which one they're logging
// into). For single-user OSS this stays empty and the pill is hidden.
const tenantSlug = computed<string>(() => {
  const host = (typeof window !== 'undefined' ? window.location.hostname : '').toLowerCase()
  const m = host.match(/^([a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?)\.tylio\.app$/)
  return m ? m[1] : ''
})

async function submit() {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    const r = await api.login(username.value, password.value)
    // We need the session CSRF (pending or complete) from now on for a
    // potential step 2.
    auth.csrf = r.csrf
    if (r.requires_2fa) {
      // Pending session: the user has to complete step 2 before the
      // session is accepted by AuthMiddleware.
      step.value = '2fa'
      otpCode.value = ''
      useBackupCode.value = false
      return
    }
    auth.user = r.user
    auth.bootstrapped = true
    const next = (route.query.next as string) || '/'
    router.push(next)
  } catch (e: unknown) {
    if (e instanceof ApiError && e.status === 429) {
      error.value = e.data.retry_after
        ? t('login.errors.rateLimited', { seconds: e.data.retry_after })
        : t('login.errors.rateLimitedFallback')
    } else if (e instanceof ApiError && e.status === 401) {
      error.value = t('login.errors.invalid')
    } else if (e instanceof ApiError && e.status === 403 && e.data.error === 'password_change_required') {
      // First login with temporary password: the server tells us to send
      // the user to /change-password (server-rendered, outside the SPA).
      const changeUrl =
        typeof e.data.change_url === 'string'
          ? e.data.change_url
          : `/change-password?u=${encodeURIComponent(username.value)}`
      window.location.href = changeUrl
      return
    } else if (e instanceof ApiError && e.status === 404 && e.data.error === 'no_tenant') {
      // The user is loading the admin SPA on a deleted/unmapped subdomain.
      // The server tells us where the canonical platform lookup lives
      // (`lookup_url`) — that key is set by the platform overlay and
      // absent on plain OSS deploys, where we just show the message.
      error.value = t('login.errors.wrongDomain')
      const lookup = typeof e.data.lookup_url === 'string' ? e.data.lookup_url : ''
      if (lookup) {
        window.location.href = lookup
      }
      return
    } else if (e instanceof ApiError && e.status >= 500) {
      // 5xx: server crashed. Surface the status code so the user can copy
      // it into a bug report instead of guessing it's a network issue.
      error.value = t('login.errors.serverError', { status: e.status })
    } else if (e instanceof ApiError) {
      // Catch-all for unexpected non-success statuses we don't have a
      // specific handler for (400, 405, 410, …). Show the status code so
      // the cause isn't hidden behind a generic "network error".
      error.value = t('login.errors.unexpectedStatus', { status: e.status })
    } else {
      // Genuine network/transport error: fetch threw before any HTTP
      // response (DNS, offline, CORS, TLS, …). Only here say "network".
      error.value = t('login.errors.network')
    }
  } finally {
    loading.value = false
  }
}

async function submit2fa() {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    const r = await api.login2fa(otpCode.value.trim(), useBackupCode.value)
    auth.user = r.user
    auth.csrf = r.csrf
    auth.bootstrapped = true
    const next = (route.query.next as string) || '/'
    router.push(next)
  } catch (e: unknown) {
    if (e instanceof ApiError && e.status === 429) {
      error.value = e.data.retry_after
        ? t('login.errors.rateLimited', { seconds: e.data.retry_after })
        : t('login.errors.rateLimitedFallback')
    } else if (e instanceof ApiError && e.status === 401) {
      error.value = useBackupCode.value
        ? t('login.errors.invalidBackup')
        : t('login.errors.invalid2fa')
    } else if (e instanceof ApiError && e.status >= 500) {
      error.value = t('login.errors.serverError', { status: e.status })
    } else if (e instanceof ApiError) {
      error.value = t('login.errors.unexpectedStatus', { status: e.status })
    } else {
      error.value = t('login.errors.network')
    }
  } finally {
    loading.value = false
  }
}

function backToPassword() {
  step.value = 'password'
  password.value = ''
  otpCode.value = ''
  error.value = ''
}
</script>

<template>
  <div class="min-h-screen grid place-items-center p-6">
    <div class="w-full max-w-sm">
      <div class="flex flex-col items-center gap-3 mb-8">
        <img :src="logoUrl" :alt="t('login.brandAlt')" class="brand-logo" width="56" height="56" />
        <h1 class="font-display text-3xl font-semibold tracking-tight">
          tylio<span class="text-ink-300 font-normal">.app</span>
        </h1>
        <p class="text-ink-300 text-sm flex items-center gap-2">
          <span>{{ t('login.subtitle') }}</span>
          <span
            v-if="tenantSlug"
            class="font-semibold text-ink-100 bg-ink-800 ring-1 ring-white/10 rounded-md px-2 py-0.5"
          >{{ tenantSlug }}</span>
        </p>
      </div>

      <form v-if="step === 'password'" class="tile" @submit.prevent="submit">
        <div class="field">
          <label for="u">{{ t('login.username') }}</label>
          <input id="u" v-model="username" type="text" autocomplete="username" required autofocus />
        </div>
        <div class="field">
          <label for="p">{{ t('login.password') }}</label>
          <input
            id="p"
            v-model="password"
            type="password"
            autocomplete="current-password"
            required
          />
        </div>
        <button class="btn btn-primary w-full justify-center" :disabled="loading">
          <iconify-icon
            v-if="loading"
            icon="lucide:loader-circle"
            class="animate-spin"
            width="18"
          ></iconify-icon>
          <iconify-icon v-else icon="lucide:log-in" width="18"></iconify-icon>
          {{ loading ? t('login.submitting') : t('login.submit') }}
        </button>
        <p v-if="error" class="text-red-300 text-sm mt-3 text-center">{{ error }}</p>
      </form>

      <form v-else class="tile" @submit.prevent="submit2fa">
        <!-- Dynamic hint: TOTP mode shows the short authenticator hint;
             backup mode shows the longer single-use explanation so the
             user understands what to paste in and what happens after. -->
        <p class="text-ink-300 text-sm mb-4 text-center leading-snug">
          <iconify-icon
            :icon="useBackupCode ? 'lucide:life-buoy' : 'lucide:shield-check'"
            width="18"
            class="align-middle mr-1"
          ></iconify-icon>
          {{ useBackupCode ? t('login.twoFactor.hintBackup') : t('login.twoFactor.hint') }}
        </p>
        <div class="field">
          <label for="otp">{{
            useBackupCode ? t('login.twoFactor.backupCode') : t('login.twoFactor.code')
          }}</label>
          <input
            id="otp"
            v-model="otpCode"
            type="text"
            :inputmode="useBackupCode ? 'text' : 'numeric'"
            :pattern="useBackupCode ? '[0-9a-fA-F]{6,8}' : '[0-9]{6}'"
            :maxlength="useBackupCode ? 8 : 6"
            :placeholder="
              useBackupCode
                ? t('login.twoFactor.backupCodePlaceholder')
                : t('login.twoFactor.codePlaceholder')
            "
            autocomplete="one-time-code"
            autofocus
            required
          />
          <p v-if="useBackupCode" class="text-ink-300 text-xs mt-2 leading-snug">
            {{ t('login.twoFactor.backupHelpFooter') }}
          </p>
        </div>
        <label class="text-ink-300 text-xs mb-3 flex items-center gap-2 cursor-pointer">
          <input v-model="useBackupCode" type="checkbox" />
          <span>{{ t('login.twoFactor.backup') }}</span>
        </label>
        <button class="btn btn-primary w-full justify-center" :disabled="loading">
          <iconify-icon
            v-if="loading"
            icon="lucide:loader-circle"
            class="animate-spin"
            width="18"
          ></iconify-icon>
          <iconify-icon v-else icon="lucide:shield-check" width="18"></iconify-icon>
          {{ loading ? t('login.twoFactor.submitting') : t('login.twoFactor.submit') }}
        </button>
        <p v-if="error" class="text-red-300 text-sm mt-3 text-center">{{ error }}</p>
        <button
          type="button"
          class="btn btn-ghost w-full justify-center mt-2"
          :disabled="loading"
          @click="backToPassword"
        >
          <iconify-icon icon="lucide:arrow-left" width="16"></iconify-icon>
          {{ t('login.twoFactor.backToPassword') }}
        </button>
      </form>
    </div>
  </div>
</template>
