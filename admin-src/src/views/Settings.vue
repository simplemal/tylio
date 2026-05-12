<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../api'
import { ApiError } from '../types'
import type { Settings } from '../types'
import { useAuth } from '../stores/auth'
import {
  SUPPORTED_LOCALES,
  setLocale,
  clearLocaleOverride,
  detectInitialLocale,
  type SupportedLocale,
} from '../i18n'
import FaviconUploader from '../components/FaviconUploader.vue'
import OgImageUploader from '../components/OgImageUploader.vue'
import { useSite } from '../stores/site'

const { t, locale } = useI18n()
const auth = useAuth()
const site = useSite()
const settings = ref<Settings>({})
const saving = ref(false)
// Per-field errors received from the server (422). Maps `settings key → message`.
const fieldErrors = ref<Record<string, string>>({})
const saveError = ref<string>('')

// Link to the change-password page with username pre-filled (user is
// already logged in).
const changePasswordHref = computed(
  () =>
    auth.user?.username
      ? `/change-password?u=${encodeURIComponent(auth.user.username)}`
      : '/change-password',
)

// ===== Language selector =====
// Whether the user has explicitly set a locale (stored in localStorage)
// or is using browser auto-detection. The select's "Browser default"
// option clears the override.
const STORAGE_KEY = 'tylio.locale'
const localeOverridden = ref<boolean>(readLocaleOverride())
function readLocaleOverride(): boolean {
  try {
    return Boolean(window.localStorage.getItem(STORAGE_KEY))
  } catch {
    return false
  }
}
// The select's current value: '' means "browser default", any supported
// locale code means an explicit choice.
const localeSelection = ref<string>(localeOverridden.value ? (locale.value as string) : '')
// The locale that would be used if no override existed (shown in the
// "Browser default ({locale})" option).
const detectedBrowserLocale = computed<SupportedLocale>(() => {
  if (localeOverridden.value) {
    // detectInitialLocale honors the stored value, so to compute the
    // "what would the browser pick" we temporarily ignore storage.
    return detectFromBrowserOnly()
  }
  return detectInitialLocale()
})
function detectFromBrowserOnly(): SupportedLocale {
  const langs: readonly string[] = Array.isArray(navigator.languages)
    ? navigator.languages
    : [navigator.language]
  for (const l of langs) {
    const short = (l || '').toLowerCase().split(/[-_]/)[0]
    if ((SUPPORTED_LOCALES as readonly string[]).includes(short))
      return short as SupportedLocale
  }
  return 'en'
}
function localeName(code: string): string {
  return t(`settings.language.names.${code}`)
}
function onLocaleSelect(value: string): void {
  localeSelection.value = value
  if (value === '') {
    clearLocaleOverride()
    localeOverridden.value = false
  } else if ((SUPPORTED_LOCALES as readonly string[]).includes(value)) {
    setLocale(value as SupportedLocale)
    localeOverridden.value = true
  }
}

// Typed accessors for settings used in template bindings. Vue tracks
// reactivity through `settings.value[key]` here.
function getStr(key: string): string {
  const v = settings.value[key]
  return typeof v === 'string' ? v : ''
}
function setStr(key: string, v: string): void {
  settings.value[key] = v
  validateField(key, v)
}
/**
 * Live validation while the user types: if the value doesn't match the
 * pattern declared on the field, sets the inline error immediately
 * (without waiting for Save). Same logic as the server
 * (SettingsController.validateSettings) for consistency.
 */
function validateField(key: string, value: string): void {
  const def = known.find((f) => f.key === key)
  if (!def) return
  // empty value = ok (fields are optional unless pattern is strict)
  if (value === '') {
    delete fieldErrors.value[key]
    return
  }
  if (def.pattern) {
    const re = new RegExp('^(?:' + def.pattern + ')$')
    if (!re.test(value)) {
      fieldErrors.value[key] = def.patternHintKey
        ? t(def.patternHintKey)
        : t('settings.errors.invalidFormat')
      return
    }
  }
  if (def.inputType === 'email') {
    const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
    if (!emailRe.test(value)) {
      fieldErrors.value[key] = t('settings.errors.invalidEmail')
      return
    }
  }
  delete fieldErrors.value[key]
}
function getBool(key: string): boolean {
  return Boolean(settings.value[key])
}
function setBool(key: string, v: boolean): void {
  settings.value[key] = v
}

interface FieldDef {
  key: string
  labelKey: string
  type: 'text' | 'textarea' | 'toggle' | 'favicon' | 'og_image'
  placeholderKey?: string
  helpKey: string
  groupKey?: string // section heading (shown above the first field of the group)
  // Optional validation for `text` fields. `pattern` is an HTML5 regex
  // (client-side validation). `maxlength` clips typing. `inputType` can
  // override "text" (e.g. 'url' for /^https?:\/\//). `lowercase` forces
  // the value to lowercase on blur.
  pattern?: string
  maxlength?: number
  inputType?: 'text' | 'url' | 'email'
  lowercase?: boolean
  patternHintKey?: string // i18n key for the error text shown if the pattern doesn't match
}

const known: FieldDef[] = [
  {
    groupKey: 'settings.groups.identity',
    key: 'site.title',
    labelKey: 'settings.site.siteTitle',
    type: 'text',
    placeholderKey: 'settings.site.siteTitlePlaceholder',
    helpKey: 'settings.site.siteTitleHelp',
  },
  {
    key: 'site.tagline',
    labelKey: 'settings.site.siteTagline',
    type: 'text',
    placeholderKey: 'settings.site.siteTaglinePlaceholder',
    helpKey: 'settings.site.siteTaglineHelp',
  },
  {
    key: 'site.description',
    labelKey: 'settings.site.siteDescription',
    type: 'textarea',
    placeholderKey: 'settings.site.siteDescriptionPlaceholder',
    helpKey: 'settings.site.siteDescriptionHelp',
  },
  {
    key: 'site.author',
    labelKey: 'settings.site.siteAuthor',
    type: 'text',
    placeholderKey: 'settings.site.siteAuthorPlaceholder',
    helpKey: 'settings.site.siteAuthorHelp',
  },
  {
    key: 'site.locale',
    labelKey: 'settings.site.siteLocale',
    type: 'text',
    placeholderKey: 'settings.site.siteLocalePlaceholder',
    helpKey: 'settings.site.siteLocaleHelp',
    pattern: '[a-zA-Z]{2}(-[a-zA-Z]{2})?',
    maxlength: 5,
    lowercase: true,
    patternHintKey: 'settings.site.siteLocaleHint',
  },

  {
    groupKey: 'settings.groups.seoIndexing',
    key: 'seo.canonical_url',
    labelKey: 'settings.seo.canonicalUrl',
    type: 'text',
    placeholderKey: 'settings.seo.canonicalUrlPlaceholder',
    helpKey: 'settings.seo.canonicalUrlHelp',
    inputType: 'url',
    pattern: 'https?://.+',
    patternHintKey: 'settings.seo.canonicalUrlHint',
  },
  {
    key: 'seo.robots_index',
    labelKey: 'settings.seo.robotsIndex',
    type: 'toggle',
    helpKey: 'settings.seo.robotsIndexHelp',
  },
  {
    key: 'seo.og_image',
    labelKey: 'settings.seo.ogImage',
    type: 'og_image',
    helpKey: 'settings.seo.ogImageHelp',
  },
  {
    key: 'seo.favicon',
    labelKey: 'settings.seo.favicon',
    type: 'favicon',
    helpKey: 'settings.seo.faviconHelp',
  },
  {
    key: 'seo.twitter_handle',
    labelKey: 'settings.seo.twitterHandle',
    type: 'text',
    placeholderKey: 'settings.seo.twitterHandlePlaceholder',
    helpKey: 'settings.seo.twitterHandleHelp',
  },

  {
    groupKey: 'settings.groups.communications',
    key: 'contact.notify_email',
    labelKey: 'settings.contact.notifyEmail',
    type: 'text',
    placeholderKey: 'settings.contact.notifyEmailPlaceholder',
    helpKey: 'settings.contact.notifyEmailHelp',
    inputType: 'email',
  },
]
// NOTE: maintenance mode used to live here in earlier iterations
// (first inside `known` as a regular field group, then as a
// standalone card at the top of this view). It now has its own
// dedicated route at /maintenance with its own sidebar nav item —
// flipping the switch is a one-purpose, urgent action and shouldn't
// share UI real estate with the rest of the settings.

onMounted(async () => {
  settings.value = (await api.getSettings()).settings
  for (const k of known) {
    if (!(k.key in settings.value)) {
      // toggle: default true (indexing on by default), text: empty string
      settings.value[k.key] = k.type === 'toggle' ? k.key === 'seo.robots_index' : ''
    }
  }
  // Validate values already in the DB: if any is invalid (legacy),
  // surface the inline error now without waiting for the user to edit it.
  for (const k of known) {
    if (k.type === 'text') validateField(k.key, getStr(k.key))
  }
  // Load 2FA status in parallel (doesn't block the form render).
  void load2faStatus()
})

