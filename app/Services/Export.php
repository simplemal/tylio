<?php
declare(strict_types=1);

namespace Tylio\Services;

use Tylio\Config;
use PharData;
use RuntimeException;

/**
 * Full-site export to a portable `.tar.gz` archive.
 *
 * Archive layout:
 *   meta.json                — { version: TYLIO_BUILD, source: "oss"|"saas",
 *                                exported_at: ISO8601, slug?: string,
 *                                tenant_id?: int, format: 1 }
 *   data.json                — { blocks: [], theme: {}, settings: {},
 *                                media: [] } with JSON-decoded values
 *                                (portable across DB schema variants)
 *   uploads/<filename>       — verbatim copies of every file referenced by
 *                                the media table
 *   favicons/<filename>      — verbatim copies of every favicon file
 *
 * Output is the inverse of `Import::importFrom()` — feeding the same
 * archive into Import on a fresh tylio reproduces the source site (DB
 * rows + media files). For OSS sources the archive is single-tenant;
 * for SaaS sources the platform overlay (TenantExportService) writes
 * `slug` + `tenant_id` into meta.json so the import side can rebuild
 * tenant-scoped paths.
 *
 * **Extendable by design.** Non-`final` and exposes `$db` / `$config`
 * as `protected`; the SaaS overlay (`TenantExportService`) overrides
 * the query methods + path helpers to scope by `tenant_id` and read
 * media from `public/uploads/<slug>/` instead of `uploads/`.
 */
class Export
{
    /**
     * Format version of the archive. Bumped if the on-disk layout
     * (meta.json / data.json schema) changes in a non-backwards
     * compatible way. The importer rejects archives with a future
     * format version it doesn't understand.
     */
    public const FORMAT_VERSION = 1;

    public function __construct(
        protected DB $db,
        protected Config $config,
    ) {}

    /**
     * Build the archive on disk and return the path. The caller is
     * responsible for streaming it to the client and unlinking it.
     *
     * @return string Absolute path of the .tar.gz file
     */
    public function build(): string
    {
        $tmpRoot = sys_get_temp_dir() . '/tylio-export-' . date('Ymd-His')
            . '-' . bin2hex(random_bytes(3));
        if (!@mkdir($tmpRoot, 0700, true)) {
            throw new RuntimeException('Cannot create temp dir ' . $tmpRoot);
        }
        try {
            $this->writeMeta($tmpRoot . '/meta.json');
            $this->writeData($tmpRoot . '/data.json');
            $this->copyUploads($tmpRoot . '/uploads');
            $this->copyFavicons($tmpRoot . '/favicons');

            $tarPath = $tmpRoot . '.tar';
            $tar = new PharData($tarPath);
            $tar->buildFromDirectory($tmpRoot);
            $tar->compress(\Phar::GZ);
            unset($tar);
            @unlink($tarPath);
            $gzPath = $tarPath . '.gz';
            if (!is_file($gzPath)) {
                throw new RuntimeException('tar.gz not produced');
            }
            return $gzPath;
        } finally {
            $this->rmTree($tmpRoot);
        }
    }

    // ------------------- meta.json -------------------

    /**
     * Override in subclasses to add `slug` / `tenant_id` / change `source`.
     * @return array<string,mixed>
     */
    protected function metaPayload(): array
    {
        return [
            'format' => self::FORMAT_VERSION,
            'version' => defined('TYLIO_BUILD') ? (string)TYLIO_BUILD : 'dev',
            'source' => 'oss',
            'exported_at' => date('c'),
        ];
    }

    private function writeMeta(string $path): void
    {
        $json = json_encode($this->metaPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('meta.json json_encode failed');
        }
        if (@file_put_contents($path, $json) === false) {
            throw new RuntimeException('cannot write ' . $path);
        }
    }

    // ------------------- data.json -------------------

