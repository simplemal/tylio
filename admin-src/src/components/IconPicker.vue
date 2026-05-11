<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const props = defineProps<{ open: boolean; modelValue?: string }>()
const emit = defineEmits<{
  'update:open': [boolean]
  'update:modelValue': [string]
}>()

const query = ref('')
const results = ref<string[]>([])
const loading = ref(false)
const error = ref('')

// Default list shown when search is empty: ~30 of the most-requested
// icons for blocks (link, social, contacts, media). A mix of:
//   - lucide: for generic UI (line/stroke icons, single style)
//   - simple-icons: for brand logos (github, spotify, … — Lucide
//     doesn't cover many brands, simple-icons is the canonical set)
// Ordered "thematically" (not alphabetically) so the list doubles as a
// quick preview of each set.
const POPULAR: string[] = [
  'lucide:link',
  'lucide:globe',
  'lucide:mail',
  'lucide:phone',
  'lucide:circle-user',
  'lucide:contact',
  'lucide:home',
  'lucide:store',
  'lucide:briefcase',
  'lucide:graduation-cap',
  'simple-icons:github',
  'simple-icons:linkedin',
  'simple-icons:instagram',
  'simple-icons:youtube',
  'simple-icons:x',
  'simple-icons:tiktok',
  'simple-icons:discord',
  'simple-icons:whatsapp',
  'simple-icons:telegram',
  'simple-icons:spotify',
  'lucide:image',
  'lucide:camera',
  'lucide:music',
  'lucide:video',
  'lucide:newspaper',
  'lucide:book',
  'lucide:download',
  'lucide:heart',
  'lucide:star',
  'lucide:rocket',
]

let debounceTimer: ReturnType<typeof setTimeout> | null = null

watch(query, (q) => {
  if (debounceTimer) clearTimeout(debounceTimer)
  if (!q.trim()) {
    results.value = POPULAR
    error.value = ''
    return
  }
  debounceTimer = setTimeout(() => search(q.trim()), 250)
})

async function search(q: string) {
  loading.value = true
  error.value = ''
  try {
    // Iconify search API: restrict to `lucide` (UI line icons) +
    // `simple-icons` (brand logos). Consistent with the SPA-wide
    // migration to these two sets.
    const url = `https://api.iconify.design/search?query=${encodeURIComponent(q)}&prefixes=lucide,simple-icons&limit=200`
    const r = await fetch(url)
    if (!r.ok) throw new Error('search_failed')
    const data: { icons?: string[] } = await r.json()
    // No style filter: lucide is single-style stroke, simple-icons is
    // single-style flat. We show every result.
    results.value = data.icons ?? []
    if (results.value.length === 0) {
      error.value = t('iconPicker.noResults', { q })
    }
  } catch (e) {
    error.value = t('iconPicker.searchUnavailable')
  } finally {
    loading.value = false
  }
}

// Extracts the readable name from the Iconify id to show it under the cell:
//   "lucide:circle-user"      → "circle-user"
//   "simple-icons:github"     → "github"
function shortName(id: string): string {
  const idx = id.indexOf(':')
  return idx >= 0 ? id.slice(idx + 1) : id
}

function pick(name: string) {
  emit('update:modelValue', name)
  close()
}

function close() {
  emit('update:open', false)
}

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape' && props.open) close()
}

onMounted(() => {
  results.value = POPULAR
  document.addEventListener('keydown', onKey)
})
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="ip-backdrop" @click.self="close">
      <div class="ip-modal" role="dialog" :aria-label="t('iconPicker.title')">
        <header class="ip-header">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-lg font-semibold">{{ t('iconPicker.title') }}</h3>
            <button type="button" class="btn btn-ghost btn-sm" :aria-label="t('iconPicker.close')" @click="close">
              <iconify-icon icon="lucide:x" width="16"></iconify-icon>
            </button>
          </div>
          <div class="ip-search">
            <iconify-icon icon="lucide:search" width="18" class="ip-search__icon"></iconify-icon>
            <input
              ref="searchInput"
              v-model="query"
              type="search"
              :placeholder="t('iconPicker.searchPlaceholder')"
              autofocus
              class="ip-search__input"
            />
            <iconify-icon
              v-if="loading"
              icon="lucide:loader-circle"
              width="16"
              class="ip-search__icon animate-spin"
            ></iconify-icon>
          </div>
        </header>

        <div class="ip-grid">
          <button
            v-for="i in results"
            :key="i"
            type="button"
            class="ip-cell"
            :class="{ 'is-selected': i === modelValue }"
            :title="i"
            @click="pick(i)"
          >
            <iconify-icon :icon="i" width="32" class="text-ink-100"></iconify-icon>
            <span class="ip-cell__name">{{ shortName(i) }}</span>
          </button>
        </div>

        <footer class="ip-footer">
          <small v-if="error" class="text-red-300">{{ error }}</small>
          <small v-else class="text-ink-300">
            {{ t('iconPicker.resultsFooter', { n: results.length }) }}
          </small>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.ip-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(4px);
  z-index: 100;
  display: grid;
  place-items: center;
  padding: 24px;
}
.ip-modal {
  background: rgb(var(--ink-900-rgb));
  border: 1px solid rgb(var(--ink-700-rgb));
  border-radius: 16px;
  width: min(720px, 100%);
  max-height: 80vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5);
}
.ip-header {
  padding: 16px;
  border-bottom: 1px solid rgb(var(--ink-700-rgb));
}
.ip-search {
  position: relative;
  display: flex;
  align-items: center;
  gap: 8px;
  background: rgb(var(--ink-800-rgb));
  border: 1px solid rgb(var(--ink-700-rgb));
  border-radius: 10px;
  padding: 10px 12px;
}
.ip-search:focus-within {
  border-color: rgb(var(--accent-rgb));
}
.ip-search__icon {
  color: rgb(var(--ink-300-rgb));
  flex-shrink: 0;
}
.ip-search__input {
  flex: 1;
  background: transparent;
  border: 0;
  outline: 0;
  color: rgb(var(--ink-100-rgb));
  font-size: 15px;
}
.ip-search__input::placeholder {
  color: rgb(var(--ink-300-rgb));
}
.ip-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));
  gap: 6px;
  padding: 12px;
  overflow-y: auto;
  flex: 1;
}
.ip-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 12px 6px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 10px;
  cursor: pointer;
  transition:
    background 0.15s,
    border-color 0.15s;
  min-width: 0;
}
.ip-cell:hover {
  background: rgb(var(--ink-800-rgb));
  border-color: rgb(var(--ink-700-rgb));
}
.ip-cell.is-selected {
  background: rgb(var(--accent-rgb) / 0.12);
  border-color: rgb(var(--accent-rgb) / 0.5);
}
.ip-cell__name {
  font-size: 10px;
  color: rgb(var(--ink-300-rgb));
  text-align: center;
  word-break: break-all;
  line-height: 1.2;
  width: 100%;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ip-footer {
  padding: 12px 16px;
  border-top: 1px solid rgb(var(--ink-700-rgb));
  background: rgb(var(--ink-900-rgb));
}
</style>