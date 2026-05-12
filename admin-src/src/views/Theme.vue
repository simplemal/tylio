<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../api'
import type { Theme } from '../types'
import { applyPalette } from '../theme'
import OgImageUploader from '../components/OgImageUploader.vue'
import InfoTip from '../components/InfoTip.vue'
import { PRESETS, type ThemePreset } from '../presets'

const { t } = useI18n()

const patterns = computed(() => [
  { id: 'none', label: t('theme.background.patternNone') },
  { id: 'dots', label: t('theme.background.patternDots') },
  { id: 'grid', label: t('theme.background.patternGrid') },
  { id: 'lines-thin', label: t('theme.background.patternLinesThin') },
  { id: 'lines-thick', label: t('theme.background.patternLinesThick') },
  { id: 'mosaic', label: t('theme.background.patternMosaic') },
  { id: 'cubes', label: t('theme.background.patternCubes') },
  { id: 'image', label: t('theme.background.patternImage') },
])

function thumbOpacity(patternId: string): number {
  // "None": stays empty regardless of intensity.
  if (patternId === 'none') return 0
  // "Photo": the preview is a placeholder (grey gradient); it still
  // reflects intensity like every other pattern. We guarantee a minimum
  // of 0.18 to avoid invisible thumbnails at very low intensities, but
  // we scale proportionally.
  const i = theme.value?.background?.intensity ?? 0.12
  return Math.max(0.18, Math.min(1, i * 1.8))
}

function patternPreviewStyle(id: string): Record<string, string> {
  // currentColor → adapts to the admin theme (light or dark)
  const c = 'currentColor'
  // mosaic = fish scales (staggered arcs), tile 28×14 radius 7
  const mosaicSvg =
    "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='28' height='14' viewBox='0 0 28 14'><g fill='none' stroke='black' stroke-width='0.9'><path d='M0 14 A7 7 0 0 1 14 14 M14 14 A7 7 0 0 1 28 14 M-7 7 A7 7 0 0 1 7 7 M7 7 A7 7 0 0 1 21 7 M21 7 A7 7 0 0 1 35 7'/></g></svg>"
  const cubesSvg =
    "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='30' height='26' viewBox='0 0 30 26'><g fill='none' stroke='black' stroke-width='0.6' stroke-linejoin='round'><polygon points='15,0 30,7.5 30,18.5 15,26 0,18.5 0,7.5'/><path d='M15 13 L30 7.5 M15 13 L30 18.5 M15 13 L15 26'/></g></svg>"
  switch (id) {
    case 'dots':
      return {
        backgroundImage: `radial-gradient(circle, ${c} 1px, transparent 1.6px)`,
        backgroundSize: '8px 8px',
      }
    case 'grid':
      return {
        backgroundImage: `linear-gradient(to right, ${c} 1px, transparent 1px),linear-gradient(to bottom, ${c} 1px, transparent 1px)`,
        backgroundSize: '10px 10px',
      }
    case 'lines-thin':
      return {
        backgroundImage: `repeating-linear-gradient(45deg, transparent 0 5px, ${c} 5px 6px)`,
      }
    case 'lines-thick':
      return {
        backgroundImage: `repeating-linear-gradient(45deg, transparent 0 6px, ${c} 6px 9px)`,
      }
    case 'mosaic':
      // bg-color = currentColor masked by the SVG → arcs become the theme color
      return {
        backgroundColor: 'currentColor',
        WebkitMaskImage: `url("${mosaicSvg}")`,
        maskImage: `url("${mosaicSvg}")`,
        WebkitMaskSize: '28px 14px',
        maskSize: '28px 14px',
        WebkitMaskRepeat: 'repeat',
        maskRepeat: 'repeat',
      }
    case 'cubes':
      // Same pattern as the mosaic + central lines for 3D cube effect
      return {
        backgroundColor: 'currentColor',
        WebkitMaskImage: `url("${cubesSvg}")`,
        maskImage: `url("${cubesSvg}")`,
        WebkitMaskSize: '30px 26px',
        maskSize: '30px 26px',
        WebkitMaskRepeat: 'repeat',
        maskRepeat: 'repeat',
      }
    case 'image': {
      // Show the actually-uploaded image if any. Otherwise a neutral
      // backdrop: the template overlays a lucide:image icon on top.
      const url = theme.value?.background?.image
      if (url) {
        return {
          backgroundImage: `url("${url.replace(/"/g, '%22')}")`,
          backgroundSize: 'cover',
          backgroundPosition: 'center',
          backgroundRepeat: 'no-repeat',
        }
      }
      return { background: 'rgba(255,255,255,0.04)' }
    }
    default:
      return { background: 'transparent' }
  }
}

const theme = ref<Theme | null>(null)
const savedSnapshot = ref<string>('') // JSON of what's in the DB; used for dirty detection
const saving = ref(false)
const cacheBust = ref(Date.now())

const isDirty = computed(() => {
  if (!theme.value) return false
  return JSON.stringify(theme.value) !== savedSnapshot.value
})
// Group presets by family, preserving declaration order.
const groupedPresets = computed(() => {
  const groups: { family: string; items: ThemePreset[] }[] = []
  for (const p of PRESETS) {
    let g = groups.find((x) => x.family === p.family)
    if (!g) {
      g = { family: p.family, items: [] }
      groups.push(g)
    }
    g.items.push(p)
  }
  return groups
})

// Map family-id → translated family label. Falls back to the raw id if
// the key isn't translated.
function familyLabel(family: string): string {
  const key = `theme.families.${family}`
  const v = t(key)
  return v === key ? family : v
}

