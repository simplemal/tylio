<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { Block, BlockType } from '../types'

const { t } = useI18n()
defineProps<{ block: Block; type?: BlockType | null }>()

function trunc(s: unknown, n = 140): string {
  const str = String(s ?? '')
    .replace(/\s+/g, ' ')
    .trim()
  return str.length > n ? str.slice(0, n - 1) + '…' : str
}

// Brand logos via simple-icons (Lucide doesn't have discord/spotify/tiktok/etc.).
// Fallback `lucide:globe` for unrecognized custom platforms, `lucide:mail`
// for "email".
function socialIcon(platform: unknown): string {
  const p = String(platform ?? '').toLowerCase()
  const known: Record<string, string> = {
    github: 'simple-icons:github',
    instagram: 'simple-icons:instagram',
    youtube: 'simple-icons:youtube',
    linkedin: 'simple-icons:linkedin',
    facebook: 'simple-icons:facebook',
    twitter: 'simple-icons:x',
    x: 'simple-icons:x',
    tiktok: 'simple-icons:tiktok',
    discord: 'simple-icons:discord',
    spotify: 'simple-icons:spotify',
    telegram: 'simple-icons:telegram',
    whatsapp: 'simple-icons:whatsapp',
  }
  if (p in known) return known[p]
  if (p === 'email') return 'lucide:mail'
  return 'lucide:globe'
}

function embedIcon(provider: unknown): string {
  const p = String(provider ?? '').toLowerCase()
  if (p === 'youtube') return 'simple-icons:youtube'
  if (p === 'vimeo') return 'simple-icons:vimeo'
  if (p === 'spotify') return 'simple-icons:spotify'
  return 'lucide:app-window'
}
</script>

<template>
  <div class="bp">
    <!-- HERO: the TITLE is already shown as the card's main heading
         (eyebrow "HERO" + h3 with the user's title, see Dashboard.vue), so
         here we avoid repeating it. We only show avatar + short bio as
         "content preview". -->
    <div v-if="block.type === 'hero'" class="bp-hero">
      <img v-if="block.data.avatar" :src="block.data.avatar" alt="" class="bp-hero__avatar" />
      <div class="min-w-0">
        <div v-if="block.data.subtitle" class="bp-hero__subtitle">
          {{ trunc(block.data.subtitle, 200) }}
        </div>
        <div v-else class="bp-empty">{{ t('blockPreview.empty.bio') }}</div>
      </div>
    </div>

    <!-- LINKS -->
    <ul v-else-if="block.type === 'links'" class="bp-list">
      <li v-if="!block.data.items?.length" class="bp-empty">{{ t('blockPreview.empty.links') }}</li>
      <li v-for="(it, i) in (block.data.items || []).slice(0, 4)" :key="i" class="bp-link">
        <span class="bp-link__icon">
          <iconify-icon :icon="it.icon || 'lucide:link'" width="16"></iconify-icon>
        </span>
        <span class="bp-link__label">{{ it.label || it.url || '—' }}</span>
        <span v-if="it.badge" class="bp-badge">{{ it.badge }}</span>
      </li>
      <li v-if="(block.data.items?.length || 0) > 4" class="bp-more">
        +{{ (block.data.items?.length ?? 0) - 4 }} altri
      </li>
    </ul>

    <!-- APPS -->
    <div v-else-if="block.type === 'apps'" class="bp-grid">
      <div v-if="!block.data.items?.length" class="bp-empty">{{ t('blockPreview.empty.apps') }}</div>
      <div v-for="(it, i) in (block.data.items || []).slice(0, 6)" :key="i" class="bp-app">
        <img v-if="it.icon_image" :src="it.icon_image" alt="" class="bp-app__icon" />
        <div v-else class="bp-app__icon bp-app__icon--placeholder">
          <iconify-icon icon="lucide:app-window" width="14"></iconify-icon>
        </div>
        <span class="bp-app__name">{{ it.name || '—' }}</span>
      </div>
      <div v-if="(block.data.items?.length || 0) > 6" class="bp-more bp-more--inline">
        +{{ (block.data.items?.length ?? 0) - 6 }}
      </div>
    </div>

    <!-- BIO -->
    <div v-else-if="block.type === 'bio'">
      <div v-if="block.data.title" class="bp-section-title">{{ block.data.title }}</div>
      <p class="bp-text">{{ trunc(block.data.body, 240) || '(testo vuoto)' }}</p>
    </div>

    <!-- SOCIAL -->
    <div v-else-if="block.type === 'social'" class="bp-pills">
      <span v-if="!block.data.items?.length" class="bp-empty">{{ t('blockPreview.empty.social') }}</span>
      <span v-for="(it, i) in block.data.items || []" :key="i" class="bp-pill">
        <iconify-icon :icon="socialIcon(it.platform)" width="14"></iconify-icon>
        {{ it.label || it.platform }}
      </span>
    </div>

    <!-- GALLERY -->
    <div v-else-if="block.type === 'gallery'" class="bp-thumbs">
      <span v-if="!block.data.items?.length" class="bp-empty">{{ t('blockPreview.empty.gallery') }}</span>
      <div v-for="(it, i) in (block.data.items || []).slice(0, 5)" :key="i" class="bp-thumb">
        <img v-if="it.image" :src="it.image" alt="" />
        <iconify-icon v-else icon="lucide:image" width="20"></iconify-icon>
      </div>
      <div v-if="(block.data.items?.length || 0) > 5" class="bp-thumb bp-thumb--more">
        +{{ (block.data.items?.length ?? 0) - 5 }}
      </div>
    </div>

    <!-- EMBED -->
    <div v-else-if="block.type === 'embed'" class="bp-embed">
      <iconify-icon :icon="embedIcon(block.data.provider)" width="22" class="text-ink-100"></iconify-icon>
      <div class="min-w-0 flex-1">
        <div v-if="block.data.title" class="bp-text">{{ block.data.title }}</div>
        <div class="text-xs text-ink-300 truncate">{{ block.data.url || '(nessun URL)' }}</div>
      </div>
    </div>

    <!-- YOUTUBE -->
    <div v-else-if="block.type === 'youtube'" class="bp-embed">
      <iconify-icon icon="simple-icons:youtube" width="22" style="color:#ff0000"></iconify-icon>
      <div class="min-w-0 flex-1">
        <div v-if="block.data.title" class="bp-text">{{ block.data.title }}</div>
        <div class="text-xs text-ink-300 truncate">
          {{ block.data.source_url || '(URL canale/playlist mancante)' }}
          <span v-if="block.data.mode === 'playlist'" class="opacity-60">· playlist</span>
          <span v-else class="opacity-60">· ultimo video</span>
        </div>
      </div>
    </div>

    <!-- PODCAST -->
    <div v-else-if="block.type === 'podcast'" class="bp-embed">
      <iconify-icon icon="lucide:podcast" width="22" class="text-ink-100"></iconify-icon>
      <div class="min-w-0 flex-1">
        <div v-if="block.data.show_name" class="bp-text">{{ block.data.show_name }}</div>
        <div class="text-xs text-ink-300 truncate flex flex-wrap gap-1">
          <span v-if="block.data.apple_url" class="bp-pill" title="Apple Podcasts">
            <iconify-icon icon="simple-icons:applepodcasts" width="11"></iconify-icon> Apple
          </span>
          <span v-if="block.data.spotify_url" class="bp-pill" title="Spotify">
            <iconify-icon icon="simple-icons:spotify" width="11"></iconify-icon> Spotify
          </span>
          <span v-if="block.data.site_url" class="bp-pill" :title="t('blockPreview.podcast.site')">
            <iconify-icon icon="lucide:globe" width="11"></iconify-icon> {{ t('blockPreview.podcast.site') }}
          </span>
          <span
            v-if="!block.data.apple_url && !block.data.spotify_url && !block.data.site_url"
            class="bp-empty"
          >
            {{ t('blockPreview.podcast.addAtLeastOne') }}
          </span>
        </div>
      </div>
    </div>

    <!-- CONTACT -->
    <div v-else-if="block.type === 'contact'" class="bp-contact">
      <div v-if="block.data.title" class="bp-section-title">{{ block.data.title }}</div>
      <p v-if="block.data.subtitle" class="bp-text">{{ block.data.subtitle }}</p>
      <div class="bp-pills">
        <span v-for="(f, i) in block.data.fields || []" :key="i" class="bp-pill">
          <iconify-icon
            :icon="
              f.type === 'email'
                ? 'lucide:mail'
                : f.type === 'tel'
                  ? 'lucide:phone'
                  : f.type === 'textarea'
                    ? 'lucide:align-left'
                    : 'lucide:type'
            "
            width="13"
          ></iconify-icon>
          {{ f.label || f.key }}<span v-if="f.required" class="text-ink-100">*</span>
        </span>
      </div>
    </div>

    <!-- PRODUCTS -->
    <div v-else-if="block.type === 'products'" class="bp-grid">
      <div v-if="!block.data.items?.length" class="bp-empty">{{ t('blockPreview.empty.products') }}</div>
      <div v-for="(it, i) in (block.data.items || []).slice(0, 6)" :key="i" class="bp-app">
        <img v-if="it.image" :src="it.image" alt="" class="bp-app__icon" />
        <div v-else class="bp-app__icon bp-app__icon--placeholder">
          <iconify-icon icon="lucide:shopping-bag" width="14"></iconify-icon>
        </div>
        <span class="bp-app__name">{{ it.name || '—' }}</span>
        <span v-if="it.discount_code" class="bp-badge bp-badge--accent">{{
          it.discount_label || it.discount_code
        }}</span>
      </div>
      <div v-if="(block.data.items?.length || 0) > 6" class="bp-more bp-more--inline">
        +{{ (block.data.items?.length ?? 0) - 6 }}
      </div>
    </div>

    <!-- SKILLS -->
    <div v-else-if="block.type === 'skills'" class="bp-pills">
      <span v-if="!block.data.items?.length" class="bp-empty">{{ t('blockPreview.empty.skills') }}</span>
      <span
        v-for="(it, i) in (block.data.items || []).slice(0, 12)"
        :key="i"
        class="bp-pill"
      >
        <iconify-icon
          v-if="it.icon"
          :icon="it.icon"
          width="11"
        ></iconify-icon>
        {{ it.name || '—' }}
        <span v-if="it.level" class="opacity-70">· {{ it.level }}</span>
      </span>
      <span
        v-if="(block.data.items?.length || 0) > 12"
        class="bp-more bp-more--inline"
        >+{{ (block.data.items?.length ?? 0) - 12 }}</span
      >
    </div>

    <!-- DIVIDER -->
    <div v-else-if="block.type === 'divider'" class="bp-divider">
      <span class="bp-divider__scale"></span>
      <span class="bp-divider__scale"></span>
      <span class="bp-divider__scale"></span>
      <span class="bp-divider__label">{{ t('blockPreview.divider.style', { style: block.data.style || 'tessera' }) }}</span>
    </div>

    <!-- FOOTER -->
    <div v-else-if="block.type === 'footer'" class="bp-footer">
      <span class="bp-text">{{ block.data.text || '(nessun testo)' }}</span>
      <span v-if="block.data.links?.length" class="text-xs text-ink-300"
        >· {{ block.data.links.length }} link</span
      >
      <span v-if="block.data.show_powered_by" class="text-xs text-ink-300"
        >· "powered by tylio"</span
      >
    </div>

    <!-- FALLBACK (unreachable given the discriminated union — only for safety) -->
    <p v-else class="bp-text text-ink-300">{{ type?.description }}</p>
  </div>
</template>

<style scoped>
/* Block preview in the admin Dashboard: uses ONLY ink-100 (primary text)
   and ink-300 (secondary text), no accent. Rule: the "card title" and
   the "card icon" use ink-100; everything else (items, badges,
   descriptions, secondary icons) uses ink-300. Guarantee: text and
   surface are opposites in every palette → contrast always OK. */
.bp {
  color: rgb(var(--ink-300-rgb));
  font-size: 13px;
  line-height: 1.4;
}
.bp-empty {
  color: rgb(var(--ink-300-rgb));
  font-style: italic;
  font-size: 12px;
}
.bp-section-title {
  font-family: theme('fontFamily.display');
  font-weight: 600;
  margin-bottom: 4px;
  color: rgb(var(--ink-100-rgb)); /* card title → primary */
}
.bp-text {
  color: rgb(var(--ink-300-rgb));
  margin: 0;
}

/* HERO */
.bp-hero {
  display: flex;
  gap: 10px;
  align-items: flex-start;
}
.bp-hero__avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid rgb(var(--ink-700-rgb));
}
.bp-hero__title {
  font-family: theme('fontFamily.display');
  font-size: 16px;
  font-weight: 600;
  line-height: 1.1;
  color: rgb(var(--ink-100-rgb)); /* card title → primary */
}
.bp-hero__subtitle {
  color: rgb(var(--ink-300-rgb));
  font-size: 12px;
  margin-top: 2px;
  line-height: 1.4;
  white-space: pre-wrap;
}

