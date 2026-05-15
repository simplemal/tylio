<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../api'
import { ApiError } from '../types'
import type { EmailVerificationStatus, Settings, UpdateCheckOk } from '../types'
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
  // Special case: site.admin_email lives outside the schema-driven
  // `known` array (it has its own dedicated section with the verification
  // widget). Validate it the same way as any inputType=email field.
  if (key === 'site.admin_email') {
    if (value === '') {
      delete fieldErrors.value[key]
      return
    }
    const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
    if (!emailRe.test(value)) {
      fieldErrors.value[key] = t('settings.errors.invalidEmail')
    } else {
      delete fieldErrors.value[key]
    }
    return
  }

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

  // NOTE: communications group is rendered as a dedicated section
  // below (admin email + verification widget). The legacy
  // `contact.notify_email` field is gone — see
  // `app/Database/migrations/0007_admin_email.sql` for the migration.
]
// NOTE: maintenance mode used to live here in earlier iterations
// (first inside `known` as a regular field group, then as a
// standalone card at the top of this view). It now has its own
// dedicated route at /maintenance with its own sidebar nav item —
// flipping the switch is a one-purpose, urgent action and shouldn't
// share UI real estate with the rest of the settings.

// ===== Update check =====
// Hits GET /api/admin/update-check on mount. The card hides itself
// entirely when the API returns 404 (OSS install without the endpoint
// deployed) or when the body has `disabled: true` (SaaS overlay: the
// platform operator updates centrally, tenants must not see this UI).
// On a transient network failure (`latest === null`) we keep the card
// visible but show a neutral "couldn't verify" status with a retry
// link — distinct from the explicit SaaS-disabled signal.
const updateCheck = ref<UpdateCheckOk | null>(null)
const updateCheckHidden = ref(false)
const updateChecking = ref(false)
const updateError = ref('')
const showChangelog = ref(false)
const showHowTo = ref(false)

async function loadUpdateCheck(force = false): Promise<void> {
  if (updateChecking.value) return
  updateChecking.value = true
  updateError.value = ''
  try {
    const r = await api.updateCheck(force)
    if ('disabled' in r && r.disabled) {
      // SaaS overlay (or any future operator-disabled deployment).
      updateCheckHidden.value = true
      updateCheck.value = null
    } else {
      // After narrowing with `'disabled' in r`, TS still keeps the union
      // here because the discriminant is OPTIONAL on the OK shape (it
      // simply doesn't appear). Cast to the OK type explicitly.
      updateCheck.value = r as UpdateCheckOk
    }
  } catch (e: unknown) {
    if (e instanceof ApiError && e.status === 404) {
      // OSS install on a version older than this SPA — endpoint not
      // deployed yet. Hide the card silently.
      updateCheckHidden.value = true
    } else {
      // Network or other transient failure: surface a short error so the
      // user can retry, but keep the card visible.
      updateError.value =
        e instanceof ApiError
          ? t('settings.errors.errorWithStatus', { status: e.status })
          : t('settings.errors.networkError')
    }
  } finally {
    updateChecking.value = false
  }
}

// "Verificato Xs/min/h/d fa" relative timestamp. Locale-aware via the
// existing i18n setup; pure client-side (the server timestamp is the
// reference instant).
function relativeFromIso(iso: string): string {
  const then = Date.parse(iso)
  if (!Number.isFinite(then)) return ''
  const diffMs = Date.now() - then
  const sec = Math.max(0, Math.floor(diffMs / 1000))
  if (sec < 60) return t('settings.update.timeSecondsAgo', { n: sec })
  const min = Math.floor(sec / 60)
  if (min < 60) return t('settings.update.timeMinutesAgo', { n: min })
  const hr = Math.floor(min / 60)
  if (hr < 24) return t('settings.update.timeHoursAgo', { n: hr })
  const days = Math.floor(hr / 24)
  return t('settings.update.timeDaysAgo', { n: days })
}

const updateLastCheckedLabel = computed(() => {
  const c = updateCheck.value
  if (!c || !c.last_checked) return ''
  const rel = relativeFromIso(c.last_checked)
  return rel ? t('settings.update.lastChecked', { when: rel }) : ''
})

