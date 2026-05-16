<?php
declare(strict_types=1);

namespace Tylio\Services;

use Tylio\Config;

/**
 * Self-service in-app upgrade for tylio OSS — driven by
 * `POST /api/admin/update/apply` (see `UpdateController::apply`).
 *
 * Flow:
 *   1. Pre-flight: writable root, not already in progress, target asset
 *      resolvable on the GitHub release page.
 *   2. Lock (`site.update_in_progress = true`) + save current
 *      maintenance flag → force maintenance ON for the duration.
 *   3. Download the release's `tylio-source-vX.Y.Z.tar.gz` asset to
 *      `/tmp/`, extract via {@see \PharData} (zero shell dependency).
 *   4. Backup current install root → `data/.backup/<old>-<ts>.tar.gz`
 *      so the admin can roll back manually if anything looks off.
 *   5. Atomic-ish swap: replace each top-level entry in the install
 *      root with the one from staging, EXCEPT the preserve set
 *      (`data/`, `uploads/`, `favicons/`, `.env`).
 *   6. Run pending migrations via {@see Migrations::run}.
 *   7. Persist new `BUILD`/`.version` cachebuster strings.
 *   8. opcache_reset() so the next request picks up the new code.
 *   9. Restore prior maintenance flag, release the lock, record
 *      `last_update_*` settings for the SPA to render.
 *
 * **Safety**: this service runs in the SAME PHP request that initiated
 * it. The currently-executing process keeps a stale class graph in
 * memory — but `opcache_reset()` at the end means *next* requests use
 * the fresh code. Concurrent admin actions during apply() are blocked
 * by maintenance mode (visitors get the 503 page; admins logged in get
 * a slim window of stale code which is acceptable for an MVP).
 *
 * **Extendable by design.** Non-`final` so the SaaS overlay's
 * `TenantUpdateController::apply` (commit later in this PR) can short-
 * circuit before reaching here — the platform operator updates all
 * tenants centrally, so per-tenant self-update is disabled.
 */
class UpdateApplier
{
    private const GITHUB_LATEST_URL = 'https://api.github.com/repos/simplemal/tylio/releases/latest';
    private const GITHUB_TAG_URL_TEMPLATE = 'https://api.github.com/repos/simplemal/tylio/releases/tags/%s';

    /**
     * Top-level entries we never touch during swap. Anything under these
     * names in the install root is preserved as-is; staging entries with
     * the same name (if any — release tarballs don't ship them) are
     * skipped.
     *
     * @var list<string>
     */
    private const PRESERVE = [
        'data',
        'uploads',
        'favicons',
        '.env',
        // Hidden git metadata too: many self-hosted installs are `git
        // clone`'d, swapping `.git` would break `git pull` recovery.
        '.git',
    ];

    /** Asset filename produced by `scripts/make-release.sh`. */
    private const SOURCE_ASSET_PATTERN = '/^tylio-source-v[\d\.]+\.tar\.gz$/';

    public function __construct(
        protected DB $db,
        protected Config $config,
        protected Migrations $migrations,
    ) {}