/* LINKS */
.bp-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.bp-link {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 0;
}
/* Item-level icons: ink-300 (secondary text). Only the "card title" and
   the "card icon" use ink-100 — see comment at the top of the style section. */
.bp-link__icon {
  width: 22px;
  height: 22px;
  display: grid;
  place-items: center;
  border-radius: 6px;
  background: rgb(var(--ink-300-rgb) / 0.12);
  color: rgb(var(--ink-300-rgb));
  flex-shrink: 0;
}
.bp-link__label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.bp-badge {
  font-size: 10px;
  padding: 2px 6px;
  border-radius: 999px;
  background: rgb(var(--ink-700-rgb));
  color: rgb(var(--ink-300-rgb));
}
.bp-badge--accent {
  /* Even the "emphasized badges" (e.g. discount code) stay in ink-300:
     the admin shouldn't scream color, it's a preview. */
  background: rgb(var(--ink-300-rgb) / 0.18);
  color: rgb(var(--ink-300-rgb));
  font-weight: 600;
}
.bp-more {
  color: rgb(var(--ink-300-rgb));
  font-size: 11px;
  padding: 2px 0 0 30px;
}
.bp-more--inline {
  padding: 0;
  align-self: center;
}

/* GRID (apps + products) */
.bp-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.bp-app {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 8px 4px 4px;
  background: rgb(var(--ink-700-rgb) / 0.5);
  border-radius: 6px;
  font-size: 12px;
  max-width: 100%;
}
.bp-app__icon {
  width: 22px;
  height: 22px;
  border-radius: 4px;
  object-fit: cover;
  flex-shrink: 0;
  background: rgb(var(--ink-900-rgb));
}
.bp-app__icon--placeholder {
  display: grid;
  place-items: center;
  color: rgb(var(--ink-300-rgb));
}
.bp-app__name {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 130px;
}