// Status of the card: drives the colored dot + the headline message.
// 'ok' (green), 'outdated' (warning amber), 'unknown' (grey, network
// failure or no GitHub release yet).
type UpdateStatus = 'ok' | 'outdated' | 'unknown'
const updateStatus = computed<UpdateStatus>(() => {
  const c = updateCheck.value
  if (!c) return 'unknown'
  if (c.latest === null) return 'unknown'
  return c.is_outdated ? 'outdated' : 'ok'
})

onMounted(async () => {
  settings.value = (await api.getSettings()).settings
  for (const k of known) {
    if (!(k.key in settings.value)) {
      // toggle: default true (indexing on by default), text: empty string
      settings.value[k.key] = k.type === 'toggle' ? k.key === 'seo.robots_index' : ''
    }
  }
  // Default the admin email to empty if the migration hasn't run yet on
  // an older snapshot (defensive).
  if (!('site.admin_email' in settings.value)) {
    settings.value['site.admin_email'] = ''
  }
  // Validate values already in the DB: if any is invalid (legacy),
  // surface the inline error now without waiting for the user to edit it.
  for (const k of known) {
    if (k.type === 'text') validateField(k.key, getStr(k.key))
  }
  validateField('site.admin_email', getStr('site.admin_email'))
  // Load 2FA status in parallel (doesn't block the form render).
  void load2faStatus()
  // Update check: GitHub release lookup. Cached server-side for 24h.
  void loadUpdateCheck(false)
  // Admin email verification status (drives the verified tick / pending
  // code widget). Independent from the settings fetch above so a slow
  // call doesn't gate the rest of the form.
  void loadEmailVerification()
})