// Fonts grouped by category. The <select> uses <optgroup> for the split.
// Add new fonts here + load them in the admin's `index.html` for the
// preview (the public site loads them automatically via layout.php →
// dynamic Google Fonts URL).
const fontGroups = computed<{ label: string; fonts: string[] }[]>(() => [
  {
    label: t('theme.fonts.groups.serif'),
    fonts: ['Fraunces', 'IBM Plex Serif', 'Crimson Pro', 'Lora'],
  },
  {
    label: t('theme.fonts.groups.sans'),
    fonts: ['Inter', 'DM Sans', 'IBM Plex Sans', 'Manrope'],
  },
  {
    label: t('theme.fonts.groups.modern'),
    fonts: ['Playfair Display', 'Space Grotesk', 'Sora'],
  },
  {
    label: t('theme.fonts.groups.script'),
    fonts: ['Caveat', 'Dancing Script', 'Pacifico'],
  },
  {
    label: t('theme.fonts.groups.monospace'),
    fonts: ['JetBrains Mono', 'IBM Plex Mono', 'Space Mono'],
  },
])

function isActivePreset(p: ThemePreset): boolean {
  return theme.value?.palette?.name === p.palette.name
}

/**
 * Detect which preset matches the current palette by VALUE (every
 * color field), independently of `palette.name`. Used at page load so
 * the picker can highlight the active preset even when the user is on
 * an older install whose seed palette is named `terra` (no preset
 * matches by name) or after a one-off custom edit that didn't rename
 * the palette. Returns the matching preset's `palette.name`, or `''`
 * if none matches (truly custom palette).
 *
 * Comparison is case-insensitive on the string values; field set is
 * the canonical PALETTE_KEYS list above, which excludes `name` (the
 * very field we're trying to derive).
 */
function detectPresetByValue(palette: Record<string, unknown> | undefined): string {
  if (!palette) return ''
  const norm = (v: unknown): string =>
    typeof v === 'string' ? v.trim().toLowerCase().replace(/\s+/g, '') : ''
  for (const p of PRESETS) {
    let allMatch = true
    for (const k of PALETTE_KEYS) {
      const a = norm((palette as Record<string, unknown>)[k])
      const b = norm((p.palette as Record<string, unknown>)[k])
      // Empty fields on the loaded palette (older installs that don't
      // store accent_soft / accent_alt_fg yet) shouldn't disqualify a
      // match — only mismatching non-empty values do.
      if (a !== '' && a !== b) { allMatch = false; break }
    }
    if (allMatch) return p.palette.name
  }
  return ''
}

// Sanitizer for font names used in `:style` and postMessage. The regex
// matches ONLY whitelisted names (letters/digits/spaces). For invalid
// names it returns 'Inter' as a safe default. Defense in depth: even
// though `style.fontFamily` is a DOM-validated setter, we avoid passing
// potentially crafted strings from the DB.
const SAFE_FONT_RE = /^[A-Za-z0-9 ]{1,60}$/
function safeFont(name: unknown, fallback = 'Inter'): string {
  return typeof name === 'string' && SAFE_FONT_RE.test(name) ? name : fallback
}

const tileStyles = computed<{ id: 'solid' | 'transparent' | 'glass'; label: string }[]>(() => [
  { id: 'solid', label: t('theme.tile.styleSolid') },
  { id: 'transparent', label: t('theme.tile.styleTransparent') },
  { id: 'glass', label: t('theme.tile.styleGlass') },
])

// Human-readable labels + descriptions for each palette color.
// Canonical order of fields in the "Customize individual colors" editor.
// Iterating over this list (rather than Object.keys(theme.palette)) means
// new fields show up even for installs whose palette in the DB predates
// the new fields (e.g. accent_soft / accent_alt_fg).
const PALETTE_KEYS = [
  'bg',
  'surface',
  'surface_alt',
  'text',
  'text_muted',
  'accent',
  'accent_soft',
  'accent_alt',
  'accent_alt_fg',
  'border',
] as const

// Maps palette-key (snake_case in the data) → i18n key under
// `theme.palette.*` (camelCase). Keeps both naming conventions tidy.
const PALETTE_LABEL_KEY: Record<string, string> = {
  bg: 'theme.palette.bg',
  surface: 'theme.palette.surface',
  surface_alt: 'theme.palette.surfaceAlt',
  text: 'theme.palette.text',
  text_muted: 'theme.palette.textMuted',
  accent: 'theme.palette.accent',
  accent_alt: 'theme.palette.accentAlt',
  accent_soft: 'theme.palette.accentSoft',
  accent_alt_fg: 'theme.palette.accentAltFg',
  border: 'theme.palette.border',
}
function colorLabel(key: string): string {
  const k = PALETTE_LABEL_KEY[key]
  return k ? t(k) : key
}
function colorHelp(key: string): string {
  const fullKey = `theme.palette.help.${key}`
  const v = t(fullKey)
  return v === fullKey ? '' : v
}

onMounted(async () => {
  theme.value = (await api.getTheme()).theme
  // Auto-activate the matching preset on load: if the saved palette
  // doesn't already carry a known preset name (e.g. install seeded as
  // 'terra', or a user manually edited without renaming), but its
  // values still match one of the presets, set the name so the picker
  // highlights it. This is a local hint — `savedSnapshot` is captured
  // AFTER the assignment so the dirty-flag stays clean: the rename
  // doesn't count as an unsaved change until the user actually changes
  // something. A real save will then persist the name to the DB.
  if (theme.value?.palette) {
    const currentName = (theme.value.palette as { name?: string }).name ?? ''
    if (!PRESETS.some((p) => p.palette.name === currentName)) {
      const detected = detectPresetByValue(theme.value.palette as Record<string, unknown>)
      if (detected) {
        (theme.value.palette as { name?: string }).name = detected
      }
    }
  }
  savedSnapshot.value = JSON.stringify(theme.value)
})

