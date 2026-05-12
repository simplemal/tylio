<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api } from '../api'
import { useInbox } from '../stores/inbox'
import { useConfirm } from '../composables/useConfirm'
import type { Settings, Submission } from '../types'

const { t, locale } = useI18n()
const { confirm } = useConfirm()

const items = ref<Submission[]>([])
const loading = ref(true)
const settings = ref<Settings>({})
const router = useRouter()
const inbox = useInbox()
const copiedId = ref<number | null>(null)
const busy = ref(false)

onMounted(async () => {
  const [s, sub] = await Promise.all([api.getSettings(), api.listSubmissions()])
  settings.value = s.settings
  items.value = sub.submissions
  loading.value = false

  // Mark-all-read runs in the background, AFTER rendering with the
  // current read_at: this way the user still sees the highlighted
  // border on new messages during this visit; on the next visit they
  // are read and the badge is cleared.
  if (items.value.some((m) => !m.read_at)) {
    api.markAllSubmissionsRead().then(() => inbox.clear()).catch(() => {})
  }
})

const notifyEmail = computed(() => (settings.value['contact.notify_email'] as string) || '')

function findEmail(payload: Record<string, string>): string | null {
  for (const v of Object.values(payload)) {
    if (typeof v === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim())) {
      return v.trim()
    }
  }
  return null
}

function findName(payload: Record<string, string>): string {
  return (
    payload['nome'] ||
    payload['name'] ||
    payload['nominativo'] ||
    Object.values(payload)[0] ||
    ''
  )
}

async function copyEmail(email: string, id: number) {
  try {
    await navigator.clipboard.writeText(email)
    copiedId.value = id
    setTimeout(() => {
      if (copiedId.value === id) copiedId.value = null
    }, 1500)
  } catch {
    // Fallback execCommand: for very old browsers without clipboard API.
    const ta = document.createElement('textarea')
    ta.value = email
    ta.style.position = 'fixed'
    ta.style.opacity = '0'
    document.body.appendChild(ta)
    ta.select()
    try {
      document.execCommand('copy')
      copiedId.value = id
      setTimeout(() => {
        if (copiedId.value === id) copiedId.value = null
      }, 1500)
    } finally {
      document.body.removeChild(ta)
    }
  }
}

/**
 * Builds a mailto: with body in the classic email-reply style (header
 * "Il [date], [name] ha scritto:" + 4-space indentation). Modern mail
 * clients (Apple Mail, Outlook, Spark, Thunderbird) recognize this
 * pattern and treat it as a natural quotation, without markdown `>`.
 * Above the header two empty lines remain for the reply.
 * We do NOT include the IP in the body, as requested by the user.
 */
function replyMailto(m: Submission): string {
  const email = findEmail(m.payload)
  if (!email) return ''
  const name = findName(m.payload)
  const subject = name
    ? t('submissions.replySubject', { name })
    : t('submissions.replyDefaultSubject')

  // Locale-aware readable date format.
  const dateFormatted = (() => {
    try {
      const d = new Date(m.created_at.replace(' ', 'T') + 'Z')
      if (isNaN(d.getTime())) return m.created_at
      return d.toLocaleString(locale.value, {
        day: 'numeric', month: 'long', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
      })
    } catch { return m.created_at }
  })()

  const attribution = t('submissions.replyAttribution', {
    date: dateFormatted,
    name: name || t('submissions.replyFallbackName'),
  })

  // 4-space indentation for every quoted line (Outlook/Thunderbird HTML style).
  const indented = Object.entries(m.payload).map(([k, v]) => {
    const label = k.charAt(0).toUpperCase() + k.slice(1)
    // The inner lines of a multiline value also need to be indented.
    const linesOfValue = String(v).split('\n')
    if (linesOfValue.length > 1) {
      return `    ${label}:\n` + linesOfValue.map((l) => `        ${l}`).join('\n')
    }
    return `    ${label}: ${v}`
  })

  const body = ['', '', '', attribution, '', ...indented, ''].join('\n')
  return `mailto:${encodeURIComponent(email)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`
}

