import { defineStore } from 'pinia'
import { api } from '../api'

/**
 * Global state for the admin shell's top-of-page banners and the
 * brand pill under the tylio.app logo. Refreshed on demand:
 *   - on AppShell mount (after login)
 *   - when the tab returns from background (visibility change)
 *   - after the user saves Settings (cross-component update)
 *
 * Holds three concerns that all sit in the shell, NOT inside a view:
 *   1. `maintenance` — drives the amber "Site under maintenance" banner.
 *   2. `title` — drives the OSS-mode brand pill (the SaaS overlay shows
 *      the tenant slug instead, derived from the hostname).
 *   3. `adminEmail` + `adminEmailVerifiedAt` — drive the persistent
 *      "set an admin email" / "verify your email" banner. Both come
 *      from the same `/admin/email/status` endpoint that Settings.vue
 *      already uses to render its inline tick.
 *
 * Implemented as a Pinia store rather than a per-view fetch so the
 * banners in AppShell stay reactive when Settings.vue mutates the
 * underlying settings, without prop-drilling or window events.
 */
export const useSite = defineStore('site', {
  state: () => ({
    maintenance: false,
    title: '',
    mailHost: '',
    mailUseCustomSmtp: false,
    mailFromEnvOnly: false, // true when env MAIL_DSN is set but no settings.mail.host
    isSaas: false,
    adminEmail: '',
    adminEmailVerifiedAt: '' as string | null | '',
    loaded: false,
    emailLoaded: false,
  }),
  getters: {
    /**
     * SMTP "needs setup" banner. Le regole sono diverse per OSS e SaaS:
     *
     *   - **OSS** (self-host standalone): l'admin DEVE configurare SMTP
     *     altrimenti nessuna email parte. Banner visibile se `mail.host`
     *     è vuoto.
     *   - **SaaS** (tenant `*.tylio.app`): il Mailer della piattaforma
     *     copre tutto di default. L'admin può abilitare un toggle
     *     "Usa il tuo server" (`mail.use_custom_smtp`) per overridarlo;
     *     SOLO in quel caso un `mail.host` vuoto è un problema, quindi
     *     SOLO in quel caso mostriamo il banner.
     */
    needsSmtpConfig(): boolean {
      if (!this.loaded) return false
      if (this.mailHost !== '') return false
      if (this.mailFromEnvOnly) return false
      // SaaS senza opt-in al custom mailer: nessun warning, è il
      // comportamento di default attendibile.
      if (this.isSaas && !this.mailUseCustomSmtp) return false
      return true
    },
    /**
     * The admin still needs to set an email. `''` (the seed value when
     * the migration ran on an existing install) is treated as "missing".
     */
    needsEmailSet(): boolean {
      return this.emailLoaded && this.adminEmail === ''
    },
    /**
     * Email is set but the user never pasted the verification code.
     * Falsy values from the API (`null` or empty string) both count
     * as "not verified".
     */
    needsEmailVerify(): boolean {
      return this.emailLoaded
        && this.adminEmail !== ''
        && (this.adminEmailVerifiedAt === null || this.adminEmailVerifiedAt === '')
    },
    /**
     * Priority resolver for the shell-wide warning banner. Returns the
     * first warning to surface — DO NOT stack multiple banners. Order
     * (Maurizio 2026-05-16):
     *   1. SMTP not configured → without this, NO email can be sent,
     *      so every downstream warning would be unactionable anyway.
     *   2. Admin email not set → password reset / 2FA fallback has no
     *      target.
     *   3. Admin email set but not verified → can still receive mail,
     *      just unverified.
     * Returns the empty string when no banner should show.
     */
    activeBanner(): '' | 'smtp' | 'email-missing' | 'email-unverified' {
      if (this.needsSmtpConfig) return 'smtp'
      if (this.needsEmailSet) return 'email-missing'
      if (this.needsEmailVerify) return 'email-unverified'
      return ''
    },
  },
  actions: {
    async refresh() {
      try {
        const r = await api.getSettings()
        this.maintenance = Boolean(r.settings['site.maintenance'])
        const t = r.settings['site.title']
        this.title = typeof t === 'string' ? t : ''
        const mh = r.settings['mail.host']
        this.mailHost = typeof mh === 'string' ? mh : ''
        this.mailUseCustomSmtp = Boolean(r.settings['mail.use_custom_smtp'])
        // Hostname-based SaaS detection — stesso pattern di AppShell.
        const host = (typeof window !== 'undefined' ? window.location.hostname : '').toLowerCase()
        this.isSaas = /\.tylio\.app$/.test(host)
        this.loaded = true
      } catch {
        // silent: banners aren't critical
      }
      // Email status comes from a separate endpoint (it joins
      // settings with the email_verifications table).
      try {
        const e = await api.emailVerificationStatus()
        this.adminEmail = e.email
        this.adminEmailVerifiedAt = e.verified_at ?? ''
        this.emailLoaded = true
      } catch {
        // silent
      }
    },
    /**
     * Cross-component shortcut for views that already have the latest
     * settings object in memory (e.g. Settings.vue after save).
     */
    setFromSettings(settings: Record<string, unknown>) {
      this.maintenance = Boolean(settings['site.maintenance'])
      const t = settings['site.title']
      this.title = typeof t === 'string' ? t : ''
      const mh = settings['mail.host']
      this.mailHost = typeof mh === 'string' ? mh : ''
      this.mailUseCustomSmtp = Boolean(settings['mail.use_custom_smtp'])
      this.loaded = true
    },
    setEmailStatus(email: string, verifiedAt: string | null) {
      this.adminEmail = email
      this.adminEmailVerifiedAt = verifiedAt ?? ''
      this.emailLoaded = true
    },
    clear() {
      this.maintenance = false
      this.title = ''
      this.mailHost = ''
      this.mailUseCustomSmtp = false
      this.mailFromEnvOnly = false
      this.isSaas = false
      this.adminEmail = ''
      this.adminEmailVerifiedAt = ''
      this.loaded = false
      this.emailLoaded = false
    },
  },
})