async function save() {
  // Re-validate all fields before submit (catches the case where the
  // user filled a field WITHOUT ever firing a recent input event).
  for (const f of known) {
    if (f.type === 'text') validateField(f.key, getStr(f.key))
  }
  if (Object.keys(fieldErrors.value).length > 0) {
    saveError.value = t('settings.errors.invalidFields')
    return
  }

  saving.value = true
  saveError.value = ''
  try {
    settings.value = (await api.updateSettings(settings.value)).settings
    // Sync the AppShell banner without a separate fetch: we already
    // have the freshly-saved value here.
    site.setFromSettings(settings.value as Record<string, unknown>)
  } catch (e: unknown) {
    if (e instanceof ApiError && e.status === 422 && e.data.fields && typeof e.data.fields === 'object') {
      fieldErrors.value = e.data.fields as Record<string, string>
      saveError.value = t('settings.errors.invalidFields')
    } else {
      saveError.value = t('settings.errors.saveFailed')
    }
  } finally {
    saving.value = false
  }
}

// ===== 2FA TOTP =====
// Mirror of the superadmin logic (PlatformAdminController::twoFactorPanel).
// States: 'idle' (shows enable/disable button) | 'setup' (QR + code form) |
// 'showing_backup' (shows the 10 codes once only) | 'disabling' (confirm
// form with DISATTIVA).
type TwoFactorView = 'idle' | 'setup' | 'showing_backup' | 'disabling'
const twoFactorEnabled = ref(false)
const twoFactorRemainingBackup = ref(0)
const twoFactorView = ref<TwoFactorView>('idle')
const twoFactorBusy = ref(false)
const twoFactorError = ref('')
// Setup state
const twoFactorSecret = ref('')
const twoFactorProvisioningUri = ref('')
const twoFactorVerifyCode = ref('')
// Backup codes (shown only once after confirm or regenerate)
const twoFactorBackupCodes = ref<string[]>([])
// Disable form
const twoFactorDisableConfirm = ref('')

// QR uses an external service (api.qrserver.com). The provisioning URI
// contains the secret — accepting that this URI is shared with the
// third-party QR renderer is the same tradeoff every TOTP setup makes.
const twoFactorQrSrc = computed(() =>
  twoFactorProvisioningUri.value
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' +
      encodeURIComponent(twoFactorProvisioningUri.value)
    : '',
)

async function load2faStatus(): Promise<void> {
  try {
    const r = await api.twoFactorStatus()
    twoFactorEnabled.value = r.enabled
    twoFactorRemainingBackup.value = r.remaining_backup_codes
  } catch {
    /* ignore: showing the panel isn't critical if the status API fails */
  }
}

async function start2faSetup(): Promise<void> {
  if (twoFactorBusy.value) return
  twoFactorBusy.value = true
  twoFactorError.value = ''
  try {
    const r = await api.twoFactorSetupInit()
    twoFactorSecret.value = r.secret
    twoFactorProvisioningUri.value = r.provisioning_uri
    twoFactorVerifyCode.value = ''
    twoFactorView.value = 'setup'
  } catch (e: unknown) {
    twoFactorError.value =
      e instanceof ApiError
        ? t('settings.errors.errorWithStatus', { status: e.status })
        : t('settings.errors.networkError')
  } finally {
    twoFactorBusy.value = false
  }
}

async function confirm2faSetup(): Promise<void> {
  if (twoFactorBusy.value) return
  twoFactorBusy.value = true
  twoFactorError.value = ''
  try {
    const r = await api.twoFactorSetupConfirm(
      twoFactorSecret.value,
      twoFactorVerifyCode.value.trim(),
    )
    twoFactorBackupCodes.value = r.backup_codes
    twoFactorEnabled.value = true
    twoFactorRemainingBackup.value = r.backup_codes.length
    twoFactorSecret.value = ''
    twoFactorProvisioningUri.value = ''
    twoFactorVerifyCode.value = ''
    twoFactorView.value = 'showing_backup'
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      twoFactorError.value =
        typeof e.data.message === 'string'
          ? e.data.message
          : t('settings.errors.errorWithStatus', { status: e.status })
    } else {
      twoFactorError.value = t('settings.errors.networkErrorRetry')
    }
  } finally {
    twoFactorBusy.value = false
  }
}