    private function writeData(string $path): void
    {
        $data = [
            'blocks' => $this->exportBlocks(),
            'theme' => $this->exportTheme(),
            'settings' => $this->exportSettings(),
            'media' => $this->exportMedia(),
            'users' => $this->exportUsers(),
        ];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('data.json json_encode failed');
        }
        if (@file_put_contents($path, $json) === false) {
            throw new RuntimeException('cannot write ' . $path);
        }
    }

    /** @return list<array<string,mixed>> */
    protected function exportBlocks(): array
    {
        $rows = $this->db->all(
            'SELECT id, type, position, enabled, data, style, parent_id, created_at, updated_at
             FROM blocks ORDER BY (parent_id IS NOT NULL), parent_id, position ASC, id ASC'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'type' => (string)$r['type'],
                'position' => (int)$r['position'],
                'enabled' => (int)$r['enabled'],
                'data' => $this->decodeJson($r['data'] ?? '{}'),
                'style' => $this->decodeJson($r['style'] ?? '{}'),
                'parent_id' => isset($r['parent_id']) ? (int)$r['parent_id'] : null,
                'created_at' => (string)($r['created_at'] ?? ''),
                'updated_at' => (string)($r['updated_at'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    protected function exportUsers(): array
    {
        $rows = $this->db->all(
            'SELECT id, username, password_hash, totp_secret, totp_enabled_at, totp_backup_codes,
                    created_at, last_login_at
             FROM users ORDER BY id ASC'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'username' => (string)$r['username'],
                'password_hash' => (string)$r['password_hash'],
                'totp_secret' => isset($r['totp_secret']) ? (string)$r['totp_secret'] : '',
                'totp_enabled_at' => isset($r['totp_enabled_at']) ? (string)$r['totp_enabled_at'] : null,
                'totp_backup_codes' => isset($r['totp_backup_codes']) ? (string)$r['totp_backup_codes'] : '[]',
                'created_at' => (string)($r['created_at'] ?? ''),
                'last_login_at' => isset($r['last_login_at']) ? (string)$r['last_login_at'] : null,
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    protected function exportTheme(): array
    {
        // Read the single theme row. Tolerant of both the OSS schema
        // (`id INTEGER PRIMARY KEY CHECK(id=1)`) and the SaaS schema
        // (`tenant_id INTEGER PRIMARY KEY`). Subclasses scope by
        // tenant_id; here we just take the first row.
        $row = $this->db->one('SELECT data, updated_at FROM theme LIMIT 1');
        if (!$row) return [];
        return [
            'data' => $this->decodeJson($row['data'] ?? '{}'),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    protected function exportSettings(): array
    {
        $rows = $this->db->all('SELECT key, value FROM settings');
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['key']] = $this->decodeJson($r['value'] ?? '""');
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    protected function exportMedia(): array
    {
        $rows = $this->db->all(
            'SELECT filename, original_name, mime, size, width, height, created_at
             FROM media ORDER BY id ASC'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'filename' => (string)$r['filename'],
                'original_name' => (string)($r['original_name'] ?? ''),
                'mime' => (string)($r['mime'] ?? ''),
                'size' => (int)($r['size'] ?? 0),
                'width' => isset($r['width']) ? (int)$r['width'] : null,
                'height' => isset($r['height']) ? (int)$r['height'] : null,
                'created_at' => (string)($r['created_at'] ?? ''),
            ];
        }
        return $out;
    }

    // ------------------- media copy -------------------

    /**
     * Filesystem path of the directory where media files live.
     * OSS: `<root>/uploads/`. SaaS overrides to `public/uploads/<slug>/`.
     */
    protected function uploadsPath(): string
    {
        return $this->config->path('uploads');
    }

    /**
     * Filesystem path of the directory where favicon files live.
     * OSS: `<root>/favicons/`. SaaS overrides to `public/favicons/<slug>/`.
     */
    protected function faviconsPath(): string
    {
        return $this->config->path('favicons');
    }

    private function copyUploads(string $destDir): void
    {
        $srcDir = $this->uploadsPath();
        if (!is_dir($srcDir)) return;
        if (!@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new RuntimeException('cannot create ' . $destDir);
        }
        // Only copy files referenced by the media table — anything else
        // (orphan uploads from deleted media rows) is junk we don't want
        // to bring along. Subclasses scope this list to a tenant.
        foreach ($this->mediaFilenames() as $rawName) {
            $fn = basename($rawName);
            if ($fn === '' || $fn === '.' || $fn === '..') continue;
            $src = $srcDir . '/' . $fn;
            if (!is_file($src)) continue;
            @copy($src, $destDir . '/' . $fn);
        }
    }

    /**
     * Filenames recorded in the media table. Subclasses (TenantExport)
     * override to filter by `tenant_id`.
     *
     * @return list<string>
     */
    protected function mediaFilenames(): array
    {
        $rows = $this->db->all('SELECT filename FROM media');
        $out = [];
        foreach ($rows as $r) {
            $out[] = (string)$r['filename'];
        }
        return $out;
    }

    private function copyFavicons(string $destDir): void
    {
        $srcDir = $this->faviconsPath();
        if (!is_dir($srcDir)) return;
        if (!@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new RuntimeException('cannot create ' . $destDir);
        }
        // Copy every top-level file in the favicons dir verbatim. Unlike
        // uploads, favicon files aren't indexed in the DB — the names are
        // computed from the user's upload (favicon.ico / favicon-16x16.png
        // / apple-touch-icon.png / favicon.svg etc.) — so we just take
        // everything that's there.
        $entries = @scandir($srcDir);
        if ($entries === false) return;
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            $src = $srcDir . '/' . $e;
            if (!is_file($src)) continue;
            @copy($src, $destDir . '/' . $e);
        }
    }

    // ------------------- helpers -------------------

    /**
     * Decode a JSON string. Returns the raw string if it doesn't parse
     * (e.g. a settings value that was stored as a quoted string rather
     * than a JSON-encoded one). Returning the raw fallback is safer for
     * portability — the importer always JSON-encodes back before INSERT.
     */
    protected function decodeJson(string $raw): mixed
    {
        if ($raw === '') return null;
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        return $raw;
    }

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
