/**
 * Theme presets.
 *
 * Each preset declares ALL 10 palette keys (including the "contrast" pair
 * accent_soft and accent_alt_fg). No runtime fallback: what you see here
 * is what the user sees and can edit.
 */
import type { Theme } from './types'

export interface ThemePreset {
  id: string
  family: string // UI grouping ('nativo', ...)
  label: string // displayed name ('Neon · chiaro')
  mode: 'light' | 'dark'
  palette: Theme['palette']
}

export const PRESETS: ThemePreset[] = [
  {
    // NEON · light: "neon on paper" vibe — cool light slate body, saturated
    // pink/purple accents.
    //  - accent #e942a8 magenta + accent_alt #7223d8 violet (pops on pale slate)
    //  - accent_soft #ededef: used as button text (light text on a magenta
    //    background) AND as chip bg (link icon, social pill) → chips nearly
    //    invisible on the #f4f5fa tile
    //  - accent_alt_fg #d9cafe lavender: badge text (lavender text on the
    //    violet — readable and on-brand)
    id: 'neon-light',
    family: 'nativo',
    label: 'Neon · chiaro',
    mode: 'light',
    palette: {
      name: 'neon-light',
      bg: '#eceff8',
      surface: '#ffffff',
      surface_alt: '#e0e1e4',
      text: '#7d258e',
      text_muted: '#6d1b5c',
      accent: '#ffffff',
      accent_alt: '#7223d8',
      accent_soft: '#fc82df',
      accent_alt_fg: '#d9cafe',
      border: 'rgba(26,28,37,0.10)',
    },
  },
  // ============ PINK LADY — inspired by "Blossom Rush": pale pink canvas,
  // vivid magenta accents + violet/plum for the complement. Romantic vibe
  // with saturated highlights. ============
  {
    id: 'pink-lady-light',
    family: 'pink-lady',
    label: 'Pink Lady · chiaro',
    mode: 'light',
    palette: {
      name: 'pink-lady-light',
      bg: '#efeff1',
      surface: '#ffffff',
      surface_alt: '#ffffff',
      text: '#d45898',
      text_muted: '#a075a3',
      accent: '#ffffff',
      accent_soft: '#d45898',
      accent_alt: '#f9d3e0',
      accent_alt_fg: '#9a244f',
      border: 'rgba(44,44,44,0.10)',
    },
  },
  {
    id: 'pink-lady-dark',
    family: 'pink-lady',
    label: 'Pink Lady · scuro',
    mode: 'dark',
    palette: {
      name: 'pink-lady-dark',
      bg: '#2a0e26',          // very dark plum (pink night)
      surface: '#3d1838',      // mid plum — tile
      surface_alt: '#4a2245',  // light plum — interior
      text: '#fce4ec',         // pale pink — signature
      text_muted: '#c89dba',   // dusty mauve
      accent: '#ff5cc4',       // bright magenta (pops on dark)
      accent_alt: '#a366ff',   // violet (code purple keyword)
      accent_soft: '#2a0e26',  // = bg (dark on the magenta button)
      accent_alt_fg: '#2a0e26',// = bg (dark on the violet badge)
      border: 'rgba(252,228,236,0.10)',
    },
  },

  // ============ FOREST — inspired by Everforest: earthy greens + warm
  // orange accents. "Wooden desk with moss" vibe. ============
  {
    id: 'forest-light',
    family: 'forest',
    label: 'Forest · chiaro',
    mode: 'light',
    palette: {
      name: 'forest-light',
      bg: '#fdf6e3',
      surface: '#fffbef',
      surface_alt: '#ffffff',
      text: '#5c6a72',
      text_muted: '#939f91',
      accent: '#fffbef',
      accent_alt: '#efcf12',
      accent_soft: '#6e7d09',
      accent_alt_fg: '#ffffff',
      border: 'rgba(92,106,114,0.10)',
    },
  },
  {
    id: 'forest-dark',
    family: 'forest',
    label: 'Forest · scuro',
    mode: 'dark',
    palette: {
      name: 'forest-dark',
      bg: '#232a2e',          // deep forest night (Everforest bg_dim)
      surface: '#2d353b',      // tile (Everforest bg)
      surface_alt: '#414a4d',  // interior (Everforest bg2)
      text: '#d3c6aa',         // Everforest cream
      text_muted: '#859289',   // muted green-grey (comments)
      accent: '#a7c080',       // Everforest green (signature)
      accent_alt: '#e69875',   // Everforest warm orange
      accent_soft: '#2d353b',  // = surface (invisible chip)
      accent_alt_fg: '#232a2e',// = bg (dark on the orange)
      border: 'rgba(211,198,170,0.10)',
    },
  },

  // ============ COCKTAIL — inspired by Andromeda: nocturnal navy + a
  // cocktail of aqua/pink/magenta accents. "Digital happy hour" vibe. ============
  {
    id: 'cocktail-light',
    family: 'cocktail',
    label: 'Cocktail · chiaro',
    mode: 'light',
    palette: {
      name: 'cocktail-light',
      bg: '#f0f1f5',          // cool light off-white
      surface: '#ffffff',      // pure white tile
      surface_alt: '#e3e5ec',  // light cool grey
      text: '#23262e',         // mirror of the dark bg
      text_muted: '#6c708a',   // cool grey
      accent: '#009d85',       // deeper teal (darker than aqua for readability)
      accent_alt: '#d63878',   // deep saturated pink
      accent_soft: '#ffffff',  // = surface
      accent_alt_fg: '#ffffff',// pure white on the pink
      border: 'rgba(35,38,46,0.10)',
    },
  },
  {
    id: 'cocktail-dark',
    family: 'cocktail',
    label: 'Cocktail · scuro',
    mode: 'dark',
    palette: {
      name: 'cocktail-dark',
      bg: '#23262e',          // Andromeda nocturnal navy
      surface: '#2b2e38',      // slightly lighter navy
      surface_alt: '#383b46',  // interior
      text: '#d5ced9',         // cool light
      text_muted: '#898eaa',   // cool grey
      accent: '#00e8c6',       // Andromeda aqua signature
      accent_alt: '#ff5995',   // hot pink (Andromeda)
      accent_soft: '#2b2e38',  // = surface
      accent_alt_fg: '#23262e',// = bg (dark on the pink)
      border: 'rgba(213,206,217,0.10)',
    },
  },

  // ============ COMMANDER — inspired by the Commodore 64 / commander
  // terminals. Light = "printed in blue ink on paper": neutral grey bg,
  // electric-blue signature text, every accent on blue (mono-accent) with
  // CRT-yellow + lavender contrasts. Dark = "phosphor monitor": absolute
  // black, super-saturated electric blue + red, CRT-yellow text_muted. ===
  {
    // COMMANDER · light: blue-on-paper monochrome. Main text IS electric
    // blue (signature inversion from dark, where text is cream).
    //  - text #0433ff: draws the eye to the content, on-brand commander
    //  - accent = accent_alt = #0433ff: everything "active" is blue —
    //    uniform and hyper-readable on paper
    //  - accent_soft #fffb00 (CRT yellow): button text — 8-bit pairing
    //  - accent_alt_fg #d9cafe (lavender): badge text — interrupts the
    //    solid blue with a cool note, avoids "all-blue" badges
    id: 'commander-light',
    family: 'commander',
    label: 'Commander · chiaro',
    mode: 'light',
    palette: {
      name: 'commander-light',
      bg: '#ebebeb',
      surface: '#ffffff',
      surface_alt: '#f1f1f3',
      text: '#0433ff',
      text_muted: '#919191',
      accent: '#0433ff',
      accent_alt: '#0433ff',
      accent_soft: '#fffb00',
      accent_alt_fg: '#d9cafe',
      border: 'rgba(26,28,37,0.10)',
    },
  },
  {
    id: 'commander-dark',
    family: 'commander',
    label: 'Commander · scuro',
    mode: 'dark',
    palette: {
      name: 'commander-dark',
      bg: '#000000',
      surface: '#000000',
      surface_alt: '#0433ff',
      text: '#f8f8f2',
      text_muted: '#fffc41',
      accent: '#0433ff',
      accent_alt: '#ff2600',
      accent_soft: '#fffb00',
      accent_alt_fg: '#fffb00',
      border: 'rgba(170,170,170,0.40)',
    },
  },

  // ============ MATRIX — green-on-black CRT terminal (and a paper twin).
  // Dark = "Matrix screen": all black, two greens (pale + dark) for text,
  // electric green as accent, black contrasts on green buttons.
  // Light = "hacker print": all white, two dark greens readable on white,
  // BLACK buttons with green icon/text (inverse of dark — on dark the
  // button is green on a black tile, on light it's black on a white
  // tile). The green border @ 40% is the signature of both modes (a
  // green-mesh look).
  {
    id: 'matrix-light',
    family: 'matrix',
    label: 'Matrix · chiaro',
    mode: 'light',
    palette: {
      name: 'matrix-light',
      bg: '#ffffff',
      surface: '#ffffff',
      surface_alt: '#ffffff',
      text: '#00a000',         // saturated green readable on white
      text_muted: '#4f7a28',   // dark olive green (text hierarchy)
      accent: '#000000',       // BLACK button (inverted terminal)
      accent_alt: '#000000',
      accent_soft: '#00f900',  // electric green = text/icon on the black buttons
      accent_alt_fg: '#00f900',
      border: 'rgba(0,249,0,0.40)',
    },
  },
  {
    id: 'matrix-dark',
    family: 'matrix',
    label: 'Matrix · scuro',
    mode: 'dark',
    palette: {
      name: 'matrix-dark',
      bg: '#000000',
      surface: '#000000',
      surface_alt: '#000000',
      text: '#cce8b5',
      text_muted: '#669c35',
      accent: '#00f900',
      accent_alt: '#00f900',
      accent_soft: '#000000',
      accent_alt_fg: '#000000',
      border: 'rgba(0,249,0,0.40)',
    },
  },

  // ============ NORDIC — inspired by the Nord theme (arctic-bluish-clean):
  // Polar Night for dark backgrounds, Snow Storm for light text/bg, Frost
  // for cool accents (cyan/blue), Aurora for the warm complement (yellow
  // or orange). "Typography on arctic paper" aesthetic. ============
  {
    id: 'nordic-light',
    family: 'nordic',
    label: 'Nordic · chiaro',
    mode: 'light',
    palette: {
      name: 'nordic-light',
      bg: '#e5e9f0',
      surface: '#ffffff',
      surface_alt: '#efeff1',
      text: '#2e3440',
      text_muted: '#59718b',
      accent: '#5e81ac',
      accent_alt: '#8db9c7',
      accent_soft: '#f5faff',
      accent_alt_fg: '#ffffff',
      border: 'rgba(46,52,64,0.10)',
    },
  },
  {
    id: 'nordic-dark',
    family: 'nordic',
    label: 'Nordic · scuro',
    mode: 'dark',
    palette: {
      name: 'nordic-dark',
      bg: '#2e3440',          // Polar Night 0
      surface: '#3b4252',      // Polar Night 1
      surface_alt: '#434c5e',  // Polar Night 2
      text: '#eceff4',         // Snow Storm 6
      text_muted: '#d8dee9',   // Snow Storm 4
      accent: '#d8dee9',       // Snow Storm 4 — off-white as primary accent
      accent_alt: '#88c0d0',   // Frost 8 — cyan signature as secondary
      accent_soft: '#3b4252',  // = surface (dark on the off-white)
      accent_alt_fg: '#2e3440',// = bg (dark slate on the cyan, readable badge)
      border: 'rgba(216,222,233,0.10)',
    },
  },

  // ============ ELEGANT TURQUOISE — minimal monochromatic slate, with
  // turquoise as the ONLY accent color in the text and saturated magenta
  // on the badges. accent = bg/surface_alt → "invisible" buttons that
  // come alive thanks to text contrast alone. ============
  {
    id: 'turquoise-light',
    family: 'turquoise',
    label: 'Elegant Turquoise · chiaro',
    mode: 'light',
    palette: {
      name: 'turquoise-light',
      bg: '#f5f5f5',
      surface: '#ffffff',
      surface_alt: '#f6f6f6',
      text: '#02adb5',
      text_muted: '#393e46',
      accent: '#a8dfea',
      accent_soft: '#393e46',
      accent_alt: '#ff2e63',
      accent_alt_fg: '#f9f7f7',
      border: 'rgba(57,62,70,0.10)',
    },
  },
  {
    id: 'turquoise-dark',
    family: 'turquoise',
    label: 'Elegant Turquoise · scuro',
    mode: 'dark',
    palette: {
      name: 'turquoise-dark',
      bg: '#393e46',
      surface: '#222831',
      surface_alt: '#393e46',
      text: '#02adb5',         // saturated turquoise as text (signature)
      text_muted: '#f5f5f5',
      accent: '#393e46',       // = bg/surface_alt → "invisible" button
      accent_soft: '#f5f5f5',  // button text (light on slate)
      accent_alt: '#ff2e63',   // saturated magenta — pops on slate
      accent_alt_fg: '#f9f7f7',
      border: 'rgba(61,79,108,0.10)',
    },
  },

  // ============ SUNRISE — sunrise gradient: warm pink/coral shifting to
  // cold teal/navy. Light = pale "dawn paper" palette; dark = "night sky
  // with a reflection of the rising sun". ============
  {
    id: 'sunrise-light',
    family: 'sunrise',
    label: 'Sunrise · chiaro',
    mode: 'light',
    palette: {
      name: 'sunrise-light',
      bg: '#fef3ee',
      surface: '#ffffff',
      surface_alt: '#f0f0f2',
      text: '#355c7d',
      text_muted: '#7a5e68',
      accent: '#4a7eb1',
      accent_alt: '#f67280',
      accent_soft: '#ffffff',
      accent_alt_fg: '#fce8e8',
      border: 'rgba(61,79,108,0.10)',
    },
  },
  {
    // SUNRISE · dark: deep blue night with warm glows (muted yellow,
    // coral, pale sage). Vibe "dawn over a cold sea".
    //  - body slate-blue (#1c2d49 → #355c7d): tile = interior elements,
    //    consistent "blue stratification"
    //  - text cream + text_muted warm yellow: breaks the bg's coldness
    //  - accent pale sage #d2e0dc as primary (light on dark, button
    //    pops with navy text #243f56 = accent_soft)
    //  - accent_alt coral #f67280 as secondary, badges with deep wine
    //    text #59384a (accent_alt_fg) for warm readability
    id: 'sunrise-dark',
    family: 'sunrise',
    label: 'Sunrise · scuro',
    mode: 'dark',
    palette: {
      name: 'sunrise-dark',
      bg: '#0d1522',
      surface: '#293647',
      surface_alt: '#355c7d',
      text: '#f5e2dc',
      text_muted: '#ffd992',
      accent: '#d2e0dc',
      accent_alt: '#f67280',
      accent_soft: '#243f56',
      accent_alt_fg: '#59384a',
      border: 'rgba(53,92,125,0.10)',
    },
  },
  {
    // NEON · dark: native dark theme, "neon on slate" look. Mirror of light.
    //  - deep slate bg, slightly lighter surface
    //  - accent (#62aaf9 soft royal) and accent_alt (#ff79c6 fluo pink):
    //    lighter/desaturated tones vs. light, since full-saturation
    //    blue/pink on a dark bg would be blinding
    //  - accent_soft = surface (#282a36): invisible chip → only the
    //    purple icon floats on the tile. Same pattern as the light variant.
    //  - accent_alt_fg = surface: dark slate on the pink, readable badge.
    id: 'neon-dark',
    family: 'nativo',
    label: 'Neon · scuro',
    mode: 'dark',
    palette: {
      name: 'neon-dark',
      bg: '#1a1c25',
      surface: '#282a36',
      surface_alt: '#353746',
      text: '#f8f8f2',
      text_muted: '#97a3c2',
      accent: '#62aaf9',
      accent_alt: '#ff79c6',
      accent_soft: '#282a36', // = surface
      accent_alt_fg: '#282a36', // = surface (dark slate sul pink)
      border: 'rgba(248,248,242,0.08)',
    },
  },
]
