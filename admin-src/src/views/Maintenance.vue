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
import type { Settings } from '../types'
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
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
      <p class="eyebrow">{{ t('maintenance.eyebrow') }}</p>
      <h1 class="heading">{{ t('maintenance.title') }}</h1>
    </div>
    <span
      v-if="flag"
      class="text-sm font-medium text-amber-200 inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-amber-400/[0.12] border border-amber-400/40"
    >
      <span class="relative inline-flex">
        <span class="absolute inset-0 rounded-full bg-amber-400 animate-ping opacity-60"></span>
        <span class="relative w-2 h-2 rounded-full bg-amber-400"></span>
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
    :class="flag
      ? 'border-amber-400/40 bg-amber-400/[0.06] shadow-[0_0_0_1px_rgba(251,191,36,0.18)_inset]'
      : ''"
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
</template>
