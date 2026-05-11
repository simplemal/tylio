/**
 * Admin SPA internationalization (vue-i18n v11).
 *
 * **Locale selection priority:**
 *   1. `localStorage['tylio.locale']` — explicit user override (set when
 *      the user changes language in Settings)
 *   2. `navigator.language` / `navigator.languages[]` — browser exposure
 *   3. Hard fallback: English
 *
 * **Standard:** ICU-style placeholders via vue-i18n's `{name}` syntax.
 * Add a new locale by dropping a `locales/<code>.json` file and adding
 * `<code>` to `SUPPORTED_LOCALES`. Keep keys 1:1 between locales — vue-i18n
 * falls back to `fallbackLocale` when a key is missing, which is the
 * developer-visible safety net.
 */

import { createI18n } from 'vue-i18n'
import en from './locales/en.json'
import it from './locales/it.json'

export const SUPPORTED_LOCALES = ['en', 'it'] as const
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number]
export const DEFAULT_LOCALE: SupportedLocale = 'en'
const STORAGE_KEY = 'tylio.locale'

/**
 * Resolve the best locale match for a BCP-47 tag (e.g. "it-IT" → "it").
 * Returns `null` if no supported locale matches.
 */
function matchSupported(tag: string | undefined | null): SupportedLocale | null {
  if (!tag) return null
  const short = tag.toLowerCase().split(/[-_]/)[0]
  return (SUPPORTED_LOCALES as readonly string[]).includes(short)
    ? (short as SupportedLocale)
    : null
}

/**
 * Pick the initial locale on app boot. Reads localStorage first
 * (user override), then walks `navigator.languages` in order so a user
 * with `["it-IT", "en-US"]` gets Italian. Falls back to DEFAULT_LOCALE.
 */
export function detectInitialLocale(): SupportedLocale {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY)
    const fromStore = matchSupported(stored)
    if (fromStore) return fromStore
  } catch {
    // localStorage may throw in private mode / restricted contexts
  }
  const langs: readonly string[] = Array.isArray(navigator.languages)
    ? navigator.languages
    : [navigator.language]
  for (const l of langs) {
    const m = matchSupported(l)
    if (m) return m
  }
  return DEFAULT_LOCALE
}

/**
 * Persist the user's explicit language choice (called from Settings).
 * Safe-no-op when storage is unavailable.
 */
export function persistLocale(locale: SupportedLocale): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    /* noop */
  }
}

export const i18n = createI18n({
  legacy: false, // Composition API mode
  globalInjection: true, // expose $t in templates
  locale: detectInitialLocale(),
  fallbackLocale: DEFAULT_LOCALE,
  messages: { en, it },
  missingWarn: import.meta.env.DEV,
  fallbackWarn: import.meta.env.DEV,
})

/**
 * Programmatic locale switch (used by the language selector in Settings).
 * Updates both vue-i18n and localStorage so the choice survives reloads.
 * Also updates `<html lang>` for screen readers and right-click translate.
 */
export function setLocale(locale: SupportedLocale): void {
  i18n.global.locale.value = locale
  persistLocale(locale)
  document.documentElement.setAttribute('lang', locale)
}

/**
 * Clear the explicit user override and fall back to browser detection.
 * Used by the "Browser default" option in the Settings language selector.
 */
export function clearLocaleOverride(): SupportedLocale {
  try {
    window.localStorage.removeItem(STORAGE_KEY)
  } catch {
    /* noop */
  }
  const detected = detectInitialLocale()
  i18n.global.locale.value = detected
  document.documentElement.setAttribute('lang', detected)
  return detected
}
