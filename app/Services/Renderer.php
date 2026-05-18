<?php
declare(strict_types=1);

namespace Tylio\Services;

use Tylio\Config;
use Tylio\Util\Markdown;

/**
 * Page renderer. Loads theme, settings, and blocks, then assembles the public
 * HTML by piping each block through its template under `Templates/blocks/`.
 *
 * **Extendable by design.** This class is non-`final` and exposes its
 * dependencies as `protected`. Sub-classes can override individual methods
 * (load*, blockHasContent, renderBlock, …) without re-implementing the full
 * pipeline. Public API is stable across minor versions.
 */
class Renderer
{
    public function __construct(
        protected DB $db,
        protected BlockRegistry $registry,
        protected Config $config,
        protected ?I18n $i18n = null,
    ) {
        // If no I18n was injected (back-compat: some callers may still
        // construct Renderer directly), fall back to a default instance.
        // The locale on it stays at the configured default; the renderer
        // will call `applySiteLocale` later from `renderPage` once it has
        // loaded the site settings + request headers.
        $this->i18n ??= new I18n($this->config);
    }

    /**
     * Translation helper exposed to block templates (`$renderer->t('key')`).
     * Goes through the injected `I18n` service; missing keys return the key
     * itself so the issue surfaces in the DOM.
     *
     * @param array<string, scalar|\Stringable|null> $vars
     */
    public function t(string $key, array $vars = []): string
    {
        return $this->i18n->t($key, $vars);
    }

    public function i18n(): I18n
    {
        return $this->i18n;
    }

    /**
     * Pick the active public-site locale from a `settings.site.locale`
     * value with a fallback to the `Accept-Language` header negotiation.
     * Called from `renderPage` once the settings array is loaded.
     */
    public function applySiteLocale(array $settings, string $acceptLanguage = ''): void
    {
        $siteLocale = (string)$this->settingsValue($settings, 'site.locale', '');
        if ($siteLocale !== '') {
            $this->i18n->setLocale($siteLocale);
            return;
        }
        if ($acceptLanguage !== '') {
            $this->i18n->setLocale($this->i18n->negotiate($acceptLanguage));
        }
    }

    public function loadTheme(): array
    {
        $row = $this->db->one('SELECT data FROM theme WHERE id = 1');
        return $row ? (json_decode($row['data'], true) ?: []) : [];
    }