async function save() {
  if (!theme.value) return
  saving.value = true
  try {
    theme.value = (await api.updateTheme(theme.value)).theme
    savedSnapshot.value = JSON.stringify(theme.value)
    // Only now apply the palette to the admin (so "applied" = "in DB").
    applyPalette(theme.value.palette)
    cacheBust.value = Date.now()
  } finally {
    saving.value = false
  }
}

function applyPreset(p: ThemePreset) {
  if (!theme.value) return
  // Modifies only the local draft; to apply it requires "Save theme"
  theme.value.palette = { ...p.palette }
  theme.value.mode = p.mode
}

function discardChanges() {
  if (!savedSnapshot.value) return
  theme.value = JSON.parse(savedSnapshot.value)
}

function setBgImage(url: string) {
  if (theme.value) theme.value.background.image = url
}

// Updates a palette key in rgba() format in place, preserving the other
// component (alpha when changing hex, hex when changing alpha).
function setRgbaHex(key: string, hex: string) {
  if (!theme.value) return
  const cur = parseRgba((theme.value.palette as Record<string, string>)[key] || '')
  ;(theme.value.palette as Record<string, string>)[key] = composeRgba(hex, cur.alpha)
}
function setRgbaAlpha(key: string, alphaStr: string | number) {
  if (!theme.value) return
  const cur = parseRgba((theme.value.palette as Record<string, string>)[key] || '')
  const a = Math.max(0, Math.min(1, Number(alphaStr)))
  ;(theme.value.palette as Record<string, string>)[key] = composeRgba(cur.hex, a)
}

// "Effective" value of the field: if the user has typed something, use
// it; otherwise compute the auto-derived value (so the color picker and
// the text input placeholder always show something sensible even for
// never-saved fields like accent_soft / accent_alt_fg). If the user
// leaves it empty on save, the server applies the same fallback as here.
function paletteOrAuto(key: string): string {
  const p = theme.value?.palette as Record<string, string> | undefined
  if (!p) return '#000000'
  const explicit = p[key]
  if (explicit) return explicit
  const surface = p.surface || '#000000'
  switch (key) {
    case 'accent_soft':
      return mixSolid(surface, p.accent || '#888888', 0.18)
    case 'accent_alt_fg':
      return contrastFg(p.accent_alt || '#888888')
    default:
      return ''
  }
}

// ===== Live preview iframe (postMessage) =====
const previewFrame = ref<HTMLIFrameElement | null>(null)
const previewReady = ref(false)

// Helpers to derive the SOLID "soft accents" client-side. Must stay
// aligned with Renderer::mixColors() / contrastFg() in PHP — the live
// preview applies the same values the server would emit on save.
function parseHex(c: string): [number, number, number] | null {
  if (!c) return null
  const s = c.trim()
  if (s.startsWith('#')) {
    const h = s.length === 4 ? s[1] + s[1] + s[2] + s[2] + s[3] + s[3] : s.slice(1)
    if (h.length !== 6 || !/^[0-9a-f]{6}$/i.test(h)) return null
    return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)]
  }
  const m = s.match(/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/i)
  return m ? [parseInt(m[1]), parseInt(m[2]), parseInt(m[3])] : null
}
function toHex(c: [number, number, number]): string {
  return '#' + c.map((v) => v.toString(16).padStart(2, '0')).join('')
}
function mixSolid(base: string, tint: string, t: number): string {
  const a = parseHex(base),
    b = parseHex(tint)
  if (!a || !b) return base
  const tt = Math.max(0, Math.min(1, t))
  return toHex([
    Math.round(a[0] * (1 - tt) + b[0] * tt),
    Math.round(a[1] * (1 - tt) + b[1] * tt),
    Math.round(a[2] * (1 - tt) + b[2] * tt),
  ])
}
function contrastFg(bg: string): string {
  const c = parseHex(bg)
  if (!c) return '#ffffff'
  const lin = (v: number) => {
    const s = v / 255
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4)
  }
  const L = 0.2126 * lin(c[0]) + 0.7152 * lin(c[1]) + 0.0722 * lin(c[2])
  return L > 0.55 ? '#1a1410' : '#ffffff'
}

// rgba(...) ⇄ {hex, alpha} for the color-picker of palette colors with
// alpha (e.g. `border`: the user picks a hex + drags an opacity slider
// instead of typing `rgba(...)` by hand, which was impractical).
function parseRgba(v: string): { hex: string; alpha: number } {
  const m = String(v || '').match(
    /^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)(?:[\s,/]+([\d.]+))?\s*\)$/i,
  )
  if (!m) {
    if (v && v.startsWith('#')) {
      const rgb = parseHex(v)
      if (rgb) return { hex: v, alpha: 1 }
    }
    return { hex: '#ffffff', alpha: 0.14 }
  }
  const r = parseInt(m[1])
  const g = parseInt(m[2])
  const b = parseInt(m[3])
  const a = m[4] !== undefined ? parseFloat(m[4]) : 1
  return {
    hex: '#' + [r, g, b].map((n) => n.toString(16).padStart(2, '0')).join(''),
    alpha: Math.max(0, Math.min(1, a)),
  }
}
function composeRgba(hex: string, alpha: number): string {
  const rgb = parseHex(hex)
  if (!rgb) return `rgba(255,255,255,${alpha.toFixed(2)})`
  return `rgba(${rgb[0]},${rgb[1]},${rgb[2]},${Number(alpha).toFixed(2)})`
}

