<?php
declare(strict_types=1);

namespace Tylio\Services;

use Tylio\Config;
use PharData;
use RuntimeException;

/**
 * Inverse of `Export`: ingest a `.tar.gz` produced by /admin/export
 * (OSS or SaaS) and restore the contained blocks/theme/settings/media
 * into the current installation.
 *
 * The importer is robust against schema drift between OSS and SaaS:
 *
 *  - **Theme table**: SaaS's `1000_multitenant.sql` rebuilds `theme`
 *    with `tenant_id INTEGER PRIMARY KEY`. INTEGER PRIMARY KEY in
 *    SQLite is a ROWID alias → every `INSERT OR REPLACE` that doesn't
 *    specify `tenant_id` auto-increments instead of overwriting. We
 *    rebuild the table with the OSS schema (`id INTEGER PRIMARY KEY
 *    CHECK(id=1)`) so the OSS controllers can keep writing
 *    `INSERT OR REPLACE INTO theme (id, ...)` without surprises.
 *  - **Multi-tenant rows**: any pre-existing row with `tenant_id != 1`
 *    is dropped before we insert the archive's payload.
 *  - **Media paths**: SaaS exports reference `/uploads/<slug>/foo.png`;
 *    OSS expects flat `/uploads/foo.png`. We walk every JSON-encoded
 *    blob (blocks.data, blocks.style, theme.data, settings.value) and
 *    strip the `/uploads/<slug>/` and `/favicons/<slug>/` prefixes.
 *    Both unescaped (`/x/y/`) and JSON-escaped (`\/x\/y\/`) forms are
 *    covered — the SaaS exporter writes the unescaped form, but a
 *    well-meaning user might have edited the JSON manually.
 *
 * The whole import runs in a single transaction. Any error rolls back
 * and bubbles up — `import.log` records every step.
 *
 * **Extendable by design.** Non-`final`; the SaaS overlay overrides
 * `insertTheme()` / `insertSettings()` / `insertBlocks()` /
 * `mediaTargetDir()` to upsert into a specific `tenant_id` and write
 * uploads under `public/uploads/<slug>/`.
 */
class Import
{
    /** Highest archive format version this importer understands. */
    public const SUPPORTED_FORMAT = 1;

    /**
     * Max size of the uploaded archive (bytes). 100 MB by default —
     * tylio installations rarely exceed a few MB of media.
     */
    public const MAX_ARCHIVE_BYTES = 100 * 1024 * 1024;

    /** @var resource|null */
    private $logHandle = null;

    public function __construct(
        protected DB $db,
        protected Config $config,
    ) {}

    /**
     * Apply the archive at `$archivePath` to the current installation.
     *
     * @return array<string,mixed>  Summary (counts of imported rows).
     */
    public function importFrom(string $archivePath): array
    {
        $this->openLog();
        $this->log('import.start archive=' . basename($archivePath));

        $extractDir = $this->extractArchive($archivePath);
        try {
            $meta = $this->readMeta($extractDir . '/meta.json');
            $this->log('import.meta source=' . ($meta['source'] ?? '?')
                . ' format=' . ($meta['format'] ?? '?')
                . ' version=' . ($meta['version'] ?? '?')
                . ' slug=' . ($meta['slug'] ?? '-')
            );
            $this->assertFormatSupported($meta);

            $data = $this->readData($extractDir . '/data.json');

            // Schema normalization runs OUTSIDE the transaction because
            // SQLite can't apply a CREATE/DROP TABLE inside an explicit
            // transaction that's started without that DDL being its
            // first statement under some pragmas. Keep DDL up front,
            // DML inside the transaction.
            $this->normalizeSchema();

            // The source slug is needed BEFORE the transaction so we can
            // rewrite slug-scoped media paths inside data.json.
            $sourceSlug = isset($meta['slug']) ? (string)$meta['slug'] : '';
            if ($sourceSlug !== '') {
                $data = $this->rewriteMediaPaths($data, $sourceSlug);
            }

            $counts = $this->db->transaction(function () use ($data) {
                $blocksN = $this->insertBlocks($data['blocks'] ?? []);
                $this->insertTheme($data['theme'] ?? []);
                $settingsN = $this->insertSettings($data['settings'] ?? []);
                $mediaN = $this->insertMedia($data['media'] ?? []);
                $usersN = $this->insertUsers($data['users'] ?? []);
                return [
                    'blocks' => $blocksN,
                    'settings' => $settingsN,
                    'media' => $mediaN,
                    'users' => $usersN,
                ];
            });

            // Filesystem copies AFTER the DB transaction commits — if a
            // file copy fails we're left with consistent DB rows pointing
            // at the source files (which we don't have), but the alternative
            // (rolling back the DB on a partial copy) is worse because
            // tar.gz extraction has already happened.
            $uploadsN = $this->copyUploads($extractDir . '/uploads');
            $faviconsN = $this->copyFavicons($extractDir . '/favicons');

            $summary = $counts + ['uploads' => $uploadsN, 'favicons' => $faviconsN];
            $this->log('import.done ' . json_encode($summary));
            return $summary;
        } finally {
            $this->rmTree($extractDir);
            $this->closeLog();
        }
    }