    /**
     * Single entry-point. Returns a result array that the controller
     * serialises straight to JSON. Never throws — all failures surface
     * as `['ok' => false, 'error' => ..., 'detail' => ...]` and are
     * also persisted to `site.last_update_error` for the SPA.
     *
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   detail?: string,
     *   new_version?: string,
     *   backup_path?: string,
     * }
     */
    public function apply(?string $targetVersion = null): array
    {
        $root = rtrim($this->config->rootPath, '/');

        // --- 1. Pre-flight ------------------------------------------------
        if (!is_writable($root)) {
            return $this->fail('permissions_denied',
                "Il processo PHP non può scrivere su $root. "
                . "Esegui: sudo chown -R www-data:www-data $root && sudo chmod -R u+w $root");
        }
        if ($this->isInProgress()) {
            return $this->fail('already_in_progress',
                'Un altro aggiornamento è già in corso. Aspetta che termini.');
        }

        // --- 2. Acquire lock + force maintenance -------------------------
        $this->setSetting('site.update_in_progress', true);
        $prevMaintenance = $this->getSettingBool('site.maintenance');
        $this->setSetting('site.maintenance', true);

        $tarballPath = null;
        $stagingDir = null;
        $backupPath = null;

        try {
            // --- 3. Resolve release asset URL ----------------------------
            $release = $this->fetchRelease($targetVersion);
            if ($release === null) {
                return $this->fail('release_not_found',
                    'Nessuna release trovata su GitHub'
                    . ($targetVersion !== null ? " per il tag $targetVersion." : '.'));
            }
            $newVersion = (string)$release['tag_name'];
            $asset = $this->pickSourceAsset($release['assets']);
            if ($asset === null) {
                return $this->fail('asset_missing',
                    "La release $newVersion non contiene l'asset "
                    . '`tylio-source-' . $newVersion . '.tar.gz`. '
                    . 'Il maintainer deve generarlo con `scripts/make-release.sh`.');
            }

            // --- 4. Download tarball -------------------------------------
            $tarballPath = $this->downloadTarball((string)$asset['browser_download_url'], $newVersion);
            if ($tarballPath === null) {
                return $this->fail('download_failed',
                    'Impossibile scaricare l\'asset di release da GitHub.');
            }

            // --- 5. Extract to staging ------------------------------------
            $stagingDir = $this->extractTarball($tarballPath);
            if ($stagingDir === null) {
                return $this->fail('extract_failed',
                    'Il tarball è corrotto o non è un .tar.gz valido.');
            }
            if (!$this->stagingLooksValid($stagingDir)) {
                return $this->fail('staging_invalid',
                    "L'archivio scaricato non contiene `app/` e `index.php` — "
                    . 'probabilmente non è un tylio source bundle.');
            }

            // --- 6. Backup current root ----------------------------------
            $backupPath = $this->backupCurrentRoot($root);
            $this->setSetting('site.last_update_backup', $backupPath);

            // --- 7. Swap staging → root ----------------------------------
            $this->swapInPlace($stagingDir, $root);

            // --- 8. Run migrations ---------------------------------------
            // Migrations::run() is also called on every boot, but we run
            // it here so we can surface a migration failure as part of
            // the apply() result instead of silently 500'ing the next
            // request.
            $this->migrations->run();

            // --- 9. Reset opcache ----------------------------------------
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            // --- 10. Record success --------------------------------------
            $this->setSetting('site.last_update_at', gmdate('c'));
            $this->setSetting('site.last_update_version', $newVersion);
            $this->setSetting('site.last_update_error', '');

            return [
                'ok' => true,
                'new_version' => $newVersion,
                'backup_path' => $backupPath,
            ];
        } catch (\Throwable $e) {
            return $this->fail('exception', $e->getMessage(), [
                'class' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'backup_path' => $backupPath,
            ]);
        } finally {
            // Best-effort cleanup of temp files. Backups stay on disk.
            if ($tarballPath !== null && is_file($tarballPath)) @unlink($tarballPath);
            if ($stagingDir !== null && is_dir($stagingDir)) $this->rmrf($stagingDir);
            // Restore prior maintenance flag (so a site that wasn't in
            // maintenance before apply() returns to live state).
            $this->setSetting('site.maintenance', $prevMaintenance);
            $this->setSetting('site.update_in_progress', false);
        }
    }

    /**
     * Persist a failure result for the SPA AND return it to the caller.
     *
     * @param array<string,mixed> $extra
     * @return array{ok:bool,error:string,detail:string}
     */
    protected function fail(string $code, string $message, array $extra = []): array
    {
        $this->setSetting('site.last_update_error', $code . ': ' . $message);
        return array_merge([
            'ok' => false,
            'error' => $code,
            'detail' => $message,
        ], $extra);
    }

    // -------------------------------------------------------------------
    // GitHub release resolution
    // -------------------------------------------------------------------