function buildThemeVars(th: Theme): Record<string, string> {
  const p = th.palette
  const tile = th.tile
  const font = th.font
  const bg = th.background
  // Derived solid colors — see Renderer::themeCssVars() in PHP for the
  // complete documentation of the roles.
  const pp = p as Record<string, string>
  // accent_soft / accent_alt_fg: if the user has defined them in the
  // palette they win, otherwise auto-derived. Needed for patterns like
  // "white social pills" (impossible if soft were ONLY a function of the bg).
  const accentSoft = pp.accent_soft || mixSolid(p.surface, p.accent, 0.18)
  const accentFg = contrastFg(p.accent)
  const accentAltFg = pp.accent_alt_fg || contrastFg(p.accent_alt)
  return {
    '--bg': p.bg,
    '--surface': p.surface,
    '--surface-alt': p.surface_alt,
    '--text': p.text,
    '--text-muted': p.text_muted,
    '--accent': p.accent,
    '--accent-alt': p.accent_alt,
    '--accent-soft': accentSoft,
    '--accent-fg': accentFg,
    '--accent-alt-fg': accentAltFg,
    '--border': p.border,
    '--tile-radius': `${tile?.radius ?? 18}px`,
    '--tile-gap': `${tile?.gap ?? 14}px`,
    '--tile-border': `${tile?.border ?? 1}px`,
    '--tile-opacity': String(tile?.opacity ?? 0.7),
    '--font-heading': `"${font?.heading ?? 'Fraunces'}", serif`,
    '--font-body': `"${font?.body ?? 'Inter'}", system-ui, sans-serif`,
    '--bg-pattern-intensity': String(bg?.intensity ?? 0.12),
    '--bg-pattern-color': p.text_muted,
    '--bg-pattern-image': bg?.image ? `url('${bg.image.replace(/'/g, '%27')}')` : 'none',
  }
}

function pushThemeToPreview() {
  if (!theme.value || !previewFrame.value?.contentWindow) return
  const tt = theme.value.tile || {}
  const style = tt.style || 'solid'
  const opacity = Number(tt.opacity ?? 0.7)
  // tileCard = visually opaque tiles → deserve a single perimeter shadow.
  const tileCard =
    style === 'solid' || style === 'glass' || (style === 'transparent' && opacity >= 0.95)
  // Same origin (admin and public are served from the same vhost) → we
  // restrict targetOrigin to the current one, never '*'.
  // Font names: when the user changes heading/body in admin, the iframe
  // must be able to load the chosen font (Google Fonts isn't preloaded
  // because we don't know which one will be picked). We send sanitized
  // names via safeFont (only [A-Za-z0-9 ]) — the iframe re-validates.
  const headingFont = safeFont(theme.value.font?.heading, 'Fraunces')
  const bodyFont = safeFont(theme.value.font?.body, 'Inter')
  previewFrame.value.contentWindow.postMessage(
    {
      type: 'tylio:applyTheme',
      vars: buildThemeVars(theme.value),
      fonts: { heading: headingFont, body: bodyFont },
      bgPattern: theme.value.background?.pattern || 'mosaic',
      tileStyle: style,
      tileFlush: (Number(tt.gap) || 0) <= 0,
      tileCard,
      mobileSpacing: tt.mobile_spacing === 'minimal' ? 'minimal' : 'desktop',
    },
    window.location.origin,
  )
}

// Computed wrapper for the "Mobile spacing" <select>. Default 'desktop'
// when the theme has never set the value (back-compat with installs that
// predate this feature). Writes to `theme.tile.mobile_spacing`.
const mobileSpacingValue = computed<'desktop' | 'minimal'>({
  get() {
    const v = theme.value?.tile?.mobile_spacing
    return v === 'minimal' ? 'minimal' : 'desktop'
  },
  set(v: 'desktop' | 'minimal') {
    if (!theme.value) return
    if (!theme.value.tile) theme.value.tile = { radius: 18, gap: 14, border: 1 }
    theme.value.tile.mobile_spacing = v
  },
})

function onWindowMessage(e: MessageEvent) {
  // Ignore messages from foreign origins — the iframe is same-origin.
  if (e.origin !== window.location.origin) return
  if (e?.data?.type === 'tylio:previewReady') {
    previewReady.value = true
    pushThemeToPreview()
  }
}

onMounted(() => window.addEventListener('message', onWindowMessage))
onUnmounted(() => window.removeEventListener('message', onWindowMessage))