async function deleteOne(m: Submission) {
  const ok = await confirm({
    message: t('submissions.deleteOneConfirm'),
    confirmLabel: t('common.delete'),
    destructive: true,
  })
  if (!ok) return
  busy.value = true
  try {
    await api.deleteSubmission(m.id)
    items.value = items.value.filter((x) => x.id !== m.id)
  } finally {
    busy.value = false
  }
}

async function deleteAll() {
  const ok = await confirm({
    message: t('submissions.deleteAllConfirmCount', { count: items.value.length }),
    confirmLabel: t('submissions.emptyAll'),
    destructive: true,
  })
  if (!ok) return
  busy.value = true
  try {
    await api.deleteAllSubmissions()
    items.value = []
    inbox.clear()
  } finally {
    busy.value = false
  }
}

function mailStatusInfo(m: Submission): { icon: string; label: string; class: string } | null {
  switch (m.mail_status) {
    case 'sent':
      return {
        icon: 'lucide:mail-check',
        label: t('submissions.mailStatus.sentLabel'),
        class: 'text-emerald-400',
      }
    case 'no_dsn':
      return {
        icon: 'lucide:mail-warning',
        label: t('submissions.mailStatus.noServer'),
        class: 'warn-icon',
      }
    case 'no_recipient':
      return {
        icon: 'lucide:mail-warning',
        label: t('submissions.mailStatus.noEmailSet'),
        class: 'warn-icon',
      }
    case 'error':
      return {
        icon: 'lucide:mail-x',
        label:
          t('submissions.mailStatus.deliveryErrorPrefix') +
          (m.mail_error || t('submissions.mailStatus.deliveryErrorUnknown')),
        class: 'text-rose-400',
      }
    default:
      return null
  }
}
</script>