/* PILLS (social, contact fields) */
.bp-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin-top: 4px;
}
.bp-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  background: rgb(var(--ink-700-rgb) / 0.6);
  border-radius: 999px;
  font-size: 11px;
  color: rgb(var(--ink-100-rgb));
}
.bp-pill iconify-icon {
  /* Item-level icons → ink-300 (secondary). */
  color: rgb(var(--ink-300-rgb));
}

/* GALLERY */
.bp-thumbs {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
}
.bp-thumb {
  width: 38px;
  height: 38px;
  border-radius: 6px;
  overflow: hidden;
  background: rgb(var(--ink-700-rgb));
  display: grid;
  place-items: center;
  color: rgb(var(--ink-300-rgb));
}
.bp-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.bp-thumb--more {
  font-size: 11px;
  font-weight: 500;
  color: rgb(var(--ink-100-rgb));
}

/* EMBED */
.bp-embed {
  display: flex;
  gap: 10px;
  align-items: center;
}

/* CONTACT */
.bp-contact {
}

/* DIVIDER: three decorative "scales" rotated 45° + "style: ..." label NOT
   rotated (regression 2026-05-10: previously the generic
   `.bp-divider span` selector rotated every span, label included, and the
   text appeared diagonal). */
.bp-divider {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 6px 0;
}
.bp-divider__scale {
  width: 8px;
  height: 8px;
  /* Preview decoration → ink-300 (secondary). */
  background: rgb(var(--ink-300-rgb) / 0.4);
  transform: rotate(45deg);
}
.bp-divider__scale:nth-of-type(2) {
  width: 12px;
  height: 12px;
  background: rgb(var(--ink-300-rgb) / 0.6);
}
.bp-divider__label {
  font-size: 12px;
  color: rgb(var(--ink-300-rgb));
  margin-left: 12px;
}

/* FOOTER */
.bp-footer {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
}
</style>