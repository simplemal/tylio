// =====================================================================
// Dynamic schema (registry) — describes how the admin renders the form
// for a block type. Comes from `GET /api/types`.
// =====================================================================

export interface FieldOption {
  value: string
  label: string
}

export interface FieldDef {
  key: string
  label: string
  type:
    | 'text'
    | 'textarea'
    | 'markdown'
    | 'url'
    | 'email'
    | 'image'
    | 'avatar'
    | 'color'
    | 'number'
    | 'toggle'
    | 'select'
    | 'repeat'
    | 'icon'
    | 'range'
  help?: string
  options?: FieldOption[]
  of?: FieldDef[]
  default?: unknown
  placeholder?: string
  required?: boolean
  /**
   * Autocomplete for text fields inside a repeat: 'siblings' collects
   * values already used by other items in the same repeat (for that
   * same key) and offers them via an HTML5 datalist. Typical for a
   * free-form "category" you want to keep consistent across items.
   */
  autocomplete_from?: 'siblings'
}

export interface BlockType {
  id: BlockKind
  label: string
  icon: string
  category: string
  description: string
  span: 'full' | 'half'
  fields: FieldDef[]
}

// =====================================================================
// Block — data model for blocks saved in the DB.
// Discriminated union on `type` → each variant types its own `data`.
// =====================================================================

export type BlockKind =
  | 'hero'
  | 'links'
  | 'apps'
  | 'bio'
  | 'products'
  | 'quote'
  | 'stats'
  | 'cta'
  | 'faq'
  | 'timeline'
  | 'social'
  | 'gallery'
  | 'embed'
  | 'youtube'
  | 'podcast'
  | 'contact'
  | 'skills'
  | 'divider'
  | 'footer'

interface BaseBlock {
  id: number
  position: number
  enabled: boolean
  style: Record<string, unknown>
  created_at: string
  updated_at: string
}

// --- Per-block data shapes -----------------------------------------------

export interface HeroData {
  avatar?: string
  title?: string
  /**
   * Optional graphical title: URL of an image (e.g. a logo) that
   * visually replaces the `title` text. When present, `title` is still
   * used as the image's alt/title for accessibility and SEO.
   */
  title_image?: string
  subtitle?: string
}

export interface LinkItem {
  label?: string
  url?: string
  icon?: string
  badge?: string
  description?: string
}
export interface LinksData {
  title?: string
  items?: LinkItem[]
}

export interface AppItem {
  name?: string
  tagline?: string
  description?: string
  icon_image?: string
  cover_image?: string
  accent?: string
  url?: string
  app_store?: string
  play_store?: string
  tag?: string
}
export interface AppsData {
  title?: string
  subtitle?: string
  columns?: '1' | '2' | '3'
  items?: AppItem[]
}

export interface BioData {
  title?: string
  body?: string
}

export interface ProductItem {
  image?: string
  name?: string
  description?: string
  price?: string
  url?: string
  discount_code?: string
  discount_label?: string
}
export interface ProductsData {
  title?: string
  subtitle?: string
  columns?: '1' | '2' | '3'
  cta_label?: string
  items?: ProductItem[]
}

export type SocialPlatform =
  | 'twitter'
  | 'instagram'
  | 'youtube'
  | 'tiktok'
  | 'threads'
  | 'bluesky'
  | 'mastodon'
  | 'facebook'
  | 'linkedin'
  | 'pinterest'
  | 'snapchat'
  | 'github'
  | 'gitlab'
  | 'codepen'
  | 'stackoverflow'
  | 'dribbble'
  | 'behance'
  | 'figma'
  | 'makerworld'
  | 'printables'
  | 'thingiverse'
  | 'cults3d'
  | 'thangs'
  | 'sketchfab'
  | 'cgtrader'
  | 'twitch'
  | 'vimeo'
  | 'spotify'
  | 'soundcloud'
  | 'applemusic'
  | 'bandcamp'
  | 'medium'
  | 'substack'
  | 'devto'
  | 'hashnode'
  | 'discord'
  | 'telegram'
  | 'whatsapp'
  | 'reddit'
  | 'matrix'
  | 'patreon'
  | 'kofi'
  | 'buymeacoffee'
  | 'liberapay'
  | 'website'
  | 'email'
  | 'phone'
  | 'rss'
  | 'other'
export interface SocialItem {
  platform?: SocialPlatform
  url?: string
  label?: string
}
export interface SocialData {
  title?: string
  display?: 'icon_only' | 'icon_platform' | 'icon_account' | 'icon_full'
  items?: SocialItem[]
}

export interface GalleryItem {
  image?: string
  alt?: string
  caption?: string
  link?: string
}
export interface GalleryData {
  title?: string
  layout?: 'mosaic' | 'grid' | 'carousel'
  items?: GalleryItem[]
}

export interface EmbedData {
  title?: string
  provider?: 'youtube' | 'vimeo' | 'spotify' | 'soundcloud' | 'iframe'
  url?: string
  aspect?: '16:9' | '4:3' | '1:1' | '9:16'
}

export interface YouTubeData {
  title?: string
  subtitle?: string
  source_url?: string
  mode?: 'latest' | 'playlist'
  aspect?: '16:9' | '9:16' | '4:3' | '1:1'
}

export interface PodcastData {
  title?: string
  subtitle?: string
  show_name?: string
  apple_url?: string
  spotify_url?: string
  site_url?: string
  description?: string
  preferred_player?: 'auto' | 'spotify' | 'apple' | 'none'
  /** @deprecated legacy single-URL field, replaced by the 3 specific URLs */
  url?: string
  /** @deprecated `kind` is now auto-derived from the URL — field ignored */
  mode?: 'show' | 'episode'
}

export interface ContactField {
  key?: string
  label?: string
  type?: 'text' | 'email' | 'tel' | 'textarea'
  required?: boolean
}
export interface ContactData {
  title?: string
  subtitle?: string
  success_message?: string
  fields?: ContactField[]
}

export interface QuoteData {
  title?: string
  text?: string
  author?: string
  role?: string
  avatar?: string
  style?: 'card' | 'minimal' | 'highlight'
  text_size?: 'sm' | 'md' | 'lg'
  line_height?: 'compact' | 'normal' | 'relaxed'
}

export interface StatItem {
  value?: string
  label?: string
  icon?: string
}
export interface StatsData {
  title?: string
  items?: StatItem[]
}

export interface CtaData {
  title?: string
  subtitle?: string
  button_label?: string
  button_url?: string
  icon?: string
  style?: 'gradient' | 'solid' | 'outline' | 'minimal'
}

export interface FaqItem {
  question?: string
  answer?: string
}
export interface FaqData {
  title?: string
  items?: FaqItem[]
}

export interface TimelineItem {
  date?: string
  title?: string
  description?: string
  icon?: string
  highlight?: boolean
}
export interface TimelineData {
  title?: string
  items?: TimelineItem[]
}

export interface SkillItem {
  name?: string
  level?: string
  category?: string
  icon?: string
}
export interface SkillsData {
  title?: string
  subtitle?: string
  items?: SkillItem[]
}

export interface DividerData {
  style?: 'line' | 'tessera' | 'space'
}

export interface FooterLink {
  label?: string
  url?: string
}
export interface FooterData {
  text?: string
  show_powered_by?: boolean
  links?: FooterLink[]
}

// --- Discriminated union -------------------------------------------------

export type Block =
  | (BaseBlock & { type: 'hero'; data: HeroData })
  | (BaseBlock & { type: 'links'; data: LinksData })
  | (BaseBlock & { type: 'apps'; data: AppsData })
  | (BaseBlock & { type: 'bio'; data: BioData })
  | (BaseBlock & { type: 'products'; data: ProductsData })
  | (BaseBlock & { type: 'quote'; data: QuoteData })
  | (BaseBlock & { type: 'stats'; data: StatsData })
  | (BaseBlock & { type: 'cta'; data: CtaData })
  | (BaseBlock & { type: 'faq'; data: FaqData })
  | (BaseBlock & { type: 'timeline'; data: TimelineData })
  | (BaseBlock & { type: 'social'; data: SocialData })
  | (BaseBlock & { type: 'gallery'; data: GalleryData })
  | (BaseBlock & { type: 'embed'; data: EmbedData })
  | (BaseBlock & { type: 'youtube'; data: YouTubeData })
  | (BaseBlock & { type: 'podcast'; data: PodcastData })
  | (BaseBlock & { type: 'contact'; data: ContactData })
  | (BaseBlock & { type: 'skills'; data: SkillsData })
  | (BaseBlock & { type: 'divider'; data: DividerData })
  | (BaseBlock & { type: 'footer'; data: FooterData })

/** Payload accepted by `PUT /api/blocks/:id`. Every property is optional. */
export interface BlockUpdate {
  data?: Block['data']
  style?: Record<string, unknown>
  enabled?: boolean
  position?: number
}

/** Mapping `type literal` → shape of `data`. Useful for generics in consumers. */
export interface BlockDataMap {
  hero: HeroData
  links: LinksData
  apps: AppsData
  bio: BioData
  products: ProductsData
  quote: QuoteData
  stats: StatsData
  cta: CtaData
  faq: FaqData
  timeline: TimelineData
  social: SocialData
  gallery: GalleryData
  embed: EmbedData
  youtube: YouTubeData
  podcast: PodcastData
  contact: ContactData
  skills: SkillsData
  divider: DividerData
  footer: FooterData
}

/**
 * Type-guard: narrows Block to the variant whose `type` matches. Useful when
 * iterating over a list of Blocks and you want to access type-specific fields.
 *
 *   if (isBlockOfType(block, 'apps')) { block.data.items?.forEach(...) }
 */
export function isBlockOfType<K extends BlockKind>(
  b: Block,
  kind: K,
): b is Extract<Block, { type: K }> {
  return b.type === kind
}

// =====================================================================
// User
// =====================================================================