    // ------------------- archive extraction -------------------

    /**
     * @return string Absolute path of the extracted directory (cleaned by caller).
     */
    private function extractArchive(string $archivePath): string
    {
        if (!is_file($archivePath)) {
            throw new RuntimeException('archive not found: ' . $archivePath);
        }
        $size = (int)filesize($archivePath);
        if ($size > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('archive too large: ' . $size . ' > ' . self::MAX_ARCHIVE_BYTES);
        }
        $extractDir = sys_get_temp_dir() . '/tylio-import-' . date('Ymd-His')
            . '-' . bin2hex(random_bytes(3));
        if (!@mkdir($extractDir, 0700, true)) {
            throw new RuntimeException('cannot create ' . $extractDir);
        }
        try {
            // PharData wants a "real" .tar.gz extension to detect compression.
            // If the upload moved the file to a tmp path without extension,
            // alias it so PharData knows to treat it as a tarball.
            $aliased = $extractDir . '.in.tar.gz';
            if (!@copy($archivePath, $aliased)) {
                throw new RuntimeException('cannot stage archive');
            }
            $tar = new PharData($aliased);
            $tar->extractTo($extractDir, null, true);
            unset($tar);
            @unlink($aliased);
        } catch (\Throwable $e) {
            $this->rmTree($extractDir);
            throw new RuntimeException('archive extraction failed: ' . $e->getMessage(), 0, $e);
        }
        return $extractDir;
    }

