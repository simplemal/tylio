<?php
declare(strict_types=1);

namespace Tylio\Services;

use Tylio\Config;
use RuntimeException;
use ZipArchive;

/**
 * Export the tylio page as a self-contained static site (zip).
 *
 * Zip output:
 *   - index.html       (complete HTML, CSS already inline in the template)
 *   - uploads/…        (images referenced by tiles)
 *   - favicons/…       (if the user uploaded a favicon)
 *   - logo.svg         (fallback favicon)
 *
 * NOT included: Google Fonts CDN, Iconify CDN (kept online-only), the
 * dynamic webmanifest, the admin-only preview script.
 *
 * Absolute paths (`/uploads/x.jpg`) are rewritten relative
 * (`./uploads/x.jpg`) so the page opens directly via `file://` or deploys
 * to any static host (Netlify, GitHub Pages, S3, …).
 *
 * Security: `safeAssetPath()` whitelists allowed prefixes and blocks path
 * traversal — without this guard a malicious URL injected into block data
 * could read files outside `public/uploads/`.
 */
final class StaticExporter
{
    public function __construct(
        private Renderer $renderer,
        private Config $config,
    ) {}

    /**
     * Block types that need a backend and are therefore OMITTED from the
     * static export:
     *   - `contact`: POSTs to /submit/{blockId} → server (and then email).
     * Documented in the admin UI for user clarity.
     */
    private const EXPORT_EXCLUDED_TYPES = ['contact'];

    /**
     * Export the page as a SINGLE self-contained HTML file: every
     * referenced image (/uploads, /favicons, /logo.svg) is inlined as a
     * base64 data URI. CSS and theme vars are already inline in the
     * template. The only online-only assets are Google Fonts CDN and
     * Iconify CDN (icons). Output is usable via `file://` or droppable on
     * any static host as `index.html`.
     *
     * @return string complete, self-contained HTML
     */
    public function exportInline(): string
    {
        $html = $this->renderer->renderPage(false, null, self::EXPORT_EXCLUDED_TYPES);
        $html = $this->stripDynamic($html);
        $html = $this->inlineAssets($html);
        return $html;
    }

    /** @return string absolute path of the zip file inside sys_get_temp_dir() */
    public function export(): string
    {
        $html = $this->renderer->renderPage(false, null, self::EXPORT_EXCLUDED_TYPES);

        $tmpDir = $this->mkTempDir();
        try {
            $this->copyReferencedAssets($html, $tmpDir);
            $html = $this->relativizeAssets($html);
            $html = $this->stripDynamic($html);
            file_put_contents($tmpDir . '/index.html', $html);

            $zipPath = sys_get_temp_dir() . '/tylio-export-'
                . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.zip';
            $this->zipDirectory($tmpDir, $zipPath);
            return $zipPath;
        } finally {
            $this->rmTree($tmpDir);
        }
    }

    private function copyReferencedAssets(string $html, string $tmpDir): void
    {
        $found = [];
        $pattern = '#["\'(]\s*(/(?:uploads|favicons|logo\.svg)[^"\')\s>]*)#';
        if (preg_match_all($pattern, $html, $m)) {
            foreach ($m[1] as $url) {
                $found[strtok($url, '?')] = true;
            }
        }
        if (!isset($found['/logo.svg'])) {
            $found['/logo.svg'] = true;
        }

        $allowedRoots = ['/uploads', '/favicons', '/logo.svg'];

        foreach (array_keys($found) as $relUrl) {
            $resolved = $this->safeAssetPath($relUrl, $allowedRoots);
            if ($resolved === null) continue;
            [$src, $relSafe] = $resolved;
            if (!is_file($src)) continue;
            $dst = $tmpDir . $relSafe;
            @mkdir(dirname($dst), 0750, true);
            $realDstDir = realpath(dirname($dst));
            $realTmp = realpath($tmpDir);
            if ($realDstDir === false || $realTmp === false) continue;
            if (!str_starts_with($realDstDir, $realTmp . '/') && $realDstDir !== $realTmp) continue;
            @copy($src, $dst);
        }
    }

    // Hard caps to avoid OOM and absurdly large downloads when a site has
    // many heavy uploads. Each asset > MAX_ASSET is LEFT AS-URL (not
    // inlined) — the export stays valid but those assets stay online. The
    // total cap stops inlining once exceeded.
    private const INLINE_MAX_ASSET_BYTES = 8 * 1024 * 1024;       // 8 MB raw
    private const INLINE_MAX_TOTAL_BYTES = 80 * 1024 * 1024;      // 80 MB raw