<template>
  <div class="mb-6 flex items-end justify-between gap-3 flex-wrap">
    <div>
      <p class="eyebrow">{{ t('submissions.eyebrow') }}</p>
      <h1 class="heading">{{ t('submissions.title') }}</h1>
    </div>
    <button
      v-if="items.length > 0"
      class="btn btn-ghost"
      :disabled="busy"
      :title="t('submissions.deleteAllTitle')"
      @click="deleteAll"
    >
      <iconify-icon icon="lucide:trash-2" width="16"></iconify-icon>
      {{ t('submissions.emptyAll') }}
    </button>
  </div>

  <!-- Explanation + email forwarding status -->
  <div class="tile mb-4 flex items-start gap-3">
    <iconify-icon
      icon="lucide:info"
      width="22"
      class="text-ink-100 flex-shrink-0 mt-0.5"
    ></iconify-icon>
    <div class="text-sm text-ink-100 flex-1">
      <p>
        <i18n-t keypath="submissions.infoLead" tag="span">
          <template #contact><strong>{{ t('submissions.contact') }}</strong></template>
          <template #savedHere><strong>{{ t('submissions.savedHere') }}</strong></template>
        </i18n-t>
      </p>
      <p v-if="notifyEmail" class="mt-2 text-ink-300">
        <i18n-t keypath="submissions.infoForwardedTo" tag="span">
          <template #forwardedByEmail><strong>{{ t('submissions.forwardedByEmail') }}</strong></template>
        </i18n-t>
        <code class="text-ink-300">{{ notifyEmail }}</code
        >.
        <button class="text-ink-300 underline ml-1 hover:text-ink-100" @click="router.push('/settings')">{{ t('submissions.change') }}</button>
      </p>
      <p v-else class="mt-2 text-ink-300">
        <i18n-t keypath="submissions.infoMissingPrefix" tag="span">
          <template #byEmail><strong>{{ t('submissions.byEmail') }}</strong></template>
        </i18n-t>
        <button class="text-ink-300 underline hover:text-ink-100 ml-1" @click="router.push('/settings')">{{ t('submissions.settingsLink') }}</button
        >.
      </p>
    </div>
  </div>

  <div v-if="loading" class="text-ink-300">{{ t('submissions.loading') }}</div>
  <div v-else-if="items.length === 0" class="tile text-center py-12">
    <iconify-icon
      icon="lucide:mail-open"
      width="48"
      class="text-ink-100 mx-auto"
    ></iconify-icon>
    <p class="mt-3 text-ink-300">{{ t('submissions.empty') }}</p>
  </div>
  <ul v-else class="space-y-3">
    <li
      v-for="m in items"
      :key="m.id"
      class="tile relative"
      :class="!m.read_at ? 'ring-2 ring-ink-100/60 shadow-lg' : ''"
    >
      <!-- Top-right card actions: Reply (only if there's an email) + Delete.
           The padding-right of the header below is generous (pr-32) to
           avoid overlapping with both buttons. -->
      <div class="absolute top-3 right-3 flex items-center gap-1.5">
        <a
          v-if="findEmail(m.payload)"
          :href="replyMailto(m)"
          class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-ink-100 hover:bg-ink-100/90 text-ink-900 text-xs font-medium transition"
          :title="t('submissions.replyTo', { email: findEmail(m.payload) ?? '' })"
        >
          <iconify-icon icon="lucide:reply" width="14"></iconify-icon>
          {{ t('submissions.reply') }}
        </a>
        <button
          class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-ink-300 hover:bg-rose-500/20 hover:text-rose-500 transition"
          :title="t('submissions.deleteOneTitle')"
          :disabled="busy"
          @click="deleteOne(m)"
        >
          <iconify-icon icon="lucide:trash-2" width="14"></iconify-icon>
        </button>
      </div>

      <div class="flex items-center gap-2 mb-2 flex-wrap pr-32">
        <iconify-icon
          icon="lucide:mail"
          width="18"
          :class="!m.read_at ? 'text-ink-100' : 'text-ink-300'"
        ></iconify-icon>
        <span
          v-if="!m.read_at"
          class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-ink-100/15 text-ink-100 font-semibold"
          >{{ t('submissions.newBadge') }}</span
        >
        <span class="text-sm text-ink-300"
          >{{ m.created_at }} · {{ t('submissions.fromIp') }} {{ m.ip || t('submissions.unknownIp') }} · {{ t('submissions.blockShort') }} #{{ m.block_id }}</span
        >
        <!-- Email forwarding status -->
        <span
          v-if="mailStatusInfo(m)"
          class="inline-flex items-center gap-1 text-xs"
          :class="mailStatusInfo(m)!.class"
          :title="mailStatusInfo(m)!.label"
        >
          <iconify-icon :icon="mailStatusInfo(m)!.icon" width="14"></iconify-icon>
        </span>
      </div>

      <dl class="grid grid-cols-[120px_1fr] gap-x-3 gap-y-1 text-sm">
        <template v-for="(v, k) in m.payload" :key="k">
          <dt class="text-ink-300 capitalize">{{ k }}</dt>
          <dd class="text-ink-100 whitespace-pre-wrap break-words">
            <!-- Value + (if it's an email) Copy icon inline immediately
                 to the right, NOT aligned to the end of the card. We use
                 align-middle because iconify-icon is inline-block. -->
            <span>{{ v }}</span>
            <button
              v-if="typeof v === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim())"
              class="ml-1.5 text-ink-300 hover:text-ink-100 transition align-middle inline-flex"
              :title="copiedId === m.id ? t('common.copied') : t('submissions.copyEmail')"
              @click="copyEmail(v.trim(), m.id)"
            >
              <iconify-icon
                :icon="copiedId === m.id ? 'lucide:check' : 'lucide:copy'"
                width="14"
              ></iconify-icon>
            </button>
          </dd>
        </template>
      </dl>

      <!-- Extended warning if forwarding failed or isn't configured —
           useful on old messages when the user wonders why they didn't
           arrive. -->
      <p
        v-if="mailStatusInfo(m) && m.mail_status !== 'sent'"
        class="mt-2 text-xs"
        :class="mailStatusInfo(m)!.class"
      >
        <iconify-icon :icon="mailStatusInfo(m)!.icon" width="12"></iconify-icon>
        {{ mailStatusInfo(m)!.label }}
      </p>
    </li>
  </ul>
</template>