async function disable2fa(): Promise<void> {
  if (twoFactorBusy.value) return
  twoFactorBusy.value = true
  twoFactorError.value = ''
  try {
    await api.twoFactorDisable(twoFactorDisableConfirm.value.trim())
    twoFactorEnabled.value = false
    twoFactorRemainingBackup.value = 0
    twoFactorDisableConfirm.value = ''
    twoFactorView.value = 'idle'
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      twoFactorError.value =
        typeof e.data.message === 'string'
          ? e.data.message
          : t('settings.errors.errorWithStatus', { status: e.status })
    } else {
      twoFactorError.value = t('settings.errors.networkErrorRetry')
    }
  } finally {
    twoFactorBusy.value = false
  }
}

async function regenerate2faBackup(): Promise<void> {
  if (twoFactorBusy.value) return
  twoFactorBusy.value = true
  twoFactorError.value = ''
  try {
    const r = await api.twoFactorRegenerateBackup()
    twoFactorBackupCodes.value = r.backup_codes
    twoFactorRemainingBackup.value = r.backup_codes.length
    twoFactorView.value = 'showing_backup'
  } catch (e: unknown) {
    twoFactorError.value =
      e instanceof ApiError
        ? t('settings.errors.errorWithStatus', { status: e.status })
        : t('settings.errors.networkError')
  } finally {
    twoFactorBusy.value = false
  }
}

function copyBackupCodes(): void {
  void navigator.clipboard.writeText(twoFactorBackupCodes.value.join('\n'))
}

function resetTwoFactorView(): void {
  twoFactorView.value = 'idle'
  twoFactorError.value = ''
  twoFactorBackupCodes.value = []
  twoFactorDisableConfirm.value = ''
  twoFactorSecret.value = ''
  twoFactorProvisioningUri.value = ''
  twoFactorVerifyCode.value = ''
}

// Export the home page as a single index.html with everything inlined
// (images as data URI, CSS already in the template). GET
// /api/export/inline → blob → click on <a download>. AuthMiddleware via
// session cookie (credentials same-origin).
const exporting = ref(false)
const exportError = ref('')

async function downloadHome(): Promise<void> {
  if (exporting.value) return
  exporting.value = true
  exportError.value = ''
  try {
    const r = await fetch('/api/export/inline', { credentials: 'same-origin' })
    if (!r.ok) throw new Error(`HTTP ${r.status}`)
    const blob = await r.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'index.html'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
  } catch (e: unknown) {
    exportError.value = e instanceof Error ? e.message : t('settings.errors.exportFailed')
  } finally {
    exporting.value = false
  }
}

// Account deletion (self-destroy). 2-step confirmation:
// 1) click on the button → shows confirmation + asks to type "CANCELLA"
// 2) submit of the modal form → API call → redirect to tylio.app
const deleteOpen = ref(false)
const deleteConfirmText = ref('')
const deleting = ref(false)
const deleteError = ref('')