    /** @return array<string,mixed> */
    private function readMeta(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('meta.json missing');
        }
        $json = (string)file_get_contents($path);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('meta.json invalid');
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function readData(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('data.json missing');
        }
        $json = (string)file_get_contents($path);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('data.json invalid');
        }
        return $decoded;
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function assertFormatSupported(array $meta): void
    {
        $format = isset($meta['format']) ? (int)$meta['format'] : 1;
        if ($format > self::SUPPORTED_FORMAT) {
            throw new RuntimeException(
                'archive format ' . $format . ' is newer than this importer (max '
                . self::SUPPORTED_FORMAT . '). Upgrade tylio and retry.'
            );
        }
    }

    // ------------------- schema normalization (OSS target) -------------------

    /**
     * Bring the local DB into a shape the OSS controllers expect.
     * No-op on a vanilla OSS install; on a DB migrated from SaaS this:
     *   - rebuilds `theme` with the OSS PK schema
     *   - drops rows with `tenant_id != 1` from every multi-tenant table
     *
     * Override in subclasses to skip these rewrites (SaaS target keeps
     * its multi-tenant schema intact).
     */
    protected function normalizeSchema(): void
    {
        $this->normalizeThemeTable();
        $this->dropOtherTenantRows();
    }

    /**
     * Rebuild the `theme` table with the OSS schema:
     *   id INTEGER PRIMARY KEY CHECK (id=1), data TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT (datetime('now'))
     *
     * Keeps the latest row's data; ignores everything else. Idempotent:
     * if the table already has the right shape, the method returns
     * without touching anything.
     */
    private function normalizeThemeTable(): void
    {
        $cols = $this->db->all("PRAGMA table_info(theme)");
        if (empty($cols)) return;
        $hasIdPk = false;
        $tenantPk = false;
        foreach ($cols as $c) {
            if ((string)$c['name'] === 'id' && (int)($c['pk'] ?? 0) === 1) $hasIdPk = true;
            if ((string)$c['name'] === 'tenant_id' && (int)($c['pk'] ?? 0) === 1) $tenantPk = true;
        }
        if ($hasIdPk && !$tenantPk) {
            $this->log('schema.theme already-oss skipped');
            return;
        }
        $this->log('schema.theme normalize-from-saas');

        $row = $this->db->one('SELECT data, updated_at FROM theme ORDER BY updated_at DESC LIMIT 1');
        $pdo = $this->db->pdo();
        $pdo->exec('DROP TABLE IF EXISTS theme_backup_oldsaas');
        $pdo->exec('ALTER TABLE theme RENAME TO theme_backup_oldsaas');
        $pdo->exec(
            "CREATE TABLE theme (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                data TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )"
        );
        if ($row !== null) {
            $stmt = $pdo->prepare(
                "INSERT INTO theme (id, data, updated_at) VALUES (1, :data, :updated_at)"
            );
            $stmt->execute([
                'data' => (string)$row['data'],
                'updated_at' => (string)($row['updated_at'] ?? date('Y-m-d H:i:s')),
            ]);
        }
        $pdo->exec('DROP TABLE theme_backup_oldsaas');
    }

    /**
     * Delete every row whose `tenant_id` isn't 1. Only runs on tables
     * that have a `tenant_id` column (SaaS-migrated DB); on a pure
     * OSS DB this is a no-op.
     */
    private function dropOtherTenantRows(): void
    {
        $tables = ['blocks', 'media', 'submissions', 'visits', 'audit_log', 'login_attempts', 'users', 'settings'];
        foreach ($tables as $table) {
            if (!$this->hasColumn($table, 'tenant_id')) continue;
            $count = $this->db->exec("DELETE FROM $table WHERE tenant_id != 1");
            if ($count > 0) {
                $this->log("schema.purge $table tenant_id!=1 rows=$count");
            }
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $cols = $this->db->all("PRAGMA table_info($table)");
        foreach ($cols as $c) {
            if ((string)$c['name'] === $column) return true;
        }
        return false;
    }

    // ------------------- inserts (OSS target) -------------------

    /**
     * @param list<array<string,mixed>> $blocks
     * @return int count inserted
     */
    protected function insertBlocks(array $blocks): int
    {
        $this->db->exec('DELETE FROM blocks');
        $n = 0;
        $hasIdAndParent = false;
        foreach ($blocks as $b) {
            $sourceId = isset($b['id']) ? (int)$b['id'] : 0;
            $row = [
                'type' => (string)($b['type'] ?? ''),
                'position' => (int)($b['position'] ?? 0),
                'enabled' => (int)($b['enabled'] ?? 1),
                'data' => json_encode($b['data'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
                'style' => json_encode($b['style'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
                'created_at' => (string)($b['created_at'] ?? date('Y-m-d H:i:s')),
                'updated_at' => (string)($b['updated_at'] ?? date('Y-m-d H:i:s')),
            ];
            if ($sourceId > 0) {
                $row['id'] = $sourceId;
                $hasIdAndParent = true;
            }
            $this->db->insert('blocks', $row);
            $n++;
        }
        if ($hasIdAndParent) {
            foreach ($blocks as $b) {
                $sourceId = isset($b['id']) ? (int)$b['id'] : 0;
                $parentId = isset($b['parent_id']) ? (int)$b['parent_id'] : 0;
                if ($sourceId > 0 && $parentId > 0) {
                    $this->db->query(
                        'UPDATE blocks SET parent_id = ? WHERE id = ?',
                        [$parentId, $sourceId],
                    );
                }
            }
        }
        $this->log("insert.blocks n=$n");
        return $n;
    }

    /**
     * @param array<string,mixed> $theme
     */
    protected function insertTheme(array $theme): void
    {
        if (empty($theme) || !isset($theme['data'])) return;
        $json = json_encode($theme['data'], JSON_UNESCAPED_UNICODE);
        $this->db->pdo()->prepare(
            "INSERT OR REPLACE INTO theme (id, data, updated_at) VALUES (1, :data, :updated_at)"
        )->execute([
            'data' => (string)$json,
            'updated_at' => (string)($theme['updated_at'] ?? date('Y-m-d H:i:s')),
        ]);
        $this->log('insert.theme ok');
    }

    /**
     * @param array<string,mixed> $settings
     * @return int count inserted
     */
    protected function insertSettings(array $settings): int
    {
        $n = 0;
        $stmt = $this->db->pdo()->prepare(
            "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (:key, :value, datetime('now'))"
        );
        foreach ($settings as $k => $v) {
            $stmt->execute([
                'key' => (string)$k,
                'value' => (string)json_encode($v, JSON_UNESCAPED_UNICODE),
            ]);
            $n++;
        }
        $this->log("insert.settings n=$n");
        return $n;
    }

    /**
     * @param list<array<string,mixed>> $media
     * @return int count inserted
     */
    protected function insertMedia(array $media): int
    {
        // Drop existing media rows first to avoid filename collisions
        // (the archive carries the canonical set of media for the site).
        $this->db->exec('DELETE FROM media');
        $n = 0;
        foreach ($media as $m) {
            $this->db->insert('media', [
                'filename' => (string)($m['filename'] ?? ''),
                'original_name' => (string)($m['original_name'] ?? ''),
                'mime' => (string)($m['mime'] ?? ''),
                'size' => (int)($m['size'] ?? 0),
                'width' => isset($m['width']) ? (int)$m['width'] : null,
                'height' => isset($m['height']) ? (int)$m['height'] : null,
                'created_at' => (string)($m['created_at'] ?? date('Y-m-d H:i:s')),
            ]);
            $n++;
        }
        $this->log("insert.media n=$n");
        return $n;
    }

    /**
     * Replace the users table with the archive's payload. Critical: an
     * archive without users => login impossible after import. Each row
     * carries the argon2id password_hash + TOTP fields (if 2FA was
     * enabled). The ID is preserved so any future referential link
     * (audit_log.user_id, ecc.) stays consistent.
     *
     * @param list<array<string,mixed>> $users
     */
    protected function insertUsers(array $users): int
    {
        if ($users === []) return 0;
        $this->db->exec('DELETE FROM users');
        $n = 0;
        foreach ($users as $u) {
            $username = (string)($u['username'] ?? '');
            $hash = (string)($u['password_hash'] ?? '');
            if ($username === '' || $hash === '') continue;
            $row = [
                'username' => $username,
                'password_hash' => $hash,
                'totp_secret' => (string)($u['totp_secret'] ?? ''),
                'totp_enabled_at' => $u['totp_enabled_at'] ?? null,
                'totp_backup_codes' => (string)($u['totp_backup_codes'] ?? '[]'),
                'created_at' => (string)($u['created_at'] ?? date('Y-m-d H:i:s')),
                'last_login_at' => $u['last_login_at'] ?? null,
            ];
            $sourceId = isset($u['id']) ? (int)$u['id'] : 0;
            if ($sourceId > 0) $row['id'] = $sourceId;
            $this->db->insert('users', $row);
            $n++;
        }
        $this->log("insert.users n=$n");
        return $n;
    }

    // ------------------- media path rewriting -------------------

    /**
     * Strip the `/uploads/<slug>/` and `/favicons/<slug>/` prefixes
     * from every string in blocks/theme/settings — covering both the
     * unescaped form and the JSON-escaped form (the JSON we just
     * decoded from data.json may have come from a CMS that wrote the
     * escaped form into the raw column).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function rewriteMediaPaths(array $data, string $slug): array
    {
        $slug = preg_quote($slug, '#');
        $replacements = [
            "#/uploads/$slug/#" => '/uploads/',
            "#/favicons/$slug/#" => '/favicons/',
            // JSON-escaped forms
            "#\\\\/uploads\\\\/$slug\\\\/#" => '\\/uploads\\/',
            "#\\\\/favicons\\\\/$slug\\\\/#" => '\\/favicons\\/',
        ];
        $rewrite = function (string $s) use ($replacements): string {
            return (string)preg_replace(array_keys($replacements), array_values($replacements), $s);
        };
        // Walk blocks
        if (isset($data['blocks']) && is_array($data['blocks'])) {
            foreach ($data['blocks'] as &$b) {
                $b['data'] = $this->walkStrings($b['data'] ?? null, $rewrite);
                $b['style'] = $this->walkStrings($b['style'] ?? null, $rewrite);
            }
            unset($b);
        }
        if (isset($data['theme']['data'])) {
            $data['theme']['data'] = $this->walkStrings($data['theme']['data'], $rewrite);
        }
        if (isset($data['settings']) && is_array($data['settings'])) {
            foreach ($data['settings'] as $k => $v) {
                $data['settings'][$k] = $this->walkStrings($v, $rewrite);
            }
        }
        $this->log('rewrite.media slug=' . $slug);
        return $data;
    }

    /**
     * Recursively apply `$rewrite` to every string in `$value`.
     */
    private function walkStrings(mixed $value, callable $rewrite): mixed
    {
        if (is_string($value)) {
            return $rewrite($value);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->walkStrings($v, $rewrite);
            }
            return $value;
        }
        return $value;
    }

    // ------------------- file copy -------------------

    /**
     * Target dir for `uploads/` extracted from the archive.
     * OSS: `<root>/uploads/`. SaaS overrides to `public/uploads/<slug>/`.
     */
    protected function uploadsTargetDir(): string
    {
        return $this->config->path('uploads');
    }

    /**
     * Target dir for `favicons/` extracted from the archive.
     * OSS: `<root>/favicons/`. SaaS overrides to `public/favicons/<slug>/`.
     */
    protected function faviconsTargetDir(): string
    {
        return $this->config->path('favicons');
    }

    private function copyUploads(string $srcDir): int
    {
        return $this->copyDir($srcDir, $this->uploadsTargetDir());
    }

    private function copyFavicons(string $srcDir): int
    {
        return $this->copyDir($srcDir, $this->faviconsTargetDir());
    }

    private function copyDir(string $srcDir, string $destDir): int
    {
        if (!is_dir($srcDir)) return 0;
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }
        if (!is_dir($destDir)) {
            throw new RuntimeException('cannot create dest dir ' . $destDir);
        }
        $entries = scandir($srcDir);
        if ($entries === false) return 0;
        $n = 0;
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            $src = $srcDir . '/' . $e;
            if (!is_file($src)) continue;
            $dest = $destDir . '/' . basename($e);
            if (!@copy($src, $dest)) {
                $this->log("copy.fail $src -> $dest");
                continue;
            }
            // Make sure www-data can read the new files. If we run as
            // root (via /install/import on a fresh server), chown so
            // the web user owns its uploads.
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                $info = @posix_getpwnam('www-data');
                if ($info !== false) {
                    @chown($dest, (int)$info['uid']);
                    @chgrp($dest, (int)$info['gid']);
                }
            }
            @chmod($dest, 0664);
            $n++;
        }
        return $n;
    }

    // ------------------- logging -------------------

    private function openLog(): void
    {
        $dir = $this->config->path('data/logs');
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        $h = @fopen($dir . '/import.log', 'ab');
        $this->logHandle = ($h === false) ? null : $h;
    }

    private function log(string $msg): void
    {
        if ($this->logHandle === null) return;
        @fwrite($this->logHandle, '[' . date('c') . '] ' . $msg . "\n");
    }

    private function closeLog(): void
    {
        if ($this->logHandle !== null) {
            @fclose($this->logHandle);
            $this->logHandle = null;
        }
    }

    // ------------------- helpers -------------------

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
