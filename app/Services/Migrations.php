<?php
declare(strict_types=1);

namespace Tylio\Services;

use Tylio\Config;

/**
 * Migration runner for tylio.
 *
 * ## Conventions
 *
 * - Files in `app/Database/migrations/`, named `NNNN_description.{sql,php}`
 *   (zero-padded 4-digit numeric prefix).
 * - **Order**: alphanumeric. `0001` → `0002` → … and so on.
 * - **An applied migration MUST NOT BE EDITED**: always add a new migration
 *   after it. Editing a migration that has already been applied elsewhere
 *   (CI, other developers, production) causes schema drift.
 * - **Idempotency**: each migration should be idempotent when possible
 *   (`CREATE TABLE IF NOT EXISTS`, `INSERT OR IGNORE`, etc.). The runner
 *   tracks applied migrations in the `migrations` table anyway, but
 *   idempotent SQL makes recovery from partial failures easier.
 *
 * ## Supported formats
 *
 * **SQL** (`*.sql`): plain SQL executed in a single exec().
 *   Good for DDL (CREATE/ALTER) and simple seed data.
 *
 * **PHP** (`*.php`): a file returning `function (PDO $pdo, DB $db): void`.
 *   Used for complex data migrations (e.g. recompute a column by reading
 *   existing rows, JSON roundtrip, etc.).
 *
 *   ```php
 *   <?php
 *   return function (PDO $pdo, DB $db): void {
 *       foreach ($db->all('SELECT id, data FROM blocks') as $row) {
 *           $data = json_decode((string)$row['data'], true) ?: [];
 *           // ... transform ...
 *           $db->update('blocks', ['data' => json_encode($data)],
 *                       'id = :id', ['id' => $row['id']]);
 *       }
 *   };
 *   ```
 *
 * ## Atomicity
 *
 * Each migration runs inside a transaction. On failure the tracking row in
 * `migrations` is not inserted, so the next run will retry. SQLite supports
 * transactional DDL since 3.0.
 */
final class Migrations
{
    /**
     * @param list<string> $extraDirs absolute paths of extra folders to scan
     *   (for forks that overlay tylio with their own migrations). Files from
     *   ALL folders are merged and sorted by name — convention: use
     *   zero-padded numeric prefixes (0001, 0002, …) so the order stays
     *   deterministic.
     */
    public function __construct(
        private DB $db,
        private Config $config,
        private array $extraDirs = [],
    ) {}

    /**
     * Apply all pending migrations. Returns the names of those applied in
     * this run. Idempotent: calling it twice in a row reapplies nothing.
     *
     * **Fast path.** Migrations are run on every HTTP request (the
     * default bootstrap calls `$migrations->run()`), but in the steady
     * state the work is zero — `pending()` does a `glob()` + a
     * `SELECT name FROM migrations` + a set diff. To save the few ms
     * those two I/O calls cost, we cache a fingerprint of the migration
     * files (`name|mtime|name|mtime…`) in `data/.migrations-stamp`. As
     * long as no file has been added/touched since the last successful
     * run, we skip the DB roundtrip entirely. The stamp is invalidated
     * automatically when:
     *   - a new migration file appears (its name joins the fingerprint),
     *   - an existing file's mtime changes (e.g. composer update pulled
     *     a new tylio/core release).
     * The DB-tracking table (`migrations`) remains the source of truth;
     * the stamp is a pure perf optimization that can be deleted at any
     * time without breaking correctness.
     *
     * @return list<string>
     */
    public function run(): array
    {
        $fingerprint = $this->computeFingerprint();
        if ($fingerprint !== '' && $this->fingerprintMatchesStamp($fingerprint) && $this->dbHasMigrationsTable()) {
            return [];
        }
        $this->ensureMigrationsTable();
        $applied = [];
        foreach ($this->pending() as $name => $file) {
            $this->db->transaction(function () use ($file, $name) {
                $this->execMigration($file);
                $this->db->insert('migrations', ['name' => $name]);
            });
            $applied[] = $name;
        }
        if ($fingerprint !== '') $this->writeStamp($fingerprint);
        return $applied;
    }