async function save() {
  // Re-validate all fields before submit (catches the case where the
  // user filled a field WITHOUT ever firing a recent input event).
  for (const f of known) {
    if (f.type === 'text') validateField(f.key, getStr(f.key))
  }
  // The admin email field is rendered outside the schema-driven
  // section but uses the same fieldErrors map; re-validate it too.
  validateField('site.admin_email', getStr('site.admin_email'))
  if (Object.keys(fieldErrors.value).length > 0) {
    saveError.value = t('settings.errors.invalidFields')
    return
  }

  saving.value = true
  saveError.value = ''
  try {
    const resp = await api.updateSettings(settings.value)
    settings.value = resp.settings
    // Sync the AppShell banner without a separate fetch: we already
    // have the freshly-saved value here.
    site.setFromSettings(settings.value as Record<string, unknown>)
    // If the admin email changed, the server fired a fresh verification
    // request — refresh the status widget so the countdown + tick show
    // the new reality, and surface a one-shot toast.
    if (resp.email_changed) {
      emailJustResent.value = true
      void loadEmailVerification()
      setTimeout(() => { emailJustResent.value = false }, 6000)
    }
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

// ===== Admin email verification =====
// Drives the Communications section: shows the current email, a
// verified tick OR a pending-code widget (input + countdown + resend).
//
// The verification request is auto-fired by the server on:
//   - install (when the user supplied an email),
//   - settings.update() that changes site.admin_email.
// So the SPA does NOT have a "send code" button — only "Verify" and
// "Resend (cooldown)". On a fresh page load, the status endpoint
// tells us the current state.
const emailVerification = ref<EmailVerificationStatus | null>(null)
const emailVerifyCode = ref('')
const emailVerifyBusy = ref(false)
const emailVerifyError = ref('')
const emailVerifyJustOk = ref(false)
const emailJustResent = ref(false)
const emailCooldownTick = ref(0)

// "Verified" requires BOTH a non-empty admin_email AND a verified_at
// timestamp from the server. If either is missing the badge stays
// hidden — protects against any inconsistent server state where the
// timestamp got set without an email row to attach it to.
const emailIsVerified = computed(
  () => !!emailVerification.value?.verified_at && getStr('site.admin_email') !== '',
)
const emailHasPending = computed(() => !!emailVerification.value?.pending)
const emailCooldownRemaining = computed(() => {
  // `emailCooldownTick` is mutated by setInterval so this re-derives.
  void emailCooldownTick.value
  const p = emailVerification.value?.pending
  if (!p) return 0
  const tEnd = Date.parse(p.can_resend_at.replace(' ', 'T') + 'Z')
  if (!Number.isFinite(tEnd)) return 0
  return Math.max(0, Math.floor((tEnd - Date.now()) / 1000))
})
const emailCooldownLabel = computed(() => {
  const s = emailCooldownRemaining.value
  if (s <= 0) return ''
  const mm = Math.floor(s / 60)
  const ss = s % 60
  return `${mm}:${ss.toString().padStart(2, '0')}`
})

async function loadEmailVerification(): Promise<void> {
  try {
    emailVerification.value = await api.emailVerificationStatus()
  } catch {
    /* silent: the widget hides itself when state is unknown */
  }
}

async function submitEmailVerifyCode(): Promise<void> {
  if (emailVerifyBusy.value) return
  const code = emailVerifyCode.value.trim().toUpperCase()
  if (code.length !== 6) {
    emailVerifyError.value = t('settings.email.errors.codeShape')
    return
  }
  emailVerifyBusy.value = true
  emailVerifyError.value = ''
  try {
    await api.verifyEmailCode(code)
    emailVerifyJustOk.value = true
    emailVerifyCode.value = ''
    await loadEmailVerification()
    setTimeout(() => { emailVerifyJustOk.value = false }, 6000)
  } catch (e: unknown) {
    if (e instanceof ApiError && e.status === 422) {
      emailVerifyError.value = t('settings.email.errors.codeWrong')
    } else if (e instanceof ApiError && e.status === 429) {
      emailVerifyError.value = t('settings.email.errors.rateLimited')
    } else {
      emailVerifyError.value = t('settings.email.errors.network')
    }
    await loadEmailVerification()
  } finally {
    emailVerifyBusy.value = false
  }
}

async function requestNewEmailCode(): Promise<void> {
  if (emailVerifyBusy.value) return
  if (emailCooldownRemaining.value > 0) return
  emailVerifyBusy.value = true
  emailVerifyError.value = ''
  try {
    await api.requestEmailCode()
    emailJustResent.value = true
    await loadEmailVerification()
    setTimeout(() => { emailJustResent.value = false }, 6000)
  } catch (e: unknown) {
    if (e instanceof ApiError && e.status === 429) {
      emailVerifyError.value = t('settings.email.errors.rateLimited')
    } else {
      emailVerifyError.value = t('settings.email.errors.network')
    }
    await loadEmailVerification()
  } finally {
    emailVerifyBusy.value = false
  }
}

// Live tick so the cooldown label decrements without a roundtrip.
// Cleared automatically by Vue when the component unmounts via the
// onScopeDispose hook below.
const cooldownTimer = window.setInterval(() => { emailCooldownTick.value++ }, 1000)
import { onScopeDispose } from 'vue'
onScopeDispose(() => window.clearInterval(cooldownTimer))

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

// Suggested filename for the full-site archive download. The server
// computes its own filename (with a timestamp) but setting `download` on
// the <a> tag lets the browser save it without prompting on the user's
// preferred location.
const archiveDownloadName = computed(() => {
  const today = new Date().toISOString().slice(0, 10).replace(/-/g, '')
  return `tylio-export-${today}.tar.gz`
})

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

  <!-- ===== "Aggiornamenti tylio" card =====
       Compares the locally installed version with the latest GitHub
       release. Hidden entirely when the API returns 404 (route not
       deployed) or `{disabled: true}` (SaaS overlay disables it so
       tenants don't see admin commands they can't run — the platform
       operator updates centrally). -->
  <section v-if="!updateCheckHidden" class="tile mb-5 update-card">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div class="min-w-[200px] flex-1">
        <h2 class="font-display text-xl mb-2">{{ t('settings.update.title') }}</h2>
        <p class="text-xs text-ink-300 mb-3 leading-relaxed">
          {{ t('settings.update.intro') }}
        </p>
        <dl class="text-sm grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 mb-2">
          <dt class="text-ink-300">{{ t('settings.update.installed') }}</dt>
          <dd>
            <code class="px-1.5 py-0.5 rounded bg-ink-900 text-ink-100">{{
              updateCheck?.current ?? t('settings.update.unknownVersion')
            }}</code>
          </dd>
          <dt class="text-ink-300">{{ t('settings.update.available') }}</dt>
          <dd>
            <code v-if="updateCheck?.latest" class="px-1.5 py-0.5 rounded bg-ink-900 text-ink-100">{{
              updateCheck.latest
            }}</code>
            <span v-else class="text-ink-300 italic">{{ t('settings.update.unknownVersion') }}</span>
          </dd>
        </dl>
        <p v-if="updateLastCheckedLabel" class="text-xs text-ink-300">
          {{ updateLastCheckedLabel }}
          <button
            type="button"
            class="ml-2 underline decoration-dotted underline-offset-2 hover:text-ink-100 disabled:opacity-50"
            :disabled="updateChecking"
            @click="loadUpdateCheck(true)"
          >{{ updateChecking ? t('settings.update.checking') : t('settings.update.checkNow') }}</button>
        </p>
        <p v-if="updateError" class="text-xs text-red-300 mt-1">{{ updateError }}</p>
      </div>

      <!-- Status badge: green / amber / grey depending on the compare result. -->
      <div class="flex items-center gap-2 px-3 py-2 rounded-full bg-ink-900 ring-1 ring-white/10">
        <template v-if="updateStatus === 'ok'">
          <span class="update-dot update-dot--ok" aria-hidden="true"></span>
          <span class="text-sm text-ink-100">{{ t('settings.update.statusUpToDate') }}</span>
        </template>
        <template v-else-if="updateStatus === 'outdated'">
          <span class="warn-dot inline-block w-2.5 h-2.5 rounded-full" aria-hidden="true"></span>
          <span class="text-sm warn-strong">{{ t('settings.update.statusOutdated') }}</span>
        </template>
        <template v-else>
          <span class="update-dot update-dot--unknown" aria-hidden="true"></span>
          <span class="text-sm text-ink-300">{{ t('settings.update.statusUnknown') }}</span>
        </template>
      </div>
    </div>

    <!-- Changelog + how-to-update: shown ONLY when the local version is
         behind the latest release. The two collapsibles are independent
         so the user can read the changelog without committing to the
         upgrade flow, or vice versa. -->
    <div v-if="updateStatus === 'outdated' && updateCheck" class="mt-4 space-y-3">
      <div v-if="updateCheck.changelog_html">
        <button
          type="button"
          class="btn btn-ghost update-toggle"
          @click="showChangelog = !showChangelog"
        >
          <iconify-icon
            :icon="showChangelog ? 'lucide:chevron-down' : 'lucide:chevron-right'"
            width="16"
          ></iconify-icon>
          {{ showChangelog ? t('settings.update.hideChangelog') : t('settings.update.showChangelog') }}
        </button>
        <div
          v-if="showChangelog"
          class="update-changelog mt-2 text-sm leading-relaxed"
          v-html="updateCheck.changelog_html"
        ></div>
        <p v-if="updateCheck.release_url && showChangelog" class="text-xs mt-2">
          <a
            :href="updateCheck.release_url"
            target="_blank"
            rel="noopener noreferrer"
            class="text-ink-300 hover:text-ink-100 underline decoration-dotted"
          >
            {{ t('settings.update.viewOnGithub') }}
            <iconify-icon icon="lucide:external-link" width="12"></iconify-icon>
          </a>
        </p>
      </div>

      <div>
        <button
          type="button"
          class="btn btn-ghost update-toggle"
          @click="showHowTo = !showHowTo"
        >
          <iconify-icon
            :icon="showHowTo ? 'lucide:chevron-down' : 'lucide:chevron-right'"
            width="16"
          ></iconify-icon>
          {{ showHowTo ? t('settings.update.hideHowTo') : t('settings.update.showHowTo') }}
        </button>
        <div v-if="showHowTo" class="mt-2">
          <p class="text-xs text-ink-300 mb-2 leading-relaxed">
            {{ t('settings.update.howToIntro') }}
          </p>
          <pre class="update-cmds">{{ t('settings.update.commands') }}</pre>
          <p class="text-xs text-ink-300 mt-2 leading-relaxed">
            {{ t('settings.update.howToNote') }}
          </p>
        </div>
      </div>
    </div>
  </section>

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

  <!-- "Communications" section: the admin email + verification widget.
       Replaces the legacy contact.notify_email field. The verification
       request is auto-fired on email change (server-side) — the SPA
       never has a "send code" button, only "Verify" + "Resend (X:YY)". -->
  <section id="communications" class="tile mt-5 scroll-mt-24">
    <h2 class="font-display text-xl mt-2 pb-1 border-b border-white/10 mb-3">
      {{ t('settings.groups.communications') }}
    </h2>
    <p class="text-xs text-ink-300 mb-4 leading-relaxed">
      {{ t('settings.email.intro') }}
    </p>
    <div class="field">
      <label for="site.admin_email" class="!mb-1 !text-ink-100 !text-sm !font-medium flex items-center gap-2">
        {{ t('settings.email.label') }}
        <!-- Verified tick / not-verified pill, always visible to the right
             of the label so the admin knows the state at a glance. -->
        <span
          v-if="emailIsVerified"
          class="inline-flex items-center gap-1 text-xs font-medium rounded-full px-2 py-0.5 bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/30"
          :title="t('settings.email.verifiedTickTitle')"
        >
          <iconify-icon icon="lucide:badge-check" width="14"></iconify-icon>
          {{ t('settings.email.verifiedTick') }}
        </span>
        <span
          v-else-if="getStr('site.admin_email') !== ''"
          class="inline-flex items-center gap-1 text-xs font-medium rounded-full px-2 py-0.5 bg-ink-800 text-ink-300 ring-1 ring-white/10"
          :title="t('settings.email.notVerifiedTickTitle')"
        >
          <iconify-icon icon="lucide:badge-alert" width="14"></iconify-icon>
          {{ t('settings.email.notVerifiedTick') }}
        </span>
      </label>
      <p class="text-xs text-ink-300 mb-2 leading-relaxed">{{ t('settings.email.help') }}</p>
      <input
        id="site.admin_email"
        type="email"
        autocomplete="email"
        :value="getStr('site.admin_email')"
        :placeholder="t('settings.email.placeholder')"
        :class="{ 'input-invalid': fieldErrors['site.admin_email'] }"
        :aria-invalid="fieldErrors['site.admin_email'] ? 'true' : undefined"
        @input="setStr('site.admin_email', ($event.target as HTMLInputElement).value)"
      />
      <p
        v-if="getStr('site.admin_email') !== '' && getStr('site.admin_email') !== (emailVerification?.email ?? '')"
        class="text-xs mt-1"
        :class="fieldErrors['site.admin_email'] ? 'text-red-300' : 'text-ink-300 italic opacity-80'"
      >
        {{ t('settings.email.changedHint') }}
      </p>
      <p v-if="fieldErrors['site.admin_email']" class="text-xs text-red-300 mt-1">
        {{ fieldErrors['site.admin_email'] }}
      </p>
    </div>

    <!-- Pending-code widget. Visible when an unverified address has an
         active verification row (issued by the server on the last save). -->
    <div
      v-if="!emailIsVerified && emailHasPending"
      class="field pt-4 border-t border-white/5"
    >
      <label for="email-verify-code" class="!mb-1 !text-ink-100 !text-sm !font-medium">
        {{ t('settings.email.codeInputLabel') }}
      </label>
      <p class="text-xs text-ink-300 mb-2 leading-relaxed">
        {{ t('settings.email.codeInputHelp', { email: emailVerification?.email ?? '' }) }}
      </p>
      <div class="flex flex-wrap gap-2 items-start">
        <input
          id="email-verify-code"
          v-model="emailVerifyCode"
          type="text"
          inputmode="text"
          autocapitalize="characters"
          spellcheck="false"
          maxlength="6"
          minlength="6"
          pattern="[23456789ABCDEFGHJKMNPQRSTVWXYZ]{6}"
          :placeholder="t('settings.email.codePlaceholder')"
          class="!w-44 !bg-ink-900 text-center tracking-[0.4em] uppercase"
          @input="emailVerifyCode = ($event.target as HTMLInputElement).value.toUpperCase()"
        />
        <button
          type="button"
          class="btn btn-primary"
          :disabled="emailVerifyBusy || emailVerifyCode.trim().length !== 6"
          @click="submitEmailVerifyCode"
        >
          <iconify-icon
            :icon="emailVerifyBusy ? 'lucide:loader-circle' : 'lucide:check'"
            :class="emailVerifyBusy ? 'animate-spin' : ''"
            width="18"
          ></iconify-icon>
          {{ t('settings.email.verifyBtn') }}
        </button>
        <button
          type="button"
          class="btn btn-ghost"
          :disabled="emailVerifyBusy || emailCooldownRemaining > 0"
          @click="requestNewEmailCode"
        >
          <iconify-icon icon="lucide:refresh-ccw" width="16"></iconify-icon>
          {{ emailCooldownRemaining > 0
            ? t('settings.email.resendCooldown', { time: emailCooldownLabel })
            : t('settings.email.resendBtn')
          }}
        </button>
      </div>
      <p v-if="emailVerifyError" class="text-xs text-red-300 mt-2">{{ emailVerifyError }}</p>
      <p v-if="emailJustResent" class="text-xs mt-2 text-emerald-300">
        {{ t('settings.email.resentToast') }}
      </p>
      <p v-if="emailVerifyJustOk" class="text-xs mt-2 text-emerald-300">
        {{ t('settings.email.verifiedToast') }}
      </p>
    </div>
  </section>

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

  <!-- "Esporta sito" section: download a tar.gz with the full site state
       (DB rows + uploads + favicons). Re-importable on any tylio instance
       via /admin/import or /install/import — useful as backup AND as a
       migration vehicle (SaaS → OSS, or between OSS installations). -->
  <section class="tile mt-5">
    <h2 class="font-display text-xl mb-1">{{ t('settings.archive.title') }}</h2>
    <p class="text-xs text-ink-300 mb-4">
      {{ t('settings.archive.hint') }}
    </p>
    <a
      href="/admin/export"
      class="btn btn-ghost"
      :download="archiveDownloadName"
    >
      <iconify-icon icon="lucide:archive" width="18"></iconify-icon>
      {{ t('settings.archive.download') }}
    </a>
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

<style scoped>
/* Status dots inside the "Aggiornamenti tylio" card. The amber one
   reuses the existing `.warn-dot` utility (rgb(var(--warning-rgb)));
   here we add the green ("up to date") + grey ("couldn't verify")
   variants, both derived from the active palette so they stay
   consistent across themes. */
.update-dot {
  display: inline-block;
  width: 0.625rem;
  height: 0.625rem;
  border-radius: 9999px;
}
.update-dot--ok {
  /* Tailwind emerald-400 (#34d399). The fixed hue is fine because the
     [data-theme-mode="light"] override in the global style.css already
     rewrites emerald-300/400 to a darker variant for light palettes. */
  background: rgb(52 211 153);
}
.update-dot--unknown {
  background: rgb(var(--ink-300-rgb));
}

/* Compact ghost-button used by the collapsible toggles in the card. */
.update-toggle {
  padding-top: 0.4rem;
  padding-bottom: 0.4rem;
  font-size: 0.85rem;
}

/* Changelog rendered from the GitHub release body. The Markdown was
   sanitized server-side by Util\Markdown (commonmark with
   html_input=strip), so v-html is safe. Styling: keep it readable on
   the Neon · scuro surface, tighten the default Tailwind base
   resets so headings inside the changelog don't tower over the card. */
.update-changelog {
  background: rgb(var(--ink-800-rgb));
  border: 1px solid rgb(var(--ink-100-rgb) / 0.08);
  border-radius: 0.75rem;
  padding: 0.9rem 1rem;
  max-height: 360px;
  overflow-y: auto;
}
.update-changelog :deep(h1),
.update-changelog :deep(h2),
.update-changelog :deep(h3) {
  font-weight: 600;
  margin: 0.6em 0 0.3em;
  font-size: 1rem;
}
.update-changelog :deep(p) {
  margin: 0.4em 0;
}
.update-changelog :deep(ul),
.update-changelog :deep(ol) {
  margin: 0.4em 0;
  padding-left: 1.4em;
}
.update-changelog :deep(ul) { list-style: disc; }
.update-changelog :deep(ol) { list-style: decimal; }
.update-changelog :deep(li) {
  margin: 0.15em 0;
}
.update-changelog :deep(code) {
  background: rgb(var(--ink-900-rgb));
  padding: 0.05em 0.4em;
  border-radius: 0.25em;
  font-size: 0.85em;
}
.update-changelog :deep(pre) {
  background: rgb(var(--ink-900-rgb));
  padding: 0.7em 0.9em;
  border-radius: 0.5em;
  overflow-x: auto;
  font-size: 0.85em;
  margin: 0.5em 0;
}
.update-changelog :deep(pre code) {
  background: transparent;
  padding: 0;
}
.update-changelog :deep(a) {
  color: rgb(var(--accent-rgb));
  text-decoration: underline;
  text-decoration-style: dotted;
  text-underline-offset: 2px;
}

/* Copy-paste upgrade commands. Looks like a terminal snippet so the
   user reads it as "run this in a shell". */
.update-cmds {
  background: rgb(var(--ink-900-rgb));
  border: 1px solid rgb(var(--ink-100-rgb) / 0.08);
  border-radius: 0.75rem;
  padding: 0.9rem 1rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.82rem;
  line-height: 1.55;
  white-space: pre;
  overflow-x: auto;
  color: rgb(var(--ink-100-rgb));
}
</style>