    /**
     * Replace every occurrence of /uploads/*, /favicons/*, /logo.svg in the
     * template with a base64 data URI. Reuses `copyReferencedAssets`'s
     * whitelist (path-traversal-safe via safeAssetPath). Assets larger than
     * INLINE_MAX_ASSET_BYTES or past the total budget stay as URLs — no
     * OOM, no gigabyte downloads.
     */
    private function inlineAssets(string $html): string
    {
        $allowedRoots = ['/uploads', '/favicons', '/logo.svg'];
        $pattern = '#["\'(]\s*(/(?:uploads|favicons|logo\.svg)[^"\')\s>]*)#';
        if (!preg_match_all($pattern, $html, $m)) return $html;

        $cache = [];
        $totalBytes = 0;
        foreach (array_unique($m[1]) as $url) {
            $clean = strtok($url, '?#') ?: $url;
            if (isset($cache[$clean])) continue;
            $resolved = $this->safeAssetPath($clean, $allowedRoots);
            if ($resolved === null) {
                $cache[$clean] = null;
                continue;
            }
            [$src, ] = $resolved;
            if (!is_file($src)) {
                $cache[$clean] = null;
                continue;
            }
            $size = (int)@filesize($src);
            if ($size <= 0 || $size > self::INLINE_MAX_ASSET_BYTES) {
                $cache[$clean] = null; // asset too large: stays as online URL
                continue;
            }
            if ($totalBytes + $size > self::INLINE_MAX_TOTAL_BYTES) {
                $cache[$clean] = null; // total budget exhausted
                continue;
            }
            $bin = @file_get_contents($src);
            if ($bin === false) {
                $cache[$clean] = null;
                continue;
            }
            $totalBytes += $size;
            $mime = $this->guessMimeType($src);
            $cache[$clean] = 'data:' . $mime . ';base64,' . base64_encode($bin);
        }

        // Substitution: every matched URL → data URI (if resolvable),
        // otherwise the URL is left as-is. The regex includes the original
        // `?…` query because the `clean` version strips it: this way both
        // `/logo.svg` and `/logo.svg?v=12` match.
        return (string)preg_replace_callback(
            '#(["\'(])(/(?:uploads|favicons|logo\.svg)[^"\')\s>]*)#',
            static function (array $match) use ($cache): string {
                $delim = $match[1];
                $url = $match[2];
                $clean = strtok($url, '?#') ?: $url;
                $data = $cache[$clean] ?? null;
                return $data !== null ? $delim . $data : $match[0];
            },
            $html,
        );
    }

    /**
     * MIME type from extension (leaner than mime_content_type, which needs
     * the fileinfo extension and isn't always enabled). Fallback:
     * `application/octet-stream`.
     */
    private function guessMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'avif' => 'image/avif',
            default => 'application/octet-stream',
        };
    }

    private function relativizeAssets(string $html): string
    {
        return preg_replace(
            '#(["\'(])(/(?:uploads|favicons|logo\.svg))#',
            '$1.$2',
            $html,
        ) ?? $html;
    }

    private function stripDynamic(string $html): string
    {
        $html = preg_replace('#<link\s+rel=["\']manifest["\'][^>]*>\s*#i', '', $html) ?? $html;
        $html = preg_replace('#<script>\s*//\s*=====\s*Live preview.*?</script>#s', '', $html) ?? $html;
        // Click tracking: usa navigator.sendBeacon('/track-click') — endpoint
        // server-side che fallisce 404 offline e sporca la console. Strip.
        $html = preg_replace('#<script>\s*//\s*Click tracking:.*?</script>#s', '', $html) ?? $html;
        return $html;
    }

    /**
     * @param list<string> $allowedRoots
     * @return array{0:string,1:string}|null
     */
    private function safeAssetPath(string $relUrl, array $allowedRoots): ?array
    {
        $relUrl = strtok($relUrl, '?#') ?: $relUrl;
        $decoded = rawurldecode($relUrl);
        if (str_contains($decoded, '..') || str_contains($decoded, '//')) return null;
        $decoded = '/' . ltrim($decoded, '/');

        $allowed = false;
        foreach ($allowedRoots as $root) {
            if ($decoded === $root || str_starts_with($decoded, $root . '/')) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) return null;

        $rootPublic = rtrim($this->config->path(''), '/');
        $candidate = $rootPublic . $decoded;
        $real = realpath($candidate);
        if ($real !== false) {
            $realPublic = realpath($rootPublic);
            if ($realPublic === false || !str_starts_with($real, $realPublic . '/')) return null;
            return [$real, $decoded];
        }
        return [$candidate, $decoded];
    }

    private function mkTempDir(): string
    {
        $base = sys_get_temp_dir();
        for ($i = 0; $i < 5; $i++) {
            $dir = $base . '/tylio-export-' . bin2hex(random_bytes(8));
            if (@mkdir($dir, 0750)) return $dir;
        }
        throw new RuntimeException('cannot create temp dir for export');
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir) ?: [];
        foreach ($items as $i) {
            if ($i === '.' || $i === '..') continue;
            $path = $dir . '/' . $i;
            is_dir($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function zipDirectory(string $srcDir, string $zipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('cannot create zip: ' . $zipPath);
        }
        $rootLen = strlen($srcDir) + 1;
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) continue;
            $localName = substr($file->getPathname(), $rootLen);
            $zip->addFile($file->getPathname(), $localName);
        }
        $zip->close();
    }
}