async function performDelete() {
  if (deleting.value) return
  deleting.value = true
  deleteError.value = ''
  try {
    const r = await api.deleteAccount()
    // Everything deleted. Redirect to the tylio.app home.
    window.location.href = r.redirect_url
  } catch (e: unknown) {
    deleting.value = false
    deleteError.value =
      e instanceof ApiError
        ? t('settings.errors.deleteWithStatus', {
            status: e.status,
            detail: e.data.error ?? t('settings.errors.unknown'),
          })
        : t('settings.errors.deleteGeneric')
  }
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
      <p class="eyebrow">{{ t('settings.eyebrow') }}</p>
      <h1 class="heading">{{ t('settings.title') }}</h1>
    </div>
    <button class="btn btn-primary" :disabled="saving" @click="save">
      <iconify-icon
        :icon="saving ? 'lucide:loader-circle' : 'lucide:check'"
        width="18"
        :class="saving ? 'animate-spin' : ''"
      ></iconify-icon>
      {{ saving ? t('common.saving') : t('common.save') }}
    </button>
  </div>

  <p v-if="saveError" class="text-sm text-red-300 mb-4 p-3 rounded-lg bg-red-500/10 border border-red-500/30">
    {{ saveError }}
  </p>

  <div class="tile space-y-5">
    <template v-for="(f, i) in known" :key="f.key">
      <!-- Section heading. `id` is the last dotted segment of the key
           (e.g. `groups.maintenance` → `maintenance`) so deep links like
           `/settings#maintenance` (used by the AppShell banner) scroll
           the user straight to the right section. -->
      <h2
        v-if="f.groupKey"
        :id="f.groupKey.split('.').pop()"
        class="font-display text-xl mt-2 first:mt-0 pb-1 border-b border-white/10 scroll-mt-24"
      >
        {{ t(f.groupKey) }}
      </h2>
      <div class="field" :class="{ 'pt-4 border-t border-white/5': i > 0 && !f.groupKey }">
        <!-- Toggle: visual switch (hidden input + styled track/thumb) -->
        <template v-if="f.type === 'toggle'">
          <label class="!flex items-start gap-3 cursor-pointer !mb-0">
            <span class="settings-switch" :class="{ 'is-on': getBool(f.key) }">
              <input
                type="checkbox"
                class="settings-switch__input"
                :checked="getBool(f.key)"
                @change="setBool(f.key, ($event.target as HTMLInputElement).checked)"
              />
              <span class="settings-switch__track" aria-hidden="true">
                <span class="settings-switch__thumb"></span>
              </span>
            </span>
            <span class="flex-1 min-w-0">
              <span class="block !text-ink-100 !text-sm !font-medium">{{ t(f.labelKey) }}</span>
              <span class="block text-xs text-ink-300 mt-1 leading-relaxed">{{ t(f.helpKey) }}</span>
            </span>
          </label>
        </template>
        <template v-else>
          <label :for="f.key" class="!mb-1 !text-ink-100 !text-sm !font-medium">{{
            t(f.labelKey)
          }}</label>
          <p class="text-xs text-ink-300 mb-2 leading-relaxed">{{ t(f.helpKey) }}</p>
          <FaviconUploader
            v-if="f.type === 'favicon'"
            :model-value="getStr(f.key)"
            @update:model-value="setStr(f.key, $event)"
          />
          <OgImageUploader
            v-else-if="f.type === 'og_image'"
            :model-value="getStr(f.key)"
            @update:model-value="setStr(f.key, $event)"
          />
          <textarea
            v-else-if="f.type === 'textarea'"
            :id="f.key"
            :value="getStr(f.key)"
            :placeholder="f.placeholderKey ? t(f.placeholderKey) : undefined"
            :maxlength="f.maxlength"
            @input="setStr(f.key, ($event.target as HTMLTextAreaElement).value)"
          ></textarea>
          <input
            v-else
            :id="f.key"
            :type="f.inputType ?? 'text'"
            :value="getStr(f.key)"
            :placeholder="f.placeholderKey ? t(f.placeholderKey) : undefined"
            :pattern="f.pattern"
            :maxlength="f.maxlength"
            :title="f.patternHintKey ? t(f.patternHintKey) : undefined"
            :autocapitalize="f.lowercase ? 'none' : undefined"
            :spellcheck="f.lowercase ? false : undefined"
            :class="{ 'input-invalid': fieldErrors[f.key] }"
            :aria-invalid="fieldErrors[f.key] ? 'true' : undefined"
            @input="setStr(f.key, ($event.target as HTMLInputElement).value)"
            @blur="
              f.lowercase
                ? setStr(f.key, getStr(f.key).toLowerCase())
                : null
            "
          />
          <p
            v-if="f.patternHintKey && !fieldErrors[f.key]"
            class="text-xs text-ink-300 mt-1 italic opacity-70"
          >
            {{ t(f.patternHintKey) }}
          </p>
          <p v-if="fieldErrors[f.key]" class="text-xs text-red-300 mt-1">
            {{ fieldErrors[f.key] }}
          </p>
        </template>
      </div>
    </template>
  </div>

  <!-- "Language" section: pick the admin UI language. Default is auto
       (browser detection); choose a specific locale to override. -->
  <section class="tile mt-5">
    <h2 class="font-display text-xl mb-1">{{ t('settings.language.title') }}</h2>
    <p class="text-xs text-ink-300 mb-4">{{ t('settings.language.hint') }}</p>
    <div class="field">
      <label for="admin-locale" class="!mb-1 !text-ink-100 !text-sm !font-medium">
        {{ t('settings.language.label') }}
      </label>
      <select
        id="admin-locale"
        :value="localeSelection"
        @change="onLocaleSelect(($event.target as HTMLSelectElement).value)"
      >
        <option value="">
          {{
            t('settings.language.browser', {
              locale: localeName(detectedBrowserLocale),
            })
          }}
        </option>
        <option v-for="code in SUPPORTED_LOCALES" :key="code" :value="code">
          {{ localeName(code) }}
        </option>
      </select>
    </div>
  </section>

  <!-- "Security" section: link to change password always available -->
  <section class="tile mt-5">
    <h2 class="font-display text-xl mb-1">{{ t('settings.security.title') }}</h2>
    <p class="text-xs text-ink-300 mb-4">
      {{ t('settings.security.changePasswordHint') }}
    </p>
    <a :href="changePasswordHref" class="btn btn-ghost">
      <iconify-icon icon="lucide:key" width="18"></iconify-icon>
      {{ t('settings.security.changePassword') }}
    </a>
  </section>

  <!-- "2FA" section: two-factor TOTP authentication. Mirror of the
       superadmin logic (PlatformAdminController::twoFactorPanel) but
       per-user on the DB. -->
  <section class="tile mt-5">
    <div class="flex items-center gap-2 mb-1">
      <h2 class="font-display text-xl">{{ t('settings.security.twoFactor') }}</h2>
      <span
        v-if="twoFactorEnabled"
        class="text-xs font-medium rounded-full px-2 py-0.5 bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/30"
      >{{ t('settings.security.twoFactorStatusOn') }}</span>
      <span
        v-else
        class="text-xs font-medium rounded-full px-2 py-0.5 bg-ink-800 text-ink-300 ring-1 ring-white/10"
      >{{ t('settings.security.twoFactorStatusOff') }}</span>
    </div>
    <p class="text-xs text-ink-300 mb-4">
      {{ t('settings.security.twoFactorIntro') }}
    </p>
    <p
      v-if="twoFactorEnabled && twoFactorView === 'idle'"
      class="text-xs text-ink-300 mb-4"
    >
      {{ t('settings.security.twoFactorBackupRemaining') }}
      <code class="text-ink-100">{{ twoFactorRemainingBackup }}</code>
      {{ t('settings.security.twoFactorBackupRemainingHint') }}
    </p>

    <!-- ===== IDLE STATE: starting buttons ===== -->
    <div v-if="twoFactorView === 'idle'" class="flex flex-wrap gap-2">
      <button
        v-if="!twoFactorEnabled"
        type="button"
        class="btn btn-ghost"
        :disabled="twoFactorBusy"
        @click="start2faSetup"
      >
        <iconify-icon icon="lucide:shield-plus" width="18"></iconify-icon>
        {{ t('settings.security.twoFactorEnable') }}
      </button>
      <template v-else>
        <button
          type="button"
          class="btn btn-ghost"
          :disabled="twoFactorBusy"
          @click="regenerate2faBackup"
        >
          <iconify-icon icon="lucide:refresh-ccw" width="18"></iconify-icon>
          {{ t('settings.security.twoFactorRegenerateBackup') }}
        </button>
        <button
          type="button"
          class="btn btn-ghost"
          :disabled="twoFactorBusy"
          @click="twoFactorView = 'disabling'"
        >
          <iconify-icon icon="lucide:shield-off" width="18"></iconify-icon>
          {{ t('settings.security.twoFactorDisable') }}
        </button>
      </template>
    </div>

    <!-- ===== SETUP WIZARD: scan QR + verify code ===== -->
    <div v-else-if="twoFactorView === 'setup'" class="space-y-4">
      <p class="text-sm">
        <strong>1.</strong> {{ t('settings.security.twoFactorSetupStep1') }}
      </p>
      <div class="flex flex-wrap gap-4 items-start">
        <img
          v-if="twoFactorQrSrc"
          :src="twoFactorQrSrc"
          :alt="t('settings.security.twoFactorQrAlt')"
          width="220"
          height="220"
          class="rounded-md p-2 bg-white"
        />
        <div class="flex-1 min-w-[200px]">
          <p class="text-xs text-ink-300 mb-1">{{ t('settings.security.twoFactorSecretLabel') }}</p>
          <code class="block text-sm break-all bg-ink-800 rounded-md px-3 py-2 text-ink-100 ring-1 ring-white/10">{{ twoFactorSecret }}</code>
        </div>
      </div>
      <p class="text-sm">
        <strong>2.</strong> {{ t('settings.security.twoFactorSetupStep2') }}
      </p>
      <form class="flex flex-wrap gap-2" @submit.prevent="confirm2faSetup">
        <input
          v-model="twoFactorVerifyCode"
          type="text"
          inputmode="numeric"
          pattern="[0-9]{6}"
          maxlength="6"
          placeholder="000000"
          autocomplete="one-time-code"
          class="!w-32 !bg-ink-900 text-center tracking-widest"
          required
        />
        <button class="btn btn-primary" type="submit" :disabled="twoFactorBusy">
          <iconify-icon
            :icon="twoFactorBusy ? 'lucide:loader-circle' : 'lucide:check'"
            :class="twoFactorBusy ? 'animate-spin' : ''"
            width="18"
          ></iconify-icon>
          {{ t('settings.security.twoFactorConfirmAndEnable') }}
        </button>
        <button
          type="button"
          class="btn btn-ghost"
          :disabled="twoFactorBusy"
          @click="resetTwoFactorView"
        >{{ t('common.cancel') }}</button>
      </form>
      <p v-if="twoFactorError" class="text-xs text-red-300">{{ twoFactorError }}</p>
    </div>

    <!-- ===== BACKUP CODES (shown only once) ===== -->
    <div
      v-else-if="twoFactorView === 'showing_backup'"
      class="warn-box space-y-3 p-4 rounded-xl"
    >
      <p class="text-sm">
        <strong class="warn-strong">{{ t('settings.security.twoFactorBackupTitle') }}</strong>
        {{ t('settings.security.twoFactorBackupHint') }}
      </p>
      <pre class="grid grid-cols-2 gap-1 text-sm font-mono bg-ink-900 rounded-md p-3 text-ink-100">{{ twoFactorBackupCodes.join('\n') }}</pre>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn btn-ghost" @click="copyBackupCodes">
          <iconify-icon icon="lucide:copy" width="16"></iconify-icon>
          {{ t('settings.security.twoFactorBackupCopyAll') }}
        </button>
        <button type="button" class="btn btn-primary" @click="resetTwoFactorView">
          {{ t('settings.security.twoFactorBackupSaved') }}
        </button>
      </div>
    </div>

    <!-- ===== DISABLE: confirm with DISATTIVA ===== -->
    <div
      v-else-if="twoFactorView === 'disabling'"
      class="space-y-3 p-4 rounded-xl border border-red-500/40 bg-red-500/5"
    >
      <p class="text-sm">
        <strong class="text-red-300">{{ t('settings.security.twoFactorDisableTitle') }}</strong>
        {{ t('settings.security.twoFactorDisableIntro') }}
        <code class="px-2 py-0.5 rounded bg-ink-900 text-red-300">{{
          t('settings.security.twoFactorDisableConfirmToken')
        }}</code>
        {{ t('settings.security.twoFactorDisableInField') }}
      </p>
      <input
        v-model="twoFactorDisableConfirm"
        type="text"
        autocomplete="off"
        autocapitalize="none"
        spellcheck="false"
        :placeholder="t('settings.security.twoFactorDisableConfirmPlaceholder')"
        class="!bg-ink-900"
      />
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          class="btn btn-danger"
          :disabled="
            twoFactorDisableConfirm !== t('settings.security.twoFactorDisableConfirmToken') ||
            twoFactorBusy
          "
          @click="disable2fa"
        >
          <iconify-icon
            :icon="twoFactorBusy ? 'lucide:loader-circle' : 'lucide:shield-off'"
            :class="twoFactorBusy ? 'animate-spin' : ''"
            width="18"
          ></iconify-icon>
          {{ twoFactorBusy ? t('settings.security.twoFactorDisabling') : t('settings.security.twoFactorDisable') }}
        </button>
        <button
          type="button"
          class="btn btn-ghost"
          :disabled="twoFactorBusy"
          @click="resetTwoFactorView"
        >{{ t('common.cancel') }}</button>
      </div>
      <p v-if="twoFactorError" class="text-xs text-red-300">{{ twoFactorError }}</p>
    </div>
  </section>

  <!-- "Export" section: download a single index.html with everything inline.
       Openable from file:// or uploadable to any static hosting. -->
  <section class="tile mt-5">
    <h2 class="font-display text-xl mb-1">{{ t('settings.export.title') }}</h2>
    <p class="text-xs text-ink-300 mb-4">
      {{ t('settings.export.hint') }}
    </p>

    <!-- WARNING: the export is a STATIC SNAPSHOT. Anything that requires
         the tylio backend (form submit, email, tracking) doesn't work
         offline. For absolute clarity we list them here — the user
         knows exactly what they will get in the downloaded file. -->
    <div class="warn-box rounded-xl p-3 mb-4">
      <p class="warn-strong text-xs font-medium mb-2 flex items-center gap-1.5">
        <iconify-icon icon="lucide:triangle-alert" width="14"></iconify-icon>
        {{ t('settings.export.warningExclTitle') }}
      </p>
      <ul class="text-xs text-ink-300 space-y-1 list-disc pl-5">
        <li>
          <strong class="text-ink-100">{{ t('settings.export.warningContactTile') }}</strong>
          {{ t('settings.export.warningContactTileNote') }}
        </li>
        <li>
          <strong class="text-ink-100">{{ t('settings.export.warningEmailNotify') }}</strong>
          {{ t('settings.export.warningEmailNotifyNote') }}
        </li>
        <li>
          <strong class="text-ink-100">{{ t('settings.export.warningClickCount') }}</strong>
          {{ t('settings.export.warningClickCountNote') }}
        </li>
        <li>
          <strong class="text-ink-100">{{ t('settings.export.warningVisitsCount') }}</strong>,
          <strong class="text-ink-100">{{ t('settings.export.warningSitemap') }}</strong>,
          <strong class="text-ink-100">{{ t('settings.export.warningRobots') }}</strong>,
          <strong class="text-ink-100">{{ t('settings.export.warningPwa') }}</strong>
          {{ t('settings.export.warningDynamicNote') }}
        </li>
        <li>
          <strong class="text-ink-100">{{ t('settings.export.warningAdmin') }}</strong>
          {{ t('settings.export.warningAdminNote') }}
        </li>
      </ul>
      <p class="text-xs text-ink-300 mt-2">
        {{ t('settings.export.warningFooter') }}
      </p>
    </div>

    <button
      type="button"
      class="btn btn-ghost"
      :disabled="exporting"
      @click="downloadHome"
    >
      <iconify-icon
        :icon="exporting ? 'lucide:loader-circle' : 'lucide:download'"
        :class="exporting ? 'animate-spin' : ''"
        width="18"
      ></iconify-icon>
      {{ exporting ? t('settings.export.generating') : t('settings.export.download') }}
    </button>
    <p v-if="exportError" class="text-xs text-red-300 mt-2">{{ exportError }}</p>
  </section>

  <!-- "Danger" section: permanent account + site deletion -->
  <section class="tile mt-5 settings-danger">
    <h2 class="font-display text-xl mb-1">{{ t('settings.account.title') }}</h2>
    <p class="text-sm mb-4">
      {{ t('settings.account.deleteIntro') }}
    </p>
    <button
      v-if="!deleteOpen"
      type="button"
      class="btn btn-danger"
      @click="deleteOpen = true"
    >
      <iconify-icon icon="lucide:trash-2" width="18"></iconify-icon>
      {{ t('settings.account.deleteAccountAndPage') }}
    </button>

    <div v-else class="space-y-3 p-4 rounded-xl border border-red-500/40 bg-red-500/5">
      <p class="text-sm">
        <strong class="text-red-300">{{ t('settings.account.deleteIrreversible') }}</strong>
        {{ t('settings.account.deleteTypeToConfirm') }}
        <code class="px-2 py-0.5 rounded bg-ink-900 text-red-300">{{
          t('settings.account.deleteConfirmToken')
        }}</code>
        {{ t('settings.account.deleteTypeInField') }}
      </p>
      <input
        v-model="deleteConfirmText"
        type="text"
        autocomplete="off"
        autocapitalize="none"
        spellcheck="false"
        :placeholder="t('settings.account.deleteConfirmPlaceholder')"
        class="!bg-ink-900"
      />
      <p v-if="deleteError" class="text-sm text-red-300">{{ deleteError }}</p>
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          class="btn btn-danger"
          :disabled="
            deleteConfirmText !== t('settings.account.deleteConfirmToken') || deleting
          "
          @click="performDelete"
        >
          <iconify-icon
            :icon="deleting ? 'lucide:loader-circle' : 'lucide:trash-2'"
            width="18"
            :class="deleting ? 'animate-spin' : ''"
          ></iconify-icon>
          {{ deleting ? t('settings.account.deleting') : t('settings.account.deletePermanently') }}
        </button>
        <button
          type="button"
          class="btn btn-ghost"
          :disabled="deleting"
          @click="(deleteOpen = false), (deleteConfirmText = ''), (deleteError = '')"
        >
          {{ t('common.cancel') }}
        </button>
      </div>
    </div>
  </section>
</template>
