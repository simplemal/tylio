/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,ts,tsx}'],
  theme: {
    extend: {
      fontFamily: {
        display: ['Fraunces', 'serif'],
        body: ['Inter', 'system-ui', 'sans-serif'],
      },
      colors: {
        // L'admin EREDITA dinamicamente la palette del sito pubblico via CSS vars
        // (impostate al boot da src/theme.ts in base a /api/theme/public).
        // Tutte le scale ink/accent puntano a una variabile RGB per supportare
        // l'opacità Tailwind (es. bg-ink-900/80).
        ink: {
          950: 'rgb(var(--ink-950-rgb) / <alpha-value>)',
          900: 'rgb(var(--ink-900-rgb) / <alpha-value>)',
          800: 'rgb(var(--ink-800-rgb) / <alpha-value>)',
          700: 'rgb(var(--ink-700-rgb) / <alpha-value>)',
          500: 'rgb(var(--ink-500-rgb) / <alpha-value>)',
          300: 'rgb(var(--ink-300-rgb) / <alpha-value>)',
          100: 'rgb(var(--ink-100-rgb) / <alpha-value>)',
        },
        accent: {
          DEFAULT: 'rgb(var(--accent-rgb) / <alpha-value>)',
          alt:     'rgb(var(--accent-alt-rgb) / <alpha-value>)',
          deep:    'rgb(var(--accent-deep-rgb) / <alpha-value>)',
          fg:      'rgb(var(--accent-fg-rgb) / <alpha-value>)',
          // accent-soft = "Contrasto su accento principale" (user-controllabile)
          // accent-alt-fg = "Contrasto su accento secondario" (user-controllabile)
          soft:    'rgb(var(--accent-soft-rgb) / <alpha-value>)',
          'alt-fg':'rgb(var(--accent-alt-fg-rgb) / <alpha-value>)',
        },
        // FISSI — sono i colori del marchio "vetrata", non del tema utente.
        glass: {
          cobalt: '#2e54a8',
          ruby:   '#c33547',
          emerald:'#2d8559',
          amber:  '#d4a13c',
          lead:   '#0b0e12',
        },
      },
      borderRadius: {
        tile: '18px',
      },
      boxShadow: {
        tile: '0 30px 60px -40px rgba(0,0,0,.6), 0 1px 0 rgba(255,255,255,.04) inset',
      },
    },
  },
  plugins: [],
}