// On every theme change, propagate to the preview iframe (minimal debounce)
let pushTimer: number | undefined
watch(
  theme,
  () => {
    if (!previewReady.value) return
    if (pushTimer) clearTimeout(pushTimer)
    pushTimer = window.setTimeout(pushThemeToPreview, 60)
  },
  { deep: true },
)
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
      <p class="eyebrow">{{ t('theme.eyebrow') }}</p>
      <h1 class="heading">{{ t('theme.title') }}</h1>
    </div>
    <div class="flex items-center gap-2">
      <span
        v-if="isDirty"
        class="text-xs px-3 py-1.5 rounded-full bg-ink-100/10 text-ink-100 border border-ink-100/20 flex items-center gap-1.5"
      >
        <iconify-icon icon="lucide:circle" width="8"></iconify-icon>
        {{ t('theme.unsavedChanges') }}
      </span>
      <button v-if="isDirty" class="btn btn-ghost" @click="discardChanges">
        <iconify-icon icon="lucide:rotate-ccw" width="18"></iconify-icon>
        {{ t('theme.cancel') }}
      </button>
      <button class="btn btn-primary" :disabled="saving || !isDirty" @click="save">
        <iconify-icon
          :icon="saving ? 'lucide:loader-circle' : 'lucide:check'"
          width="18"
          :class="saving ? 'animate-spin' : ''"
        ></iconify-icon>
        {{ saving ? t('theme.saving') : t('theme.save') }}
      </button>
    </div>
  </div>

  <div v-if="theme" class="grid lg:grid-cols-[1fr_360px] gap-6 items-start">
    <div class="space-y-6">
      <section class="tile">
        <h2 class="font-display text-xl mb-1">{{ t('theme.palette.title') }}</h2>
        <i18n-t keypath="theme.presets.intro" tag="p" class="text-xs text-ink-300 mb-4">
          <template #nativa
            ><strong>{{ t('theme.presets.introNative') }}</strong></template
          >
          <template #sunrise
            ><strong>{{ t('theme.presets.introSunrise') }}</strong></template
          >
        </i18n-t>

        <div class="presets mb-6">
          <!-- Light/dark header shown once above the chip columns. -->
          <div class="preset-row preset-row--head">
            <span></span>
            <div class="preset-row__swatches">
              <span class="preset-col-head"
                ><iconify-icon icon="lucide:sun" width="14"></iconify-icon>
                {{ t('theme.presets.headLight') }}</span
              >
              <span class="preset-col-head"
                ><iconify-icon icon="lucide:moon" width="14"></iconify-icon>
                {{ t('theme.presets.headDark') }}</span
              >
            </div>
          </div>

          <div v-for="g in groupedPresets" :key="g.family" class="preset-row">
            <span class="preset-row__family">{{ familyLabel(g.family) }}</span>
            <div class="preset-row__swatches">
              <button
                v-for="p in g.items"
                :key="p.id"
                type="button"
                class="preset-chip"
                :class="{ 'is-active': isActivePreset(p) }"
                :style="{
                  '--swatch-accent': p.palette.accent,
                  '--swatch-border': p.palette.border,
                }"
                :title="p.label"
                @click="applyPreset(p)"
              >
                <!-- Left 1/3: background rectangle (bg). -->
                <span
                  class="preset-chip__bg"
                  :style="{ background: p.palette.bg }"
                  :title="t('theme.swatchTitle.bg')"
                ></span>
                <!-- Right 2/3: all palette colors as bars.
                     Adjacent pairs that live together in the UI:
                     surface + surface_alt (tile + interior)
                     text + text_muted (texts)
                     accent + accent_soft (accent + its contrast)
                     accent_alt + accent_alt_fg (accent_alt + its contrast)
                     border. Accents take 2fr, contrasts 1fr — see CSS. -->
                <span class="preset-chip__bars">
                  <i
                    :style="{ background: p.palette.surface }"
                    :title="t('theme.swatchTitle.surface')"
                  ></i>
                  <i
                    :style="{ background: p.palette.surface_alt }"
                    :title="t('theme.swatchTitle.surfaceAlt')"
                  ></i>
                  <i
                    :style="{ background: p.palette.text }"
                    :title="t('theme.swatchTitle.text')"
                  ></i>
                  <i
                    :style="{ background: p.palette.text_muted }"
                    :title="t('theme.swatchTitle.textMuted')"
                  ></i>
                  <i
                    :style="{ background: p.palette.accent }"
                    :title="t('theme.swatchTitle.accent')"
                  ></i>
                  <i
                    :style="{
                      background:
                        (p.palette as Record<string, string>).accent_soft ||
                        mixSolid(p.palette.surface, p.palette.accent, 0.18),
                    }"
                    :title="t('theme.swatchTitle.accentSoft')"
                  ></i>
                  <i
                    :style="{ background: p.palette.accent_alt }"
                    :title="t('theme.swatchTitle.accentAlt')"
                  ></i>
                  <i
                    :style="{
                      background:
                        (p.palette as Record<string, string>).accent_alt_fg ||
                        contrastFg(p.palette.accent_alt),
                    }"
                    :title="t('theme.swatchTitle.accentAltFg')"
                  ></i>
                  <i
                    :style="{ background: p.palette.border }"
                    :title="t('theme.swatchTitle.border')"
                  ></i>
                </span>
              </button>
            </div>
          </div>
        </div>

        <details class="group">
          <summary class="cursor-pointer eyebrow flex items-center gap-2 select-none">
            <iconify-icon
              icon="lucide:chevron-right"
              class="group-open:rotate-90 transition-transform"
              width="14"
            ></iconify-icon>
            {{ t('theme.palette.customize') }}
          </summary>
          <div class="grid sm:grid-cols-2 gap-3 mt-4">
            <div
              v-for="key in PALETTE_KEYS"
              :key="key"
              :class="{ 'sm:col-span-2': key === 'border' || key === 'surface_alt' }"
            >
              <!-- Label row: for `border` it's 2 columns (Border | Border
                   opacity) so labels stay aligned with the controls below.
                   For the other fields it's a single label. -->
              <div
                v-if="
                  key === 'border' ||
                  String(theme.palette[key] || '')
                    .toLowerCase()
                    .startsWith('rgba') ||
                  String(theme.palette[key] || '')
                    .toLowerCase()
                    .startsWith('rgb(')
                "
                class="grid sm:grid-cols-2 gap-3 mb-1.5"
              >
                <div
                  class="flex items-center gap-1.5 text-xs text-ink-300 normal-case tracking-wide"
                >
                  <InfoTip :text="colorHelp(key)" />
                  <span class="font-medium">{{ colorLabel(key) }}</span>
                </div>
                <div
                  class="flex items-baseline justify-between text-xs text-ink-300 normal-case tracking-wide"
                >
                  <span class="font-medium">{{ t('theme.palette.borderOpacity') }}</span>
                  <span class="font-medium">
                    {{ Math.round(parseRgba(theme.palette[key] || '').alpha * 100) }}%
                  </span>
                </div>
              </div>
              <div
                v-else
                class="flex items-center gap-1.5 mb-1.5 text-xs text-ink-300 normal-case tracking-wide"
              >
                <InfoTip :text="colorHelp(key)" />
                <span class="font-medium">{{ colorLabel(key) }}</span>
              </div>

              <!-- BORDER (rgba) — 2-column controls: normal hex on the left,
                   opacity slider on the right (the labels are in the row
                   above, aligned). -->
              <div
                v-if="
                  key === 'border' ||
                  String(theme.palette[key] || '')
                    .toLowerCase()
                    .startsWith('rgba') ||
                  String(theme.palette[key] || '')
                    .toLowerCase()
                    .startsWith('rgb(')
                "
                class="grid sm:grid-cols-2 gap-3 items-center"
              >
                <div class="flex gap-2 items-center">
                  <input
                    type="color"
                    :value="parseRgba(theme.palette[key] || '').hex"
                    @input="setRgbaHex(key, ($event.target as HTMLInputElement).value)"
                    class="!w-12 !h-10 !p-1"
                  />
                  <input
                    type="text"
                    :value="parseRgba(theme.palette[key] || '').hex"
                    @change="setRgbaHex(key, ($event.target as HTMLInputElement).value)"
                    class="flex-1"
                    :placeholder="t('theme.palette.hexPlaceholder')"
                  />
                </div>
                <input
                  type="range"
                  min="0"
                  max="1"
                  step="0.01"
                  :value="parseRgba(theme.palette[key] || '').alpha"
                  @input="setRgbaAlpha(key, ($event.target as HTMLInputElement).value)"
                  class="w-full"
                />
              </div>

              <!-- HEX COLORS — swatch + text input. For auto-derivable
                   fields (accent_soft / accent_alt_fg) the swatch and
                   the placeholder show the auto value if the user hasn't
                   set anything yet. -->
              <div v-else class="flex gap-2 items-center">
                <input
                  type="color"
                  :value="paletteOrAuto(key) || theme.palette[key] || '#000000'"
                  @input="theme.palette[key] = ($event.target as HTMLInputElement).value"
                  class="!w-12 !h-10 !p-1"
                />
                <input
                  v-model="theme.palette[key]"
                  type="text"
                  class="flex-1"
                  :placeholder="
                    paletteOrAuto(key)
                      ? paletteOrAuto(key) + t('theme.palette.autoSuffix')
                      : t('theme.palette.hexPlaceholder')
                  "
                />
              </div>
            </div>
          </div>
        </details>
      </section>

      <section class="tile">
        <h2 class="font-display text-xl mb-3">{{ t('theme.fonts.title') }}</h2>
        <!-- Heading and Body one per row: select at half width, the other
             half shows the font name written in the font itself (live
             preview). -->
        <div class="space-y-3">
          <div class="grid grid-cols-2 gap-3 items-end">
            <label class="!mb-0"
              >{{ t('theme.fonts.heading') }}
              <select v-model="theme.font.heading">
                <optgroup v-for="g in fontGroups" :key="g.label" :label="g.label">
                  <option v-for="f in g.fonts" :key="f" :value="f">
                    {{ f }}
                  </option>
                </optgroup>
              </select>
            </label>
            <div
              class="font-preview"
              :style="{ fontFamily: `'${safeFont(theme.font.heading, 'Fraunces')}', serif` }"
            >
              {{ safeFont(theme.font.heading, 'Fraunces') }}
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3 items-end">
            <label class="!mb-0"
              >{{ t('theme.fonts.body') }}
              <select v-model="theme.font.body">
                <optgroup v-for="g in fontGroups" :key="g.label" :label="g.label">
                  <option v-for="f in g.fonts" :key="f" :value="f">
                    {{ f }}
                  </option>
                </optgroup>
              </select>
            </label>
            <div
              class="font-preview"
              :style="{ fontFamily: `'${safeFont(theme.font.body, 'Inter')}', sans-serif` }"
            >
              {{ safeFont(theme.font.body, 'Inter') }}
            </div>
          </div>
        </div>
      </section>

      <section class="tile">
        <h2 class="font-display text-xl mb-3">{{ t('theme.tile.title') }}</h2>

        <!-- Style (solid / transparent / glass) -->
        <p class="text-xs text-ink-300 mb-2">{{ t('theme.tile.styleSection') }}</p>
        <div class="grid grid-cols-3 gap-2 mb-4">
          <button
            v-for="s in tileStyles"
            :key="s.id"
            type="button"
            class="tile-style-chip"
            :class="{ 'is-active': (theme.tile.style || 'solid') === s.id }"
            @click="theme.tile.style = s.id"
          >
            <span class="tile-style-chip__demo" :data-style="s.id"></span>
            <span class="tile-style-chip__label">{{ s.label }}</span>
          </button>
        </div>

        <label v-if="theme.tile.style === 'transparent'" class="mb-4 block">
          {{ t('theme.tile.opacity', { pct: Math.round((theme.tile.opacity ?? 0.7) * 100) }) }}
          <input v-model.number="theme.tile.opacity" type="range" min="0" max="1" step="0.05" />
        </label>
        <p
          v-else-if="theme.tile.style === 'glass'"
          class="mb-4 text-xs text-ink-300 leading-relaxed flex items-start gap-1.5"
        >
          <iconify-icon
            icon="lucide:info"
            width="14"
            class="text-ink-100 flex-shrink-0 mt-0.5"
          ></iconify-icon>
          <span>{{ t('theme.tile.glassHint') }}</span>
        </p>

        <p class="text-xs text-ink-300 mb-2 mt-2">{{ t('theme.tile.geometryDesktop') }}</p>
        <div class="grid sm:grid-cols-3 gap-3">
          <label
            >{{ t('theme.tile.radius') }}<input v-model.number="theme.tile.radius" type="number"
          /></label>
          <label
            >{{ t('theme.tile.gap') }}<input v-model.number="theme.tile.gap" type="number"
          /></label>
          <label
            >{{ t('theme.tile.border') }}<input v-model.number="theme.tile.border" type="number"
          /></label>
        </div>

        <p class="text-xs text-ink-300 mb-2 mt-5">{{ t('theme.tile.geometryMobile') }}</p>
        <div class="field">
          <label class="!mb-1 !text-ink-100 !text-sm !font-medium">{{
            t('theme.tile.mobileSpacing')
          }}</label>
          <select v-model="mobileSpacingValue">
            <option value="desktop">{{ t('theme.tile.mobileDesktop') }}</option>
            <option value="minimal">{{ t('theme.tile.mobileMinimal') }}</option>
          </select>
        </div>
        <p class="mt-2 text-xs text-ink-300 leading-relaxed flex items-start gap-1.5">
          <iconify-icon
            icon="lucide:info"
            width="14"
            class="text-ink-100 flex-shrink-0 mt-0.5"
          ></iconify-icon>
          <i18n-t keypath="theme.tile.mobileHint" tag="span">
            <template #minimal
              ><strong>{{ t('theme.tile.mobileMinimal') }}</strong></template
            >
          </i18n-t>
        </p>
      </section>

      <section class="tile">
        <h2 class="font-display text-xl mb-3">{{ t('theme.background.title') }}</h2>
        <p class="text-xs text-ink-300 mb-3">{{ t('theme.background.hint') }}</p>

        <div class="grid grid-cols-3 sm:grid-cols-4 gap-2 mb-4">
          <button
            v-for="p in patterns"
            :key="p.id"
            type="button"
            class="relative aspect-square rounded-lg border transition overflow-hidden flex flex-col items-center justify-end p-2 text-[11px]"
            :class="
              (theme.background.pattern || 'mosaic') === p.id
                ? 'border-ink-100 ring-1 ring-ink-100/40'
                : 'border-white/5 hover:border-ink-100/40'
            "
            @click="theme.background.pattern = p.id"
          >
            <div
              class="absolute inset-0"
              :style="{ ...patternPreviewStyle(p.id), opacity: thumbOpacity(p.id) }"
            ></div>
            <!-- Photo without selected image: central placeholder icon.
                 `bottom-7` (28px) leaves room for the "Photo" label below,
                 so the icon is centered in the available space above. -->
            <div
              v-if="p.id === 'image' && !theme.background.image"
              class="absolute top-0 left-0 right-0 bottom-7 grid place-items-center pointer-events-none"
            >
              <iconify-icon icon="lucide:image" width="34" class="text-ink-300"></iconify-icon>
            </div>
            <span class="relative z-10 bg-ink-900/80 backdrop-blur-sm px-2 py-0.5 rounded">{{
              p.label
            }}</span>
          </button>
        </div>

        <div v-if="theme.background.pattern === 'image'" class="mb-4">
          <label>{{ t('theme.background.image') }}</label>
          <OgImageUploader
            aspect="wide"
            :placeholder="t('theme.background.imagePlaceholder')"
            :model-value="theme.background.image ?? ''"
            @update:model-value="setBgImage"
          />
          <p class="text-xs text-ink-300 mt-1">
            {{ t('theme.background.imageHint') }}
          </p>
        </div>

        <label v-if="theme.background.pattern !== 'none'" class="mt-2"
          >{{
            t('theme.background.intensity', {
              pct: Math.round((theme.background.intensity ?? 0) * 100),
            })
          }}
          <input
            v-model.number="theme.background.intensity"
            type="range"
            min="0"
            max="0.5"
            step="0.01"
            class="!p-0"
          />
        </label>
      </section>
    </div>

    <aside class="tile sticky top-6">
      <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2">
          <iconify-icon icon="lucide:smartphone" width="18" class="text-ink-100"></iconify-icon>
          <h3 class="font-display text-lg">{{ t('theme.preview.title') }}</h3>
        </div>
        <button class="btn-icon" :title="t('theme.preview.reload')" @click="cacheBust = Date.now()">
          <iconify-icon icon="lucide:refresh-cw" width="16"></iconify-icon>
        </button>
      </div>
      <div
        class="rounded-xl overflow-hidden border border-white/5 bg-black"
        style="aspect-ratio: 9/16"
      >
        <iframe
          ref="previewFrame"
          :src="`/api/preview?t=${cacheBust}`"
          class="w-full h-full border-0"
          :title="t('theme.preview.frameTitle')"
          @load="pushThemeToPreview"
        />
      </div>
      <p class="text-xs text-ink-300 mt-2">
        {{ t('theme.preview.hint') }}
      </p>
    </aside>
  </div>
