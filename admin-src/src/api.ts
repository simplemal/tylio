import { useAuth } from './stores/auth'
import {
  ApiError,
  type ApiErrorData,
  type Block,
  type BlockKind,
  type BlockType,
  type BlockUpdate,
  type EmailVerificationPending,
  type EmailVerificationStatus,
  type MediaItem,
  type Settings,
  type Stats,
  type Submission,
  type Theme,
  type UpdateCheckResponse,
  type User,
} from './types'

const BASE = '/api'

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const auth = useAuth()
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(init.headers as Record<string, string>),
  }
  if (init.body && !(init.body instanceof FormData) && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json'
  }
  const method = (init.method || 'GET').toUpperCase()
  if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && auth.csrf) {
    headers['X-CSRF-Token'] = auth.csrf
  }
  const r = await fetch(BASE + path, { ...init, headers, credentials: 'same-origin' })
  if (r.status === 401) {
    auth.user = null
    auth.csrf = null
  }
  const text = await r.text()
  let json: ApiErrorData | T | null = null
  try {
    json = text ? (JSON.parse(text) as ApiErrorData | T) : null
  } catch {
    /* the body wasn't valid JSON — ignore it */
  }
  if (!r.ok) {
    const errData = (json ?? {}) as ApiErrorData
    throw new ApiError(errData.error ?? r.statusText, r.status, errData)
  }
  return json as T
}

export const api = {
  // Auth
  me: () => request<{ user: User; csrf: string }>('/auth/me'),
  login: (username: string, password: string) =>
    request<{ user: User; csrf: string; expires_at: string; requires_2fa?: boolean }>('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ username, password }),
    }),
  // Login step 2: send TOTP code (6 digits) or backup code (8 hex with backup=true).
  login2fa: (code: string, backup = false) =>
    request<{ user: User; csrf: string; expires_at: string }>('/auth/login/2fa', {
      method: 'POST',
      body: JSON.stringify({ code, backup }),
    }),
  logout: () => request<{ ok: true }>('/auth/logout', { method: 'POST' }),

  // 2FA management (logged-in user)
  twoFactorStatus: () =>
    request<{ enabled: boolean; remaining_backup_codes: number }>('/2fa/status'),
  twoFactorSetupInit: () =>
    request<{ secret: string; provisioning_uri: string }>('/2fa/setup/init', { method: 'POST' }),
  twoFactorSetupConfirm: (secret: string, code: string) =>
    request<{ ok: true; backup_codes: string[] }>('/2fa/setup/confirm', {
      method: 'POST',
      body: JSON.stringify({ secret, code }),
    }),
  twoFactorDisable: (confirm: string) =>
    request<{ ok: true }>('/2fa/disable', {
      method: 'POST',
      body: JSON.stringify({ confirm }),
    }),
  twoFactorRegenerateBackup: () =>
    request<{ backup_codes: string[] }>('/2fa/backup/regenerate', { method: 'POST' }),

  // Types
  types: () => request<{ types: BlockType[] }>('/types'),

  // Blocks
  listBlocks: () => request<{ blocks: Block[] }>('/blocks'),
  createBlock: (type: BlockKind, data?: Record<string, unknown>) =>
    request<{ block: Block }>('/blocks', {
      method: 'POST',
      body: JSON.stringify({ type, data }),
    }),
  updateBlock: (id: number, patch: BlockUpdate) =>
    request<{ block: Block }>(`/blocks/${id}`, { method: 'PUT', body: JSON.stringify(patch) }),
  deleteBlock: (id: number) => request<{ ok: true }>(`/blocks/${id}`, { method: 'DELETE' }),
  reorder: (order: number[]) =>
    request<{ ok: true }>('/blocks/reorder', { method: 'POST', body: JSON.stringify({ order }) }),
  // Apply current data+style to ALL blocks of the same type as the source
  // block. Server-side whitelist: for now only `divider`. Responds with
  // { ok, count, type }.
  applyToSameType: (id: number, data: Record<string, unknown>, style: Record<string, unknown>) =>
    request<{ ok: true; count: number; type: string }>(`/blocks/${id}/apply-to-same-type`, {
      method: 'POST',
      body: JSON.stringify({ data, style }),
    }),

  // Theme
  getTheme: () => request<{ theme: Theme }>('/theme'),
  updateTheme: (theme: Theme) =>
    request<{ theme: Theme }>('/theme', { method: 'PUT', body: JSON.stringify({ theme }) }),

  // Settings
  getSettings: () => request<{ settings: Settings }>('/settings'),
  updateSettings: (settings: Settings) =>
    request<{ settings: Settings; email_changed?: boolean }>('/settings', {
      method: 'PUT',
      body: JSON.stringify({ settings }),
    }),

  // Admin email verification flow (site.admin_email + companion settings).
  // The verify endpoint also ships the welcome mail on first ever success
  // (gated by site.welcome_sent_at IS NULL on the server). status() drives
  // the SPA tick + countdown without re-parsing every settings row.
  emailVerificationStatus: () =>
    request<EmailVerificationStatus>('/admin/email/status'),
  verifyEmailCode: (code: string) =>
    request<{ ok: true; verified_at: string }>('/admin/email/verify', {
      method: 'POST',
      body: JSON.stringify({ code }),
    }),
  requestEmailCode: () =>
    request<{ ok: boolean; pending: EmailVerificationPending | null }>(
      '/admin/email/resend-code',
      { method: 'POST' },
    ),

  // Account self-destroy: permanently deletes the tenant + all its data.
  // The server requires `confirm: 'DELETE'` as a safety check.
  deleteAccount: () =>
    request<{ ok: true; redirect_url: string }>('/account/delete', {
      method: 'POST',
      body: JSON.stringify({ confirm: 'DELETE' }),
    }),

  // Media
  listMedia: () => request<{ media: MediaItem[] }>('/media'),
  uploadMedia: (file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    return request<{ media: MediaItem }>('/media', { method: 'POST', body: fd })
  },
  deleteMedia: (id: number) => request<{ ok: true }>(`/media/${id}`, { method: 'DELETE' }),

  // Favicon
  uploadFavicon: (file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    return request<{ ok: true; version: string; sizes: Record<string, string> }>('/favicon', {
      method: 'POST',
      body: fd,
    })
  },
  faviconFromMedia: (mediaId: number) =>
    request<{ ok: true; version: string; sizes: Record<string, string> }>('/favicon', {
      method: 'POST',
      body: JSON.stringify({ media_id: mediaId }),
    }),
  deleteFavicon: () => request<{ ok: true }>('/favicon', { method: 'DELETE' }),

  // Stats
  stats: () => request<Stats>('/stats'),

  // Update check (Settings → "Aggiornamenti tylio" card). Compares local
  // version with the latest GitHub release. 24h cache on the server;
  // pass `force=true` to bust it (the "Verifica ora" link).
  updateCheck: (force = false) =>
    request<UpdateCheckResponse>(
      '/admin/update-check' + (force ? '?force=1' : ''),
    ),

  // Submissions
  listSubmissions: () => request<{ submissions: Submission[] }>('/submissions'),
  unreadSubmissionsCount: () => request<{ count: number }>('/submissions/unread-count'),
  markSubmissionRead: (id: number) =>
    request<{ ok: true }>(`/submissions/${id}/read`, { method: 'POST' }),
  markAllSubmissionsRead: () =>
    request<{ ok: true }>('/submissions/mark-all-read', { method: 'POST' }),
  deleteSubmission: (id: number) =>
    request<{ ok: true }>(`/submissions/${id}`, { method: 'DELETE' }),
  deleteAllSubmissions: () => request<{ ok: true }>('/submissions', { method: 'DELETE' }),
}