export interface User {
  id: number
  username: string
}

// =====================================================================
// Theme
// =====================================================================

export interface Theme {
  palette: Record<string, string>
  font: {
    heading: string
    body: string
  }
  tile: {
    radius: number
    gap: number
    border: number
    style?: 'solid' | 'transparent' | 'glass'
    opacity?: number // 0..1, used when style = transparent
    shadow?: string
    tessellate?: number
    /**
     * Mobile geometry (≤ ~780px). Default 'desktop' = historical behavior
     * (tiles visible like on desktop). 'minimal' hides the tile outlines
     * on mobile to maximize content space — useful on narrow phones where
     * card borders eat usable pixels.
     */
    mobile_spacing?: 'desktop' | 'minimal'
  }
  background: {
    pattern: 'none' | 'dots' | 'grid' | 'lines-thin' | 'lines-thick' | 'mosaic' | 'image' | string
    intensity: number
    image?: string
  }
  mode: 'auto' | 'dark' | 'light'
}

// =====================================================================
// Media
// =====================================================================

export interface MediaItem {
  id: number
  filename: string
  original_name: string
  mime: string
  size: number
  width?: number | null
  height?: number | null
  url: string
  created_at: string
}

// =====================================================================
// Submissions / Stats / Settings — typed API responses
// =====================================================================

export interface Submission {
  id: number
  block_id: number | null
  type: string
  payload: Record<string, string> // deserialized payload (the server runs json_decode)
  ip: string | null
  created_at: string
  read_at: string | null
  /**
   * Status of the email forwarding to the owner's notify_email:
   *   - 'sent'         OK
   *   - 'no_dsn'       MAIL_DSN not configured on the server
   *   - 'no_recipient' notify_email empty in the tenant's Settings
   *   - 'error'        SMTP exception (see mail_error for the message)
   *   - null           attempt not recorded (row inserted before the migration)
   */
  mail_status:
    | 'sent'
    | 'no_dsn'
    | 'no_recipient'
    | 'unverified_recipient'
    | 'error'
    | null
  mail_error: string | null
}

export interface StatsTotals {
  total_visits: number
  today_visits: number
  unique_days: number
  submissions_total: number
  submissions_unread: number
}
export interface StatsByDay {
  day: string
  visits: number
}
export interface StatsByBlock {
  id: number
  type: BlockKind
  clicks: number
}
export interface Stats {
  totals: StatsTotals
  by_day: StatsByDay[]
  by_block: StatsByBlock[]
}

/**
 * Settings have simple values (strings for text/URL, boolean for toggles).
 * If structured shapes are needed in the future, widen the type here.
 */
export type SettingValue = string | boolean
export type Settings = Record<string, SettingValue>

// =====================================================================
// Update check (GET /api/admin/update-check)
// =====================================================================

/**
 * Successful payload from GET /api/admin/update-check.
 *
 *  - `current`: locally installed version (e.g. `v0.1.0`, `v0.1.0-3-gabc`,
 *    `build-2026-05-14-160000`, `dev`).
 *  - `latest`: most recent GitHub release tag, or `null` when the API
 *    call failed (network down, rate limit, no releases yet).
 *  - `is_outdated`: true only if both versions are comparable and the
 *    local one is strictly older.
 *  - `last_checked`: ISO-8601 of the last successful poll.
 *  - `changelog_html`: pre-sanitized HTML of the release body (Markdown
 *    rendered server-side via `Util\Markdown`), safe to v-html.
 *  - `release_url`: GitHub release page link.
 *  - `release_name`: human title of the release.
 */
export interface UpdateCheckOk {
  current: string
  latest: string | null
  is_outdated: boolean
  last_checked: string
  changelog_html: string
  release_url: string
  release_name: string
}

/**
 * Operator-disabled signal returned by the SaaS overlay
 * (`TenantUpdateController`). HTTP 200 + `{ disabled: true }` so the
 * SPA can hide the card without surfacing an error.
 */
export interface UpdateCheckDisabled {
  disabled: true
  reason?: string
}

export type UpdateCheckResponse = UpdateCheckOk | UpdateCheckDisabled

// =====================================================================
// Email verification (`site.admin_email`)
// =====================================================================

/**
 * Live state of the pending verification code (if any) for the
 * currently-configured `site.admin_email`. `cooldown_remaining` drives
 * the SPA's "Resend in 12:34" countdown.
 */
export interface EmailVerificationPending {
  email: string
  expires_at: string
  attempts: number
  can_resend_at: string
  cooldown_remaining: number
}

export interface EmailVerificationStatus {
  email: string
  verified_at: string | null
  pending: EmailVerificationPending | null
}

// =====================================================================
// API error
// =====================================================================

export interface ApiErrorData {
  error?: string
  message?: string
  retry_after?: number
  [key: string]: unknown
}

export class ApiError extends Error {
  status: number
  data: ApiErrorData
  constructor(message: string, status: number, data: ApiErrorData) {
    super(message)
    this.status = status
    this.data = data
    this.name = 'ApiError'
  }
}