</template>

<style scoped>
/* Font preview: writes the font name using the font itself. Aligned in
   height with the adjacent select (min-height 42px = select height). */
.font-preview {
  min-height: 42px;
  display: flex;
  align-items: center;
  font-size: 22px;
  line-height: 1.1;
  color: theme('colors.ink.100');
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  padding-left: 4px;
}

/* Compact preset layout: name | [light] [dark]. Light/dark header shown once. */
.presets {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.preset-row {
  display: grid;
  grid-template-columns: 180px 1fr;
  gap: 12px;
  align-items: center;
}
@media (max-width: 540px) {
  .preset-row {
    grid-template-columns: 1fr;
    gap: 4px;
  }
}
.preset-row--head {
  margin-bottom: 2px;
}
.preset-row__family {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.14em;
  color: rgb(var(--ink-300-rgb));
  white-space: nowrap;
}
/* Two deterministic columns: light on the left, dark on the right. Aligns
   the chips with their respective headers. */
.preset-row__swatches {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
}

.preset-col-head {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.14em;
  color: rgb(var(--ink-300-rgb));
  padding: 0 4px;
}
.preset-col-head iconify-icon {
  color: rgb(var(--ink-100-rgb));
}

/* Preset chip: "bg" rectangle on the left (1/3), vertical bars of the
   key colors on the right (2/3). The bars share a single width and show
   adjacent pairs that map to real usage: button (accent + button text)
   and badge (accent_alt + badge text). No inner padding/margin — colors
   span the full height for at-a-glance reading. */
.preset-chip {
  height: 36px;
  border-radius: 10px;
  /* Outline = palette `border` color WITH its real opacity. Communicates
     at a glance how strong the theme's border is. */
  border: 1px solid var(--swatch-border, rgba(0, 0, 0, 0.18));
  display: grid;
  grid-template-columns: 1fr 2fr;
  overflow: hidden;
  cursor: pointer;
  padding: 0;
  background: transparent;
  transition:
    transform 0.15s ease,
    box-shadow 0.15s ease,
    border-color 0.15s ease;
}
.preset-chip:hover {
  transform: translateY(-1px);
  border-color: var(--swatch-accent, rgba(0, 0, 0, 0.35));
}
/* Selected chip: `--ink-100-rgb` border (primary text) — always in
   contrast with any chip background (text and surface are opposites in
   every palette). No shadow: the solid border is enough. */
.preset-chip.is-active {
  border: 2px solid rgb(var(--ink-100-rgb));
  box-shadow: none;
}
.preset-chip__bg {
  display: block;
  width: 100%;
  height: 100%;
}
.preset-chip__bars {
  display: grid;
  /* 9 bars = all palette colors except bg.
     Order + weights (1..9): surface, surface_alt, text, text_muted,
     accent(2fr), accent_soft, accent_alt(2fr), accent_alt_fg, border.
     The two accents are 2fr for visual pop; their adjacent contrast is
     1fr so the accent/contrast pair reads as a "block". */
  grid-template-columns: 1fr 1fr 1fr 1fr 2fr 1fr 2fr 1fr 1fr;
  width: 100%;
  height: 100%;
}
.preset-chip__bars i {
  display: block;
  width: 100%;
  height: 100%;
}

/* Tile style — chip with visual mini-preview of the behavior */
.tile-style-chip {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 10px 8px;
  border-radius: 12px;
  border: 1px solid rgb(var(--ink-300-rgb) / 0.25);
  background: rgb(var(--ink-800-rgb));
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    transform 0.15s ease,
    background 0.15s ease;
}
.tile-style-chip:hover {
  transform: translateY(-1px);
  border-color: rgb(var(--accent-rgb) / 0.5);
}
.tile-style-chip.is-active {
  border: 2px solid rgb(var(--accent-rgb));
  padding: 9px 7px; /* compensate +1px border */
}
.tile-style-chip__demo {
  display: block;
  width: 100%;
  aspect-ratio: 16 / 7;
  border-radius: 8px;
  /* fake "underlying" background with pattern to show transparency clearly */
  background-image:
    linear-gradient(rgb(var(--accent-rgb) / 0.5), rgb(var(--accent-rgb) / 0.5)),
    repeating-linear-gradient(45deg, transparent 0 6px, rgb(var(--ink-100-rgb) / 0.18) 6px 8px);
  position: relative;
  overflow: hidden;
}
.tile-style-chip__demo::after {
  content: '';
  position: absolute;
  inset: 18% 25%;
  background: rgb(var(--ink-900-rgb));
  border-radius: 5px;
  border: 1px solid rgba(255, 255, 255, 0.06);
}
/* Solid: full tile */
.tile-style-chip__demo[data-style='solid']::after {
  background: rgb(var(--ink-900-rgb));
}
/* Transparent: tile at 60% bg, lets the pattern behind show through */
.tile-style-chip__demo[data-style='transparent']::after {
  background: rgb(var(--ink-900-rgb) / 0.55);
}
/* Glass: blurred tile (simulated with filter on virtual parent via box-shadow + semi bg) */
.tile-style-chip__demo[data-style='glass']::after {
  background: rgb(var(--ink-900-rgb) / 0.45);
  -webkit-backdrop-filter: blur(4px);
  backdrop-filter: blur(4px);
  box-shadow: inset 0 0 0 1px rgb(255, 255, 255, 0.12);
}
.tile-style-chip__label {
  font-size: 12px;
  color: rgb(var(--ink-100-rgb));
}
</style>
