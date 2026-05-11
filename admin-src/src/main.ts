import { createApp } from 'vue'
import { createPinia } from 'pinia'
import 'iconify-icon'
import './style.css'
import App from './App.vue'
import { router } from './router'
import { bootstrapTheme } from './theme'
import { i18n } from './i18n'

// Apply the user's palette right away (sync from localStorage, async from /api/theme/public)
bootstrapTheme()

// Reflect the chosen locale on <html lang> so screen readers / right-click
// translate pick it up. i18n itself was initialized from navigator.language
// (with a localStorage override) — see `detectInitialLocale` in i18n.ts.
document.documentElement.setAttribute('lang', i18n.global.locale.value)

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.use(i18n)
app.mount('#app')
