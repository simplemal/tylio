/**
 * Syncs the admin's theme with the public site's palette.
 * Fetches /api/theme/public (no auth), converts the colors to RGB triplets
 * and sets the CSS variables on :root, which in turn feed Tailwind.
 *
 * Used at boot and after every save from the Theme editor (see views/Theme.vue).
 */

export interface PublicPalette {
  bg?: string
  surface?: string
  surface_alt?: string
  text?: string
  text_muted?: string
  accent?: string
  accent_soft?: string
  accent_alt?: string
  accent_alt_fg?: string
  border?: string
}

// Versioned key: bump it when the palette shape changes, so old
// snapshots in localStorage are ignored rather than applied with missing
// or no-longer-valid fields.
const KEY_LS = 'tylio:lastTheme:v1'

function hexToRgb(input: string): [number, number, number] | null {
  if (!input) return null
  const s = input.trim().toLowerCase()
  // #rgb / #rrggbb
  if (s.startsWith('#')) {
    const hex = s.slice(1)
    if (hex.length === 3) {
      const r = parseInt(hex[0] + hex[0], 16)
      const g = parseInt(hex[1] + hex[1], 16)
      const b = parseInt(hex[2] + hex[2], 16)
      return [r, g, b]
    }
    if (hex.length === 6) {
      const r = parseInt(hex.slice(0, 2), 16)
      const g = parseInt(hex.slice(2, 4), 16)
      const b = parseInt(hex.slice(4, 6), 16)
      return [r, g, b]
    }
  }
  // rgb(R, G, B) or rgba(R, G, B, A)
  const m = s.match(/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/)
  if (m) return [parseInt(m[1]), parseInt(m[2]), parseInt(m[3])]
  return null
}

function rgbStr(c: [number, number, number] | null, fallback: string): string {
  return c ? `${c[0]} ${c[1]} ${c[2]}` : fallback
}

function mix(
  a: [number, number, number],
  b: [number, number, number],
  t: number,
): [number, number, number] {
  return [
    Math.round(a[0] * (1 - t) + b[0] * t),
    Math.round(a[1] * (1 - t) + b[1] * t),
    Math.round(a[2] * (1 - t) + b[2] * t),
  ]
}

function luminance(c: [number, number, number]): number {
  // WCAG relative luminance
  const f = (v: number) => {
    const s = v / 255
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4)
  }
  return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2])
}

// "Chromaticity proxy": how much the color deviates from neutral grey.
// max(R,G,B) − min(R,G,B); 0 means perfectly grey, 255 means pure hue.
function chromaticity(c: [number, number, number]): number {
  return Math.max(...c) - Math.min(...c)
}

// Whether a color is usable as the admin's "backend accent" — i.e. the
// background color of toggles-ON, active nav items, and primary buttons.
// Rejects three failure modes:
//   1. too light (e.g. #eeeeee — invisible on a light card)
//   2. too dark  (e.g. #2c2c2c — looks like a disabled state on dark)
//   3. too grey  (R≈G≈B — feels like a placeholder, not an accent)
// Thresholds chosen empirically; tweak in one place if needed.
function passesBackendAccentChecks(c: [number, number, number]): boolean {
  const lum = luminance(c)
  if (lum > 0.85) return false // too light
  if (lum < 0.1) return false // too dark
  if (chromaticity(c) < 10) return false // too grey
  return true
}

/**
 * Pick the admin's "backend accent" with a 3-step cascade so the active
 * nav item / toggle-ON / primary button always have a vivid, readable
 * color regardless of the palette the user picked.
 *
 * Each step yields BOTH the background and its companion foreground —
 * the foreground choice is part of the cascade, not an afterthought:
 *
 *   1. `accent`      passes checks → bg=accent,     fg=accentSoft
 *      (when the primary accent is vivid, use the palette author's
 *      "contrast on main accent" as the text color on top of it)
 *
 *   2. `accentSoft`  passes checks → bg=accentSoft, fg=accent
 *      (when the primary is too neutral, the "contrast on accent" is
 *      usually a vivid foil — Pink Lady · light has accent=#fff and
 *      accent_soft=#ff5fa8; we swap them so the vivid one becomes the
 *      backdrop and the neutral one becomes the text)
 *
 *   3. fallback                  → bg=text,        fg=surface
 *      (both candidates are neutral or out-of-range; the old "text on
 *      surface" inversion is guaranteed to contrast in any palette)
 */
function pickBackendAccent(
  accent: [number, number, number],
  accentSoft: [number, number, number],
  text: [number, number, number],
  surface: [number, number, number],
): { bg: [number, number, number]; fg: [number, number, number] } {
  if (passesBackendAccentChecks(accent)) return { bg: accent, fg: accentSoft }
  if (passesBackendAccentChecks(accentSoft)) return { bg: accentSoft, fg: accent }
  return { bg: text, fg: surface }
}