    /**
     * Fetch a release. If $tag is null, fetches `releases/latest`;
     * otherwise `releases/tags/{tag}`. Returns null on any failure.
     *
     * @return array{tag_name:string,assets:list<array{name:string,browser_download_url:string}>}|null
     */
    protected function fetchRelease(?string $tag): ?array
    {
        $url = $tag === null
            ? self::GITHUB_LATEST_URL
            : sprintf(self::GITHUB_TAG_URL_TEMPLATE, rawurlencode($tag));
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => implode("\r\n", [
                    'Accept: application/vnd.github+json',
                    'User-Agent: tylio-update-applier/1.0',
                    'X-GitHub-Api-Version: 2022-11-28',
                ]),
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || $body === '') return null;
        try {
            /** @var array<string,mixed> $json */
            $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!isset($json['tag_name']) || !is_string($json['tag_name'])) return null;
        $assets = [];
        if (isset($json['assets']) && is_array($json['assets'])) {
            foreach ($json['assets'] as $a) {
                if (is_array($a) && isset($a['name'], $a['browser_download_url'])
                    && is_string($a['name']) && is_string($a['browser_download_url'])
                ) {
                    $assets[] = ['name' => $a['name'], 'browser_download_url' => $a['browser_download_url']];
                }
            }
        }
        return ['tag_name' => $json['tag_name'], 'assets' => $assets];
    }

    /**
     * Find the source-bundle asset (`tylio-source-vX.Y.Z.tar.gz`) in a
     * release. The admin-only `tylio-admin-bundle-*.tar.gz` is rejected
     * — we need the full source for an upgrade, not just the SPA.
     *
     * @param list<array{name:string,browser_download_url:string}> $assets
     * @return array{name:string,browser_download_url:string}|null
     */
    protected function pickSourceAsset(array $assets): ?array
    {
        foreach ($assets as $a) {
            if (preg_match(self::SOURCE_ASSET_PATTERN, $a['name'])) {
                return $a;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------
    // Download + extract
    // -------------------------------------------------------------------

    /**
     * Stream the asset to a unique file under the system temp dir.
     * Returns the absolute path, or null on failure.
     */
    protected function downloadTarball(string $url, string $version): ?string
    {
        $tmpPath = sys_get_temp_dir() . '/tylio-update-' . $version . '-' . bin2hex(random_bytes(4)) . '.tar.gz';
        $in = @fopen($url, 'rb', false, stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 60,
                'header' => 'User-Agent: tylio-update-applier/1.0',
                'follow_location' => 1,
            ],
        ]));
        if ($in === false) return null;
        $out = @fopen($tmpPath, 'wb');
        if ($out === false) { fclose($in); return null; }
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false) break;
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);
        if (!is_file($tmpPath) || filesize($tmpPath) < 1024) {
            @unlink($tmpPath);
            return null;
        }
        return $tmpPath;
    }

    /**
     * Extract a .tar.gz into a fresh staging dir, then collapse a single
     * top-level wrapper directory if the archive contains one (a tarball
     * built with `tar -czf x.tar.gz some-dir/` extracts into
     * `staging/some-dir/...`; we want `staging/...` directly).
     *
     * Returns the staging dir, or null on extract failure.
     */
    protected function extractTarball(string $tarballPath): ?string
    {
        $stagingDir = sys_get_temp_dir() . '/tylio-update-staging-' . bin2hex(random_bytes(4));
        if (!mkdir($stagingDir, 0700, true)) return null;
        try {
            // PharData wants the .tar (not .tar.gz). Decompress first.
            $phar = new \PharData($tarballPath);
            $phar->extractTo($stagingDir, null, true);
        } catch (\Throwable) {
            $this->rmrf($stagingDir);
            return null;
        }
        // Collapse single wrapper dir if present.
        $entries = array_values(array_diff(scandir($stagingDir) ?: [], ['.', '..']));
        if (count($entries) === 1) {
            $first = $stagingDir . '/' . $entries[0];
            if (is_dir($first)) {
                // Move everything from $first up into $stagingDir.
                foreach (array_diff(scandir($first) ?: [], ['.', '..']) as $name) {
                    rename($first . '/' . $name, $stagingDir . '/' . $name);
                }
                @rmdir($first);
            }
        }
        return $stagingDir;
    }

    /**
     * Spot-check: a real tylio source bundle must contain at least the
     * application entry-point and the Slim composer autoloader root.
     *
     * History: v0.3.1 wrongly checked `public/index.php` here — but
     * tylio's canonical entry-point is `index.php` at the project root
     * (with `.htaccess` rewriting every URL through it). The `public/`
     * directory only holds static assets. Fixed in v0.3.3. Older v0.3.1
     * installs can still upgrade because v0.3.3+ ships a stub at
     * `public/index.php` that satisfies the old (buggy) check.
     */
    protected function stagingLooksValid(string $stagingDir): bool
    {
        return is_dir($stagingDir . '/app')
            && is_file($stagingDir . '/index.php')
            && is_file($stagingDir . '/composer.json');
    }

    // -------------------------------------------------------------------
    // Backup
    // -------------------------------------------------------------------

    /**
     * Tar up the current install root (excluding the preserve set) into
     * `data/.backup/<oldver>-<ts>.tar.gz`. Returns the absolute path.
     *
     * Uses PharData for zero shell dependency. The backup IS scoped to
     * the swappable bits — keeping `data/`, `uploads/`, `favicons/`,
     * `.env` out keeps the backup small (a fresh install is ~10MB
     * source + ~50MB vendor, vs. potentially gigabytes of uploads).
     */
    protected function backupCurrentRoot(string $root): string
    {
        $backupDir = $root . '/data/.backup';
        if (!is_dir($backupDir)) @mkdir($backupDir, 0750, true);

        $oldVersion = trim(@file_get_contents($root . '/BUILD') ?: '') ?: 'unknown';
        $oldVersion = preg_replace('/[^a-zA-Z0-9.\-]/', '_', $oldVersion) ?? 'unknown';
        $timestamp = gmdate('Ymd-His');
        $tarPath = $backupDir . '/' . $oldVersion . '-' . $timestamp . '.tar';
        $gzPath = $tarPath . '.gz';

        $phar = new \PharData($tarPath);
        // PharData::buildFromIterator with our own filter — we walk
        // ourselves so we can skip the preserve set and follow
        // RecursiveDirectoryIterator's standard ordering.
        $preserve = array_flip(self::PRESERVE);
        $entries = array_values(array_diff(scandir($root) ?: [], ['.', '..']));
        foreach ($entries as $name) {
            if (isset($preserve[$name])) continue;
            // Also skip the backup dir itself (we're writing INTO it).
            if ($name === 'data') continue;
            $absPath = $root . '/' . $name;
            if (is_dir($absPath)) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($absPath, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST,
                );
                /** @var \SplFileInfo $fileInfo */
                foreach ($it as $fileInfo) {
                    $relative = $name . '/' . str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($absPath) + 1));
                    if ($fileInfo->isFile()) {
                        $phar->addFile($fileInfo->getPathname(), $relative);
                    }
                }
            } elseif (is_file($absPath)) {
                $phar->addFile($absPath, $name);
            }
        }
        $phar->compress(\Phar::GZ);
        // PharData::compress writes a new .tar.gz alongside the .tar —
        // delete the uncompressed one.
        unset($phar);
        if (is_file($tarPath)) @unlink($tarPath);
        return $gzPath;
    }

    // -------------------------------------------------------------------
    // Swap
    // -------------------------------------------------------------------

    /**
     * Replace each top-level entry in $root with the matching entry from
     * $stagingDir, EXCEPT the preserve set (which is left untouched on
     * both sides).
     *
     * Strategy per entry:
     *   1. Stage path = $stagingDir/$name  →  if it exists,
     *      rename root/$name to root/.deprecated-$name-<rand>,
     *      then rename $stagingDir/$name to root/$name.
     *   2. After all swaps succeed, recursively delete every
     *      `.deprecated-*` dir.
     *
     * Entries present in root but NOT in staging are LEFT AS IS. (A
     * release ships *the new state*; removed dirs would have to be
     * declared explicitly via a manifest. For 0.x we accept some stale
     * dirs lingering — opcache reset prevents code-execution of stale
     * PHP, and an admin who wants a pristine state can roll back from
     * backup and re-install.)
     */
    protected function swapInPlace(string $stagingDir, string $root): void
    {
        $preserve = array_flip(self::PRESERVE);
        $stagingEntries = array_values(array_diff(scandir($stagingDir) ?: [], ['.', '..']));
        $deprecated = [];

        foreach ($stagingEntries as $name) {
            if (isset($preserve[$name])) continue;
            $rootPath = $root . '/' . $name;
            $stagingPath = $stagingDir . '/' . $name;
            if (file_exists($rootPath)) {
                $depPath = $root . '/.deprecated-' . $name . '-' . bin2hex(random_bytes(3));
                rename($rootPath, $depPath);
                $deprecated[] = $depPath;
            }
            rename($stagingPath, $rootPath);
        }

        // Cleanup deprecated dirs/files (post-swap, so a mid-swap crash
        // leaves recoverable state under .deprecated-*).
        foreach ($deprecated as $p) {
            $this->rmrf($p);
        }
    }

    // -------------------------------------------------------------------
    // Settings + helpers
    // -------------------------------------------------------------------

    protected function isInProgress(): bool
    {
        return $this->getSettingBool('site.update_in_progress');
    }

    protected function getSettingBool(string $key): bool
    {
        $row = $this->db->one('SELECT value FROM settings WHERE key = ? LIMIT 1', [$key]);
        if ($row === null) return false;
        $decoded = json_decode((string)($row['value'] ?? ''), true);
        return (bool)$decoded;
    }

    /** @param mixed $value */
    protected function setSetting(string $key, $value): void
    {
        $this->db->pdo()->prepare(
            "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))"
        )->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Recursive rm -rf. Tolerates symlinks (deletes the symlink itself,
     * not its target — important so we don't accidentally wipe an
     * out-of-tree dir that someone symlinked into /var/www/tylio/).
     */
    protected function rmrf(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        $entries = scandir($path) ?: [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            $this->rmrf($path . '/' . $e);
        }
        @rmdir($path);
    }
}
