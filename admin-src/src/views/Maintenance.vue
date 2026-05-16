<script setup lang="ts">
/**
 * Maintenance view — dedicated page (separate from Settings) to flip
 * the site's maintenance flag. Has its own sidebar entry so the user
 * finds it immediately when they need to take the site offline.
 *
 * What it owns:
 *   - settings.site.maintenance (bool)
 *   - settings.site.maintenance_message (text, max 500)
 *
 * Why a dedicated route instead of a section inside Settings:
 *   - flipping the switch is a one-purpose, urgent action; having it
 *     hide behind a "Settings" tab + several scrolls is friction.
 *   - the "two Save buttons on the same page" UX from the previous
 *     iteration is gone: Settings has its global Save, Maintenance
 *     has its own Save, no overlap.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../api'
import { ApiError } from '../types'
import type { Settings, UpdateCheckOk } from '../types'
import { useSite } from '../stores/site'

const { t } = useI18n()
const site = useSite()

// Local form state. Loaded from /api/settings on mount. We carry only
// the two maintenance keys; saving sends a partial payload that the
// SettingsController upserts per-key, leaving the rest untouched.
const loading = ref(true)
const saving = ref(false)
const saveError = ref('')
const justSaved = ref(false)
const flag = ref(false)
const message = ref('')
// Snapshot of the values currently in the DB (last successful load or
// save) — used both for the dirty-flag and for "discard changes".
const savedFlag = ref(false)
const savedMessage = ref('')

const isDirty = computed(
  () => flag.value !== savedFlag.value || message.value !== savedMessage.value,
)

onMounted(async () => {
  try {
    const r = await api.getSettings()
    const s = r.settings
    flag.value = Boolean(s['site.maintenance'])
    const m = s['site.maintenance_message']
    message.value = typeof m === 'string' ? m : ''
    savedFlag.value = flag.value
    savedMessage.value = message.value
    // Sync the AppShell banner state too — Settings.vue used to do
    // this on its own load; now that the maintenance section is split
    // off, this view becomes the canonical updater.
    site.setFromSettings(s as Record<string, unknown>)
  } catch {
    // silent — the form just shows defaults (off / empty)
  } finally {
    loading.value = false
  }
})

async function save(): Promise<void> {
  if (saving.value) return
  saving.value = true
  saveError.value = ''
  justSaved.value = false
  try {
    const payload = {
      'site.maintenance': flag.value,
      'site.maintenance_message': message.value,
    } as Settings
    const r = await api.updateSettings(payload)
    flag.value = Boolean(r.settings['site.maintenance'])
    const m = r.settings['site.maintenance_message']
    message.value = typeof m === 'string' ? m : ''
    savedFlag.value = flag.value
    savedMessage.value = message.value
    site.setFromSettings(r.settings as Record<string, unknown>)
    justSaved.value = true
    // Auto-fade the "saved" confirmation after a couple of seconds so
    // the button label can go back to "Save" without the user needing
    // to touch anything.
    window.setTimeout(() => { justSaved.value = false }, 2200)
  } catch (e: unknown) {
    saveError.value =
      e instanceof ApiError
        ? t('settings.errors.errorWithStatus', { status: e.status })
        : t('settings.errors.networkError')
  } finally {
    saving.value = false
  }
}

function discardChanges(): void {
  flag.value = savedFlag.value
  message.value = savedMessage.value
}

const updateCheck = ref<UpdateCheckOk | null>(null)
const updateCheckHidden = ref(false)
const updateChecking = ref(false)
const updateError = ref('')
const showChangelog = ref(false)

const updateState = ref<{
  in_progress: boolean
  last_update_at: string
  last_version: string
  last_error: string
  last_backup: string
} | null>(null)

const applying = ref(false)
const applyResult = ref<{
  ok: boolean
  message: string
  newVersion?: string
  backupPath?: string
} | null>(null)

async function loadUpdateCheck(force = false): Promise<void> {
  if (updateChecking.value) return
  updateChecking.value = true
  updateError.value = ''
  try {
    const r = await api.updateCheck(force)
    if ('disabled' in r && r.disabled) {
      updateCheckHidden.value = true
      updateCheck.value = null
    } else {
      updateCheck.value = r as UpdateCheckOk
    }
  } catch (e: unknown) {
    if (e instanceof ApiError && e.status === 404) {
      updateCheckHidden.value = true
    } else {
      updateError.value =
        e instanceof ApiError
          ? t('settings.errors.errorWithStatus', { status: e.status })
          : t('settings.errors.networkError')
    }
  } finally {
    updateChecking.value = false
  }
}

async function loadUpdateState(): Promise<void> {
  try {
    const r = await api.updateState()
    if ('disabled' in r && r.disabled) {
      updateState.value = null
      return
    }
    updateState.value = r as {
      in_progress: boolean
      last_update_at: string
      last_version: string
      last_error: string
      last_backup: string
    }
  } catch {
    updateState.value = null
  }
}

async function applyUpdate(): Promise<void> {
  if (applying.value) return
  applying.value = true
  applyResult.value = null
  try {
    const r = await api.updateApply()
    if (r.ok) {
      applyResult.value = {
        ok: true,
        message: t('settings.update.applySuccess', { version: r.new_version }),
        newVersion: r.new_version,
        backupPath: r.backup_path,
      }
      await loadUpdateCheck(true)
      await loadUpdateState()
    } else {
      applyResult.value = {
        ok: false,
        message: r.detail || t('settings.update.applyGenericError'),
        backupPath: r.backup_path,
      }
    }
  } catch (e: unknown) {
    let errMessage: string
    if (e instanceof ApiError && typeof e.data?.detail === 'string' && e.data.detail) {
      errMessage = e.data.detail
    } else if (e instanceof ApiError) {
      errMessage = t('settings.errors.errorWithStatus', { status: e.status })
    } else {
      errMessage = t('settings.errors.networkError')
    }
    applyResult.value = { ok: false, message: errMessage }
    await loadUpdateState()
  } finally {
    applying.value = false
  }
}

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

type UpdateStatus = 'ok' | 'outdated' | 'unknown'
const updateStatus = computed<UpdateStatus>(() => {
  const c = updateCheck.value
  if (!c) return 'unknown'
  if (c.latest === null) return 'unknown'
  return c.is_outdated ? 'outdated' : 'ok'
})

onMounted(() => {
  void loadUpdateCheck(false)
  void loadUpdateState()
})
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
      <p class="eyebrow">{{ t('maintenance.eyebrow') }}</p>
      <h1 class="heading">{{ t('maintenance.title') }}</h1>
    </div>
    <span
      v-if="flag"
      class="warn-pill text-sm font-medium inline-flex items-center gap-2 px-3 py-1.5 rounded-full"
    >
      <span class="relative inline-flex">
        <span class="warn-dot absolute inset-0 rounded-full animate-ping opacity-60"></span>
        <span class="warn-dot relative w-2 h-2 rounded-full"></span>
      </span>
      {{ t('maintenance.statusOn') }}
    </span>
  </div>

  <p
    v-if="saveError"
    class="text-sm text-red-300 mb-4 p-3 rounded-lg bg-red-500/10 border border-red-500/30"
  >
    {{ saveError }}
  </p>

  <!-- Loading placeholder: prevents the "off, then flips to on" jump
       on slow networks that would happen if we rendered the form with
       defaults while waiting for the API. -->
  <div
    v-if="loading"
    class="tile flex items-center justify-center gap-3 text-ink-300 text-sm"
  >
    <iconify-icon icon="lucide:loader-circle" width="18" class="animate-spin"></iconify-icon>
    {{ t('common.loading') }}
  </div>

  <section
    v-else
    class="tile"
    :class="flag ? 'warn-tile' : ''"
  >
    <!-- Toggle row -->
    <label class="!flex items-start gap-4 cursor-pointer !mb-6 !pb-0">
      <span class="settings-switch" :class="{ 'is-on': flag }">
        <input
          type="checkbox"
          class="settings-switch__input"
          :checked="flag"
          @change="flag = ($event.target as HTMLInputElement).checked"
        />
        <span class="settings-switch__track" aria-hidden="true">
          <span class="settings-switch__thumb"></span>
        </span>
      </span>
      <span class="flex-1 min-w-0">
        <span class="block !text-ink-100 !text-base !font-medium">
          {{ t('maintenance.toggleLabel') }}
        </span>
        <span class="block text-sm text-ink-300 mt-1 leading-relaxed">
          {{ t('maintenance.toggleHint') }}
        </span>
      </span>
    </label>

    <!-- Message textarea -->
    <div class="field">
      <label for="maintenance-message" class="!mb-1 !text-ink-100 !text-sm !font-medium">
        {{ t('maintenance.messageLabel') }}
      </label>
      <p class="text-xs text-ink-300 mb-2 leading-relaxed">
        {{ t('maintenance.messageHelp') }}
      </p>
      <textarea
        id="maintenance-message"
        v-model="message"
        :placeholder="t('maintenance.messagePlaceholder')"
        maxlength="500"
        rows="4"
      ></textarea>
      <p class="text-xs text-ink-300 mt-1 opacity-60 text-right tabular-nums">
        {{ message.length }} / 500
      </p>
    </div>

    <!-- Save row -->
    <div class="flex items-center justify-between gap-3 mt-6 pt-4 border-t border-white/10">
      <p class="text-xs text-ink-300 m-0">
        <span v-if="justSaved" class="text-emerald-300 inline-flex items-center gap-1.5">
          <iconify-icon icon="lucide:check-circle" width="14"></iconify-icon>
          {{ t('maintenance.savedFlash') }}
        </span>
        <span v-else-if="flag">{{ t('maintenance.footerOn') }}</span>
        <span v-else>{{ t('maintenance.footerOff') }}</span>
      </p>
      <div class="flex items-center gap-2">
        <button
          v-if="isDirty"
          class="btn btn-ghost"
          :disabled="saving"
          @click="discardChanges"
        >
          {{ t('common.discard') }}
        </button>
        <button
          class="btn"
          :class="flag ? 'btn-warning' : 'btn-primary'"
          :disabled="saving || !isDirty"
          @click="save"
        >
          <iconify-icon
            :icon="saving ? 'lucide:loader-circle' : 'lucide:check'"
            width="16"
            :class="saving ? 'animate-spin' : ''"
          ></iconify-icon>
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </div>
  </section>

  <!-- Help / explanation, separate tile (no border highlight) so the
       active state is clearly the form above. -->
  <section v-if="!loading" class="tile mt-5">
    <h2 class="font-display text-lg mb-2">{{ t('maintenance.aboutTitle') }}</h2>
    <ul class="text-sm text-ink-300 space-y-2 list-disc pl-5 leading-relaxed">
      <li>{{ t('maintenance.aboutPoint1') }}</li>
      <li>{{ t('maintenance.aboutPoint2') }}</li>
      <li>{{ t('maintenance.aboutPoint3') }}</li>
    </ul>
  </section>

  <section v-if="!loading && !updateCheckHidden" class="tile mt-5 update-card">
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

      <div class="update-apply">
        <button
          type="button"
          class="btn btn-primary update-apply__btn"
          :disabled="applying"
          @click="applyUpdate()"
        >
          <iconify-icon
            :icon="applying ? 'lucide:loader-2' : 'lucide:download-cloud'"
            :class="applying ? 'animate-spin' : ''"
            width="16"
          ></iconify-icon>
          {{ applying ? t('settings.update.applying') : t('settings.update.applyNow') }}
        </button>
        <p class="text-xs text-ink-300 mt-2 leading-relaxed">
          {{ t('settings.update.applyDisclaimer') }}
        </p>
      </div>
    </div>

    <div
      v-if="applyResult"
      class="update-outcome mt-4"
      :class="applyResult.ok ? 'update-outcome--ok' : 'update-outcome--err'"
    >
      <iconify-icon
        :icon="applyResult.ok ? 'lucide:check-circle-2' : 'lucide:alert-circle'"
        width="20"
      ></iconify-icon>
      <div class="flex-1">
        <p class="text-sm">{{ applyResult.message }}</p>
        <p v-if="applyResult.backupPath" class="text-xs text-ink-300 mt-1">
          {{ t('settings.update.backupAt', { path: applyResult.backupPath }) }}
        </p>
      </div>
    </div>

    <p
      v-if="!applyResult && updateState?.last_update_at && updateState.last_version"
      class="text-xs text-ink-300 mt-3"
    >
      {{ t('settings.update.lastApplied', {
        version: updateState.last_version,
        when: relativeFromIso(updateState.last_update_at),
      }) }}
    </p>
    <p
      v-if="!applyResult && updateState?.last_error"
      class="text-xs text-red-300 mt-1"
    >
      {{ t('settings.update.lastFailure', { detail: updateState.last_error }) }}
    </p>
  </section>
</template>

<style scoped>
.update-dot {
  display: inline-block;
  width: 0.625rem;
  height: 0.625rem;
  border-radius: 9999px;
}
.update-dot--ok {
  background: rgb(52 211 153);
}
.update-dot--unknown {
  background: rgb(var(--ink-300-rgb));
}

.update-toggle {
  padding-top: 0.4rem;
  padding-bottom: 0.4rem;
  font-size: 0.85rem;
}

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

.update-apply {
  margin-top: 0.5rem;
}
.update-apply__btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
}

.update-outcome {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 0.85rem 1rem;
  border-radius: 0.75rem;
  border: 1px solid rgb(var(--ink-100-rgb) / 0.08);
}
.update-outcome--ok {
  background: rgb(var(--accent-rgb) / 0.08);
  border-color: rgb(var(--accent-rgb) / 0.35);
  color: rgb(var(--ink-100-rgb));
}
.update-outcome--err {
  background: rgb(220 38 38 / 0.08);
  border-color: rgb(220 38 38 / 0.45);
  color: rgb(252 165 165);
}
</style>