    /**
     * Build a deterministic fingerprint from the discovered migration
     * files. Returns '' if there's no discoverable file (no fast path).
     */
    private function computeFingerprint(): string
    {
        $files = $this->scanMigrationFiles();
        if ($files === []) return '';
        $parts = [];
        foreach ($files as $name => $path) {
            $parts[] = $name . '|' . (string)@filemtime($path);
        }
        return hash('sha256', implode("\n", $parts));
    }

    private function stampPath(): string
    {
        return $this->config->path('data/.migrations-stamp');
    }

    private function fingerprintMatchesStamp(string $fingerprint): bool
    {
        $stamp = $this->stampPath();
        if (!is_file($stamp)) return false;
        return trim((string)@file_get_contents($stamp)) === $fingerprint;
    }

    /**
     * Guard against a stale stamp: if someone wiped `db.sqlite` but
     * left `data/.migrations-stamp` around (e.g. recovery from a bad
     * import), the fingerprint would still match the files on disk
     * and the fast-path would silently skip — leaving the new DB with
     * zero tables. Re-running everything when the marker table is
     * missing is cheap (idempotent migrations) and saves an hour of
     * "no such table" debugging.
     */
    private function dbHasMigrationsTable(): bool
    {
        try {
            $row = $this->db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations' LIMIT 1");
            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function writeStamp(string $fingerprint): void
    {
        $stamp = $this->stampPath();
        $dir = dirname($stamp);
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        @file_put_contents($stamp, $fingerprint, LOCK_EX);
    }

    /**
     * @return array{applied: list<array{name:string,applied_at:string}>, pending: list<string>}
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();
        /** @var list<array{name:string,applied_at:string}> $appliedRows */
        $appliedRows = $this->db->all('SELECT name, applied_at FROM migrations ORDER BY name ASC');
        $pending = array_keys($this->pending());
        return ['applied' => $appliedRows, 'pending' => $pending];
    }

    /**
     * Migrations found on disk but NOT yet applied, mapping name → path.
     * Preserves the alphanumeric order produced by ksort.
     *
     * @return array<string,string>
     */
    public function pending(): array
    {
        $this->ensureMigrationsTable();
        $files = $this->scanMigrationFiles();
        /** @var list<array<string,mixed>> $appliedRows */
        $appliedRows = $this->db->all('SELECT name FROM migrations');
        $applied = [];
        foreach ($appliedRows as $r) $applied[(string)$r['name']] = true;
        $out = [];
        foreach ($files as $name => $path) {
            if (!isset($applied[$name])) $out[$name] = $path;
        }
        return $out;
    }

    /**
     * Scan migrations folders (default + extraDirs), returning files sorted
     * by name. Accepts `.sql` and `.php`.
     *
     * @return array<string,string> map basename → absolute path
     */
    private function scanMigrationFiles(): array
    {
        $dirs = array_merge(
            [$this->config->path('app/Database/migrations')],
            $this->extraDirs,
        );
        $files = [];
        foreach ($dirs as $dir) {
            foreach ((glob($dir . '/*.{sql,php}', GLOB_BRACE) ?: []) as $f) {
                $files[basename($f)] = $f;
            }
        }
        ksort($files);
        return $files;
    }

    /**
     * Run a single migration on the DB. Routed by extension:
     *   .sql → PDO::exec of the file contents
     *   .php → require $file (must `return function (PDO, DB): void`)
     */
    private function execMigration(string $file): void
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'sql') {
            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') return;
            $this->db->pdo()->exec($sql);
            return;
        }
        if ($ext === 'php') {
            /** @var callable|mixed $up */
            $up = require $file;
            if (!is_callable($up)) {
                throw new \RuntimeException("Migration $file did not return a callable closure");
            }
            $up($this->db->pdo(), $this->db);
            return;
        }
        throw new \RuntimeException("Migration $file: unsupported extension ($ext)");
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS migrations (
            name TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');
    }
}