    public function loadSettings(): array
    {
        $rows = $this->db->all('SELECT key, value FROM settings');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = json_decode($r['value'], true);
        }
        return $out;
    }

    public function loadBlocks(bool $includeDisabled = false, ?int $onlyId = null): array
    {
        $sql = 'SELECT * FROM blocks';
        $params = [];
        $where = [];
        if (!$includeDisabled) $where[] = 'enabled = 1';
        if ($onlyId !== null)  { $where[] = 'id = ?'; $params[] = $onlyId; }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY position ASC, id ASC';
        $rows = $this->db->all($sql, $params);
        foreach ($rows as &$r) {
            $r['data'] = json_decode($r['data'] ?? '{}', true) ?: [];
            $r['style'] = json_decode($r['style'] ?? '{}', true) ?: [];
            $r['enabled'] = (bool)$r['enabled'];
            // `parent_id` may be NULL or absent on rows from a DB that
            // predates migration 0008 — coerce to int|null for downstream code.
            $r['parent_id'] = isset($r['parent_id']) ? (int)$r['parent_id'] : null;
            if ($r['parent_id'] === 0) $r['parent_id'] = null;
        }
        return $rows;
    }

    /**
     * Plan the public mosaic layout. Walks the flat block list and
     * decides what each block's CSS grid placement should be on
     * desktop (2-column grid). On mobile the inline custom properties
     * are ignored by `public.css` and items flow linearly.
     *
     * Output: a flat list of "render units" in DOM order (= mobile
     * linear order). Each unit knows the block to render and a CSS
     * fragment to inject as inline style. Groups themselves never
     * become a unit — only their children, each pinned to a row inside
     * the group's column.
     *
     * Algorithm:
     *   1. Split rows into top-level (parent_id NULL) and children-by-parent.
     *   2. Build a sequence of "cells" from the top-level row:
     *        - tile        → 1 col × 1 row
     *        - tile full   → 2 col × 1 row
     *        - group of N  → 1 col × N rows
     *   3. Walk the cells, pairing adjacent 1-col cells side-by-side.
     *      A paired row's height = max(left.rows, right.rows). The
     *      shorter side's last item stretches to fill the leftover
     *      rows so neither column has dead space.
     *   4. Emit each render unit with grid coords as CSS variables.
     *
     * @param list<array<string,mixed>> $blocks Flat list, output of `loadBlocks()`.
     * @return list<array{block: array<string,mixed>, grid: string}>
     */
    public function planLayout(array $blocks): array
    {
        // Step 1: tree split.
        $topLevel = [];
        $childrenByParent = [];
        foreach ($blocks as $b) {
            $pid = $b['parent_id'] ?? null;
            if ($pid === null) {
                $topLevel[] = $b;
            } else {
                $childrenByParent[$pid][] = $b;
            }
        }

        // Step 2: build cells (preserving position order from loadBlocks).
        $cells = [];
        foreach ($topLevel as $b) {
            $type = (string)($b['type'] ?? '');
            if ($type === 'group') {
                $kids = $childrenByParent[(int)$b['id']] ?? [];
                if (empty($kids)) continue; // empty groups are invisible
                $cells[] = [
                    'kind' => 'group',
                    'block' => $b,
                    'children' => $kids,
                    'cols' => 1,
                    'rows' => count($kids),
                ];
            } else {
                $span = $this->blockSpan($b); // 1 (half) or 2 (full)
                $cells[] = [
                    'kind' => 'tile',
                    'block' => $b,
                    'cols' => $span,
                    'rows' => 1,
                ];
            }
        }

        // Step 3: walk cells, pair halves, compute row indices.
        $units = [];
        $rowStart = 1;
        $i = 0;
        $n = count($cells);
        while ($i < $n) {
            $cell = $cells[$i];
            if ($cell['cols'] === 2) {
                foreach ($this->expandCell($cell, $rowStart, $cell['rows'], 1, 2) as $u) {
                    $units[] = $u;
                }
                $rowStart += $cell['rows'];
                $i++;
                continue;
            }
            // 1-col cell: pair with next 1-col cell if present.
            if ($i + 1 < $n && $cells[$i + 1]['cols'] === 1) {
                $left = $cell;
                $right = $cells[$i + 1];
                $rowHeight = max($left['rows'], $right['rows']);
                foreach ($this->expandCell($left,  $rowStart, $rowHeight, 1, 1) as $u) $units[] = $u;
                foreach ($this->expandCell($right, $rowStart, $rowHeight, 2, 1) as $u) $units[] = $u;
                $rowStart += $rowHeight;
                $i += 2;
            } else {
                // Orphan half: keeps a half-width column, leaves the
                // other column empty (matches the design decision of
                // 2026-05-10 in `markOrphanHalves`).
                foreach ($this->expandCell($cell, $rowStart, $cell['rows'], 1, 1) as $u) {
                    $units[] = $u;
                }
                $rowStart += $cell['rows'];
                $i++;
            }
        }
        return $units;
    }

    /**
     * Turn one cell into 1+ render units with grid coords. Tiles emit
     * a single unit spanning the cell's row range. Groups emit one unit
     * per child, each pinned to a single row inside the column; if the
     * group is paired with a taller cell, the LAST child absorbs the
     * leftover row span so the column has no dead vertical space.
     *
     * The grid coords are stored as a CSS custom property `--ga` (a
     * `grid-area` shorthand) so the public stylesheet can override them
     * with `grid-area: auto` on mobile via a media query.
     *
     * @param array{kind:string,block:array,cols:int,rows:int,children?:list<array>} $cell
     * @return list<array{block: array<string,mixed>, grid: string}>
     */
    private function expandCell(array $cell, int $rowStart, int $rowSpan, int $colStart, int $colSpan): array
    {
        if ($cell['kind'] === 'tile') {
            $grid = sprintf(
                '--ga: %d / %d / %d / %d',
                $rowStart, $colStart,
                $rowStart + $rowSpan, $colStart + $colSpan
            );
            return [['block' => $cell['block'], 'grid' => $grid]];
        }
        // Group: stack children. The last child gets any extra rows so
        // the column is fully covered when the sibling on the other
        // column is taller.
        $kids = $cell['children'] ?? [];
        $kidsCount = count($kids);
        $extra = max(0, $rowSpan - $kidsCount);
        $row = $rowStart;
        $units = [];
        foreach ($kids as $idx => $kid) {
            $kidRows = 1 + ($idx === $kidsCount - 1 ? $extra : 0);
            $grid = sprintf(
                '--ga: %d / %d / %d / %d',
                $row, $colStart,
                $row + $kidRows, $colStart + $colSpan
            );
            $units[] = ['block' => $kid, 'grid' => $grid];
            $row += $kidRows;
        }
        return $units;
    }

    /**
     * @param list<string> $excludeTypes block types to SKIP during render
     *   (used by StaticExporter to omit blocks that depend on the server,
     *   e.g. `contact` which posts to /submit/{id}).
     * @param bool $adminMaintenanceBanner inject the "site offline to
     *   visitors" banner at the top of the public layout. Set true ONLY
     *   when the admin is previewing the live site during maintenance
     *   (PageController.home does the auth + maintenance flag check).
     */
    public function renderPage(bool $includeDisabled = false, ?int $onlyId = null, array $excludeTypes = [], bool $adminMaintenanceBanner = false): string
    {
        $theme = $this->loadTheme();
        $settings = $this->loadSettings();
        $blocks = $this->loadBlocks($includeDisabled || $onlyId !== null, $onlyId);

        // Lock in the locale for this render: site.locale wins; otherwise
        // fall back to the Accept-Language header sent by the visitor.
        $accept = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        $this->applySiteLocale($settings, $accept);

        // Filter out empty blocks (e.g. empty gallery) except when rendering
        // a single block (edit mode) — there we want to see it anyway.
        if ($onlyId === null) {
            $blocks = array_values(array_filter($blocks, fn($b) => $this->blockHasContent($b)));
            // Mark "half" tiles that are orphans (not followed by another half)
            // so they can stretch to 2 cols on desktop and not leave a hole.
            $blocks = $this->markOrphanHalves($blocks);
        }
        // Exclude specific types (after blockHasContent so they're not
        // "promoted" needlessly). Typical use: ['contact'] for static export.
        if (!empty($excludeTypes)) {
            $excl = array_flip(array_map('strval', $excludeTypes));
            $blocks = array_values(array_filter(
                $blocks,
                fn($b) => !isset($excl[(string)($b['type'] ?? '')]),
            ));
        }

        // Layout plan: full page render → planLayout (handles groups +
        // grid coords). Single-block edit preview → just one unit, no
        // grid placement (the preview iframe is a 1-col canvas anyway).
        if ($onlyId === null) {
            $units = $this->planLayout($blocks);
        } else {
            $units = array_map(
                fn($b) => ['block' => $b, 'grid' => ''],
                $blocks
            );
        }

        ob_start();
        $renderer = $this;
        $appUrl = $this->config->appUrl();
        require $this->config->path('app/Templates/layout.php');
        return (string)ob_get_clean();
    }

    /**
     * Render the public maintenance page. Self-contained — uses the
     * theme palette + fonts so the offline page still feels like the
     * user's site. Called by `PageController::home()` when
     * `settings['site.maintenance']` is truthy AND the visitor is not
     * the authenticated admin.
     *
     * Returns the full HTML as a string; the caller is responsible for
     * setting the HTTP status (503 + Retry-After is the convention).
     */
    public function renderMaintenance(string $acceptLanguage = ''): string
    {
        $theme = $this->loadTheme();
        $settings = $this->loadSettings();
        // Re-use the same locale resolution as renderPage so the
        // hardcoded fallback strings in the template (it/en) pick the
        // right language even on a fresh install with no site.locale set.
        $this->applySiteLocale($settings, $acceptLanguage);
        $locale = $this->i18n->currentLocale();
        $title = (string)$this->settingsValue($settings, 'site.title', 'tylio');
        $message = (string)$this->settingsValue($settings, 'site.maintenance_message', '');

        ob_start();
        $renderer = $this;
        require $this->maintenanceTemplatePath();
        return (string)ob_get_clean();
    }

    /**
     * Path to the maintenance.php template, exposed as a protected hook
     * so sub-classes (the multi-tenant overlay's `TenantRenderer`) can
     * reuse the same template via the parent's `__DIR__` regardless of
     * where the OSS package is installed (rootPath in OSS, vendor tree
     * in SaaS). Override only to ship a different template entirely.
     */
    protected function maintenanceTemplatePath(): string
    {
        return __DIR__ . '/../Templates/maintenance.php';
    }

    public function renderNotFound(string $path, string $acceptLanguage = ''): string
    {
        $theme = $this->loadTheme();
        $settings = $this->loadSettings();
        $this->applySiteLocale($settings, $acceptLanguage);
        $locale = $this->i18n->currentLocale();
        $title = (string)$this->settingsValue($settings, 'site.title', 'tylio');

        ob_start();
        $renderer = $this;
        require $this->notFoundTemplatePath();
        return (string)ob_get_clean();
    }

    protected function notFoundTemplatePath(): string
    {
        return __DIR__ . '/../Templates/route_not_found.php';
    }

    /**
     * No-op. Historically "orphan" half tiles (not paired with another half)
     * were stretched to 2 columns via a `m-tile--orphan` class so the grid
     * had no gaps. Current design choice: keep the gap — a half stays a
     * half even when alone, so the layout truly respects the user's choice.
     * Kept for call-site compat; can be removed once the caller stops using it.
     *
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    private function markOrphanHalves(array $blocks): array
    {
        return $blocks;
    }

    /**
     * Whether the block has enough content to be shown publicly.
     *
     * `hero`, `divider`, `footer` and any "structural" type fall through
     * to `default: return true` (they make sense even with minimal data).
     *
     * **Adding a new block type?** Add a `case` here when the block has
     * required content. If the block is structural (always shown), the
     * default branch covers you. The `tests/Unit/BlockRegistryCoverageTest`
     * watches for new block types that lack a case here and flags them.
     */
    public function blockHasContent(array $block): bool
    {
        $type = $block['type'] ?? '';
        $data = $block['data'] ?? [];
        switch ($type) {
            case 'gallery':
                foreach (($data['items'] ?? []) as $it) {
                    if (!empty($it['image'])) return true;
                }
                return false;
            case 'links':
                foreach (($data['items'] ?? []) as $it) {
                    if (!empty($it['url'])) return true;
                }
                return false;
            case 'social':
                foreach (($data['items'] ?? []) as $it) {
                    if (!empty($it['url'])) return true;
                }
                return false;
            case 'apps':
            case 'products':
            case 'skills':
                foreach (($data['items'] ?? []) as $it) {
                    if (!empty($it['name'])) return true;
                }
                return false;
            case 'embed':
                return !empty($data['url']);
            case 'youtube':
                return !empty($data['source_url']);
            case 'podcast':
                // Render if at least one of the 3 URLs is set.
                return !empty($data['apple_url'])
                    || !empty($data['spotify_url'])
                    || !empty($data['site_url'])
                    || !empty($data['url']); // legacy fallback (old field)
            case 'bio':
                return !empty($data['body']);
            case 'contact':
                return !empty($data['fields']);
            case 'quote':
                // Either the quote text itself or the title is enough.
                return !empty($data['text']) || !empty($data['title']);
            case 'stats':
                foreach (($data['items'] ?? []) as $it) {
                    if (!empty($it['value']) || !empty($it['label'])) return true;
                }
                return false;
            case 'cta':
                // CTA is useful only if there's an action: button URL is
                // the strict requirement; a title alone would be a banner.
                return !empty($data['button_url']);
            case 'faq':
                foreach (($data['items'] ?? []) as $it) {
                    if (!empty($it['question'])) return true;
                }
                return false;
            case 'timeline':
                foreach (($data['items'] ?? []) as $it) {
                    if (!empty($it['title']) || !empty($it['date'])) return true;
                }
                return false;
            // hero, divider, footer: always visible
            default:
                return true;
        }
    }

    public function renderBlock(array $block, array $theme): string
    {
        $type = $block['type'];
        $tpl = $this->config->path("app/Templates/blocks/$type.php");
        if (!file_exists($tpl)) {
            return '<!-- block type not implemented: ' . htmlspecialchars($type) . ' -->';
        }
        ob_start();
        $renderer = $this;
        $data = $block['data'];
        $style = $block['style'];
        $blockId = (int)$block['id'];
        require $tpl;
        return (string)ob_get_clean();
    }

    public function settingsValue(array $settings, string $key, mixed $default = ''): mixed
    {
        return $settings[$key] ?? $default;
    }

    public function escape(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Proof-of-work challenge to embed in the contact form: returns
     * `['token' => 'salt:ts.sig', 'difficulty' => 18]`. The client must
     * find a nonce such that SHA-256(token:nonce) has `difficulty` leading
     * zero bits; the server re-verifies signature + nonce.
     *
     * @return array{token: string, difficulty: int}
     */
    public function captchaChallenge(): array
    {
        return \Tylio\Controllers\SubmissionsController::issueCaptchaChallenge($this->config);
    }

    /**
     * Expose Config to templates / utility services that need it (e.g.
     * YouTubeFeed cache under data/cache/). Not a generic "give me anything"
     * getter — returns only Config, which is already publicly available
     * through the DI container.
     */
    public function config(): \Tylio\Config
    {
        return $this->config;
    }

    /**
     * Linkify free text: turn `http(s)://...` URLs and emails into `<a>`
     * tags. Everything else is escaped as text. XSS-safe: we work on pre/post
     * match segments (preg_split DELIM_CAPTURE) and escape each piece
     * individually before concatenating the output.
     *
     * Trim trailing punctuation like `.,;:)!?` from the URL: often part of
     * the sentence rather than the URL itself (e.g. "see github.com/tylio."
     * → final dot excluded from the link).
     */
    public function linkify(string $text): string
    {
        if ($text === '') return '';
        $pattern = '#(https?://[^\s<>"\']+|[\w.+-]+@[\w-]+(?:\.[\w-]+)+)#u';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) return $this->escape($text);
        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $out .= $this->escape($part);
                continue;
            }
            // Match: URL or email
            if (str_starts_with($part, 'http')) {
                $trail = '';
                while ($part !== '' && preg_match('/[.,;:!?)\]]$/', $part)) {
                    $trail = substr($part, -1) . $trail;
                    $part = substr($part, 0, -1);
                }
                $out .= '<a href="' . $this->escape($part)
                     . '" target="_blank" rel="noopener noreferrer">'
                     . $this->escape($part) . '</a>';
                if ($trail !== '') $out .= $this->escape($trail);
            } else {
                // Email
                $out .= '<a href="mailto:' . $this->escape($part) . '">'
                     . $this->escape($part) . '</a>';
            }
        }
        return $out;
    }

    /**
     * ISO-8601 date → human-friendly relative distance, localized via the
     * injected `I18n` service (`relative.*` keys). Empty string for
     * invalid input. Thresholds are pragmatic rather than astronomical:
     * we want text that "sounds natural" to the reader, not precision.
     */
    public function relativeDate(string $iso): string
    {
        if ($iso === '') return '';
        $ts = strtotime($iso);
        if ($ts === false) return '';
        $diff = time() - $ts;
        if ($diff < 0) return $this->t('relative.future');
        if ($diff < 60) return $this->t('relative.now');
        if ($diff < 3600) return $this->t('relative.minutes_ago', ['n' => (int)floor($diff / 60)]);
        if ($diff < 86400) return $this->t('relative.hours_ago', ['n' => (int)floor($diff / 3600)]);
        if ($diff < 86400 * 2) return $this->t('relative.yesterday');
        if ($diff < 86400 * 7) return $this->t('relative.days_ago', ['n' => (int)floor($diff / 86400)]);
        if ($diff < 86400 * 30) return $this->t('relative.weeks_ago', ['n' => (int)floor($diff / (86400 * 7))]);
        if ($diff < 86400 * 365) return $this->t('relative.months_ago', ['n' => (int)floor($diff / (86400 * 30))]);
        return $this->t('relative.years_ago', ['n' => (int)floor($diff / (86400 * 365))]);
    }

    /**
     * CSS class for a block's section title, based on `block.style.title_size`.
     * Default 'standard'. Three options:
     *   - small    → m-h-sm  (18px, compact)
     *   - standard → m-h-std (24px) ← DEFAULT
     *   - large    → m-h-lg  (22-30px clamp)
     * Keeps tiles visually uniform out of the box; users wanting stronger
     * hierarchy can pick a different size per block.
     */
    public function titleClass(array $style): string
    {
        $size = is_string($style['title_size'] ?? null) ? $style['title_size'] : 'standard';
        $modifier = match ($size) {
            'small' => 'm-h-sm',
            'large' => 'm-h-lg',
            default => 'm-h-std',
        };
        return 'm-h ' . $modifier;
    }

    /**
     * Returns the URL only if it has a safe scheme (http, https, mailto, tel),
     * is a relative path (/...), or an anchor (#...). Otherwise $fallback.
     * Blocks javascript:, data:, vbscript:, file:, and injections via
     * control characters.
     */
    public function safeUrl(string $url, string $fallback = '#'): string
    {
        $url = trim($url);
        if ($url === '') return $fallback;
        // Control characters / newlines = potential header injection.
        if (preg_match('/[\x00-\x1f\x7f]/', $url)) return $fallback;
        if ($url[0] === '#') return $url;
        // Relative path: '/foo' but not '//evil.com' (protocol-relative URL).
        if ($url[0] === '/' && (!isset($url[1]) || $url[1] !== '/')) return $url;
        if (preg_match('#^(https?|mailto|tel):#i', $url)) return $url;
        return $fallback;
    }

    /**
     * Validate a CSS color value (hex #abc / #abcdef / rgb()/rgba()).
     * Returns the original value if valid, null otherwise.
     * Use BEFORE emitting a color inside `style="..."` attributes.
     */
    public function cssColor(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        return self::parseColorRgb($value) !== null ? $value : null;
    }

    /**
     * Build the embed-friendly (player) URL for the chosen provider. Lives
     * here instead of inside the block template because the template is
     * included once per render: declaring a global function there would
     * raise "Cannot redeclare function" when multiple `embed` blocks
     * coexist on the same page.
     */
    public function embedUrl(string $provider, string $url): ?string
    {
        if ($url === '') return null;
        if ($provider === 'youtube') {
            if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/|youtube\.com/shorts/)([\w-]{11})#', $url, $m)) {
                return 'https://www.youtube-nocookie.com/embed/' . $m[1];
            }
            return null;
        }
        if ($provider === 'vimeo') {
            if (preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $m)) {
                return 'https://player.vimeo.com/video/' . $m[1];
            }
            return null;
        }
        if ($provider === 'spotify') {
            if (preg_match('#open\.spotify\.com/(track|album|playlist|episode|show)/([a-zA-Z0-9]+)#', $url, $m)) {
                return 'https://open.spotify.com/embed/' . $m[1] . '/' . $m[2];
            }
            return null;
        }
        if ($provider === 'soundcloud') {
            if (!preg_match('#^https?://#', $url)) return null;
            return 'https://w.soundcloud.com/player/?url=' . rawurlencode($url) . '&color=%23d4a574&auto_play=false&hide_related=true&visual=true';
        }
        if ($provider === 'iframe') {
            return preg_match('#^https?://#', $url) ? $url : null;
        }
        return null;
    }

    /**
     * Return a high-contrast foreground (text) color over the given `$bg`.
     * Uses WCAG relative luminance. For unparseable colors (CSS names,
     * oklch, hsl, …) returns `$fallback`.
     */
    public function contrastFg(string $bg, string $light = '#ffffff', string $dark = '#1a1410', string $fallback = '#ffffff'): string
    {
        $rgb = self::parseColorRgb($bg);
        if (!$rgb) return $fallback;
        [$r, $g, $b] = $rgb;
        $L = 0.2126 * self::srgbToLinear($r) + 0.7152 * self::srgbToLinear($g) + 0.0722 * self::srgbToLinear($b);
        return $L > 0.55 ? $dark : $light;
    }

    private static function srgbToLinear(int $v): float
    {
        $s = $v / 255.0;
        return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    }

    private static function parseColorRgb(string $c): ?array
    {
        $c = trim($c);
        if ($c === '') return null;
        if ($c[0] === '#') {
            $hex = substr($c, 1);
            if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }
            if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return null;
            return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
        }
        if (preg_match('/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/i', $c, $m)) {
            return [(int)$m[1], (int)$m[2], (int)$m[3]];
        }
        return null;
    }

    /**
     * Linear mix of two sRGB colors. `$t` = 0 → only $base, 1 → only $tint.
     * Returns a solid hexadecimal `#rrggbb` (no alpha). If either color is
     * unparseable, falls back to $base.
     *
     * Used to pre-compute "soft accents" of the theme: instead of relying on
     * CSS `color-mix(… transparent)` — which produces an apparent color that
     * depends on whatever is behind the element, and is therefore
     * unpredictable — we emit SOLID variants as CSS variables (e.g.
     * `--accent-soft`). When the user changes `accent` in the palette every
     * component that consumes `--accent-soft` updates consistently,
     * regardless of the layer it's mounted on.
     */
    public static function mixColors(string $base, string $tint, float $t): string
    {
        $a = self::parseColorRgb($base);
        $b = self::parseColorRgb($tint);
        if (!$a || !$b) return $base;
        $t = max(0.0, min(1.0, $t));
        $r = (int)round($a[0] * (1 - $t) + $b[0] * $t);
        $g = (int)round($a[1] * (1 - $t) + $b[1] * $t);
        $bl = (int)round($a[2] * (1 - $t) + $b[2] * $t);
        return sprintf('#%02x%02x%02x', $r, $g, $bl);
    }

    public function markdown(string $md): string
    {
        return Markdown::render($md);
    }

    public function themeCssVars(array $theme): string
    {
        // Defense-in-depth: the ThemeController already sanitizes at the
        // boundary (see `ThemeController::sanitizeTheme()`), but we
        // re-validate here so legacy DB rows (saved before the sanitizer
        // existed) cannot inject CSS into the <style> block. Each value
        // that lands inside the `:root{…}` rule is validated against a
        // strict char-class before interpolation.
        $palette = $theme['palette'] ?? [];
        $tile = $theme['tile'] ?? [];
        $font = $theme['font'] ?? [];
        $bg = $theme['background'] ?? [];

        $safeColor = static fn(?string $v, string $fallback): string =>
            ($v !== null && \Tylio\Controllers\ThemeController::isValidCssColor((string)$v)) ? (string)$v : $fallback;
        $safeFont = static fn(?string $v, string $fallback): string =>
            ($v !== null && \Tylio\Controllers\ThemeController::isValidFontFamily((string)$v)) ? (string)$v : $fallback;

        $patternColor = $safeColor($palette['text_muted'] ?? null, '#9c8e7c');
        // Strip quotes/parentheses defensively before wrapping in url('…').
        $bgImageSafe = is_string($bg['image'] ?? null)
            ? (preg_replace('/["\\\'()]/', '', $bg['image']) ?? '')
            : '';
        $patternImage = $bgImageSafe !== '' ? "url('" . $bgImageSafe . "')" : 'none';

        $surface = $safeColor($palette['surface'] ?? null, '#1a1612');
        $accent = $safeColor($palette['accent'] ?? null, '#d4a574');
        $accentAlt = $safeColor($palette['accent_alt'] ?? null, '#e8c598');

        // ===== Derived solid colors =====
        // Server-side, computed as SOLID hex: the apparent color no longer
        // depends on the layer behind the element (unlike CSS
        // color-mix(… transparent), which lets the underlying layer show
        // through and makes the final result unpredictable). Role map —
        // user changes the palette → components respond deterministically:
        //  • --accent / --accent-alt    : main accent colors (links, badges, …)
        //  • --accent-soft / -alt-soft  : lightly tinted variants for surfaces
        //                                  (chips, hovers, code pills)
        //  • --accent-fg / -alt-fg      : high-contrast foreground ON the
        //                                  matching accent (badge text)
        // Soft accent: used as the background of primary chips (link icon,
        // social pill). Auto-derived as 18% accent over surface; if the user
        // overrides it in the palette, the manual value wins (needed for
        // patterns like "white social pills with a purple icon", impossible
        // if soft were purely a function of accent+surface).
        $accentSoft = isset($palette['accent_soft']) && $palette['accent_soft'] !== ''
            ? $palette['accent_soft']
            : self::mixColors($surface, $accent, 0.18);
        $accentFg = $this->contrastFg($accent);
        // accent_alt_fg: the foreground over accent_alt is an editorial
        // choice (WCAG luminance rarely produces a well-tuned result on the
        // warm/medium tones typical of secondary accents). If the user set
        // an explicit value in the palette we honor it, otherwise fall back
        // to the auto-derived one for back-compat with installs predating
        // this feature.
        $accentAltFg = isset($palette['accent_alt_fg']) && $palette['accent_alt_fg'] !== ''
            ? $palette['accent_alt_fg']
            : $this->contrastFg($accentAlt);

        // Cast all numeric/range-bounded values explicitly to avoid
        // injecting arbitrary text via the JSON column.
        $clampInt = static fn(mixed $v, int $min, int $max, int $default): int =>
            is_numeric($v) ? max($min, min($max, (int)$v)) : $default;
        $clampFloat = static fn(mixed $v, float $min, float $max, float $default): float =>
            is_numeric($v) ? max($min, min($max, (float)$v)) : $default;

        $vars = [
            '--bg' => $safeColor($palette['bg'] ?? null, '#0f0d0a'),
            '--surface' => $surface,
            '--surface-alt' => $safeColor($palette['surface_alt'] ?? null, '#221c17'),
            '--text' => $safeColor($palette['text'] ?? null, '#f4ede1'),
            '--text-muted' => $safeColor($palette['text_muted'] ?? null, '#9c8e7c'),
            '--accent' => $accent,
            '--accent-alt' => $accentAlt,
            '--accent-soft' => $accentSoft,
            '--accent-fg' => $accentFg,
            '--accent-alt-fg' => $accentAltFg,
            '--border' => $safeColor($palette['border'] ?? null, 'rgba(244,237,225,0.08)'),
            '--tile-radius' => $clampInt($tile['radius'] ?? null, 0, 100, 18) . 'px',
            '--tile-gap' => $clampInt($tile['gap'] ?? null, 0, 100, 14) . 'px',
            '--tile-border' => $clampInt($tile['border'] ?? null, 0, 20, 1) . 'px',
            '--tile-opacity' => (string)$clampFloat($tile['opacity'] ?? null, 0.0, 1.0, 0.7),
            '--font-heading' => '"' . $safeFont($font['heading'] ?? null, 'Fraunces') . '", serif',
            '--font-body' => '"' . $safeFont($font['body'] ?? null, 'Inter') . '", system-ui, sans-serif',
            '--bg-pattern-intensity' => (string)$clampFloat($bg['intensity'] ?? null, 0.0, 1.0, 0.12),
            '--bg-pattern-color' => $patternColor,
            '--bg-pattern-image' => $patternImage,
        ];

        $out = '';
        foreach ($vars as $k => $v) {
            $out .= $k . ':' . $v . ';';
        }
        return $out;
    }

    /**
     * Compute a span (1 = half, 2 = full) for the 2-column desktop layout.
     *
     * Priority: per-block override (`block.style.span`) > per-type default
     * (BlockRegistry). The override lets the user change the width of a
     * single tile without touching the global default for that type.
     * Accepted values: 'full' (= 2) or 'half' (= 1); anything else falls
     * back to the default.
     */
    public function blockSpan(array $block): int
    {
        $override = $block['style']['span'] ?? null;
        if ($override === 'half') return 1;
        if ($override === 'full') return 2;

        $def = $this->registry->get($block['type']);
        $span = $def['span'] ?? 'full';
        if ($span === 'half') return 1;
        return 2;
    }
}