export function applyPalette(palette: PublicPalette): void {
  const root = document.documentElement.style

  const bg = hexToRgb(palette.bg ?? '') ?? [15, 13, 10]
  const surface = hexToRgb(palette.surface ?? '') ?? [26, 22, 18]
  const surfaceAlt = hexToRgb(palette.surface_alt ?? '') ?? [34, 28, 23]
  const text = hexToRgb(palette.text ?? '') ?? [244, 237, 225]
  const textMuted = hexToRgb(palette.text_muted ?? '') ?? [156, 142, 124]
  const accent = hexToRgb(palette.accent ?? '') ?? [212, 165, 116]
  const accentAlt = hexToRgb(palette.accent_alt ?? '') ?? [232, 197, 152]
  const accentDeep = mix(accent, bg, 0.35)
  // accent-soft = user-controllable as "Contrast on main accent".
  // If explicit in the palette → use it. Otherwise fall back to the
  // WCAG auto-derived value (same as --accent-fg, kept for back-compat).
  const accentFgAuto: [number, number, number] =
    luminance(accent) > 0.55 ? [26, 20, 16] : [255, 255, 255]
  const accentSoft = hexToRgb(palette.accent_soft ?? '') ?? accentFgAuto
  // accent-alt-fg = user-controllable as "Contrast on secondary accent".
  const accentAltFgAuto: [number, number, number] =
    luminance(accentAlt) > 0.55 ? [26, 20, 16] : [255, 255, 255]
  const accentAltFg = hexToRgb(palette.accent_alt_fg ?? '') ?? accentAltFgAuto
  const ink700 = mix(surfaceAlt, text, 0.08)
  const ink500 = mix(surfaceAlt, textMuted, 0.4)

  root.setProperty('--ink-950-rgb', rgbStr(bg, '15 13 10'))
  root.setProperty('--ink-900-rgb', rgbStr(surface, '26 22 18'))
  root.setProperty('--ink-800-rgb', rgbStr(surfaceAlt, '34 28 23'))
  root.setProperty('--ink-700-rgb', rgbStr(ink700, '42 34 27'))
  root.setProperty('--ink-500-rgb', rgbStr(ink500, '102 84 73'))
  root.setProperty('--ink-300-rgb', rgbStr(textMuted, '156 142 124'))
  root.setProperty('--ink-100-rgb', rgbStr(text, '244 237 225'))
  root.setProperty('--accent-rgb', rgbStr(accent, '212 165 116'))
  root.setProperty('--accent-alt-rgb', rgbStr(accentAlt, '232 197 152'))
  root.setProperty('--accent-deep-rgb', rgbStr(accentDeep, '168 122 74'))
  root.setProperty('--accent-fg-rgb', rgbStr(accentFgAuto, '26 20 16'))
  root.setProperty('--accent-soft-rgb', rgbStr(accentSoft, '255 255 255'))
  root.setProperty('--accent-alt-fg-rgb', rgbStr(accentAltFg, '26 20 16'))

  // Backend accent: the color used by admin UI surfaces (primary
  // button, active nav item, settings toggle ON). The cascade returns
  // BOTH the bg and the companion fg — we never auto-derive the fg
  // from luminance alone, because the palette author's explicit
  // "contrast on accent" choice (`accent_soft`) carries information
  // WCAG can't recover (e.g. a brand-specific tinted dark instead of
  // pure black). See `pickBackendAccent` for the cascade rules.
  const backend = pickBackendAccent(accent, accentSoft, text, surface)
  root.setProperty('--backend-accent-rgb', rgbStr(backend.bg, '212 165 116'))
  root.setProperty('--backend-accent-fg-rgb', rgbStr(backend.fg, '26 20 16'))

  // Background glow: subtle accent-colored vignette.
  root.setProperty('--bg-glow-1', `rgba(${accent.join(',')},.10)`)
  root.setProperty('--bg-glow-2', `rgba(${accentAlt.join(',')},.07)`)

  // Browser theme-color (mobile chrome bar).
  let meta = document.querySelector<HTMLMetaElement>('meta[name="theme-color"]')
  if (!meta) {
    meta = document.createElement('meta')
    meta.name = 'theme-color'
    document.head.appendChild(meta)
  }
  meta.content = `rgb(${bg.join(',')})`

  // Light/dark mode inferred from bg luminance. Lets custom styles adapt
  // (e.g. a "green" badge that's only readable on dark bg → on a light
  // theme it picks a darker variant).
  const bgLuma = luminance(bg)
  document.documentElement.dataset.themeMode = bgLuma > 0.5 ? 'light' : 'dark'

  // Persist to localStorage so the next boot applies instantly.
  try {
    localStorage.setItem(KEY_LS, JSON.stringify(palette))
  } catch {
    /* ignore */
  }
}

export async function bootstrapTheme(): Promise<void> {
  // 1) Apply the snapshot in localStorage immediately (no flash of wrong theme on refresh)
  try {
    const cached = localStorage.getItem(KEY_LS)
    if (cached) applyPalette(JSON.parse(cached) as PublicPalette)
  } catch {
    /* ignore */
  }

  // 2) In parallel: fetch the up-to-date palette from the server
  try {
    const r = await fetch('/api/theme/public', { credentials: 'omit', cache: 'no-cache' })
    if (r.ok) {
      const j = await r.json()
      if (j?.theme?.palette) applyPalette(j.theme.palette as PublicPalette)
    }
  } catch {
    /* ignore */
  }
}
