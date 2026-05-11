<?php
declare(strict_types=1);

namespace Tylio\Util;

/**
 * Build version used as a cache-buster for assets (local CSS/JS/SVG/img).
 *
 * The version is read from the `BUILD` file in the project root:
 *   /var/www/tylio/BUILD          (SaaS)
 *   /path/to/oss-clone/BUILD      (OSS)
 *
 * File contents: a single string, e.g. `2026-05-09-001` or a commit hash.
 * If the file is missing, falls back to `dev`. To regenerate it:
 *
 *     php -r 'file_put_contents("BUILD", date("Y-m-d-His"));'
 *
 * Templates: use the global constant `TYLIO_BUILD` defined by the bootstrap.
 *   <link href="/platform.css?v=<?= TYLIO_BUILD ?>">
 *
 * This file also exposes the same info via a static method for callers that
 * don't want to depend on the global define.
 */
final class Build
{
    private static ?string $cached = null;
    private static ?string $rootCached = null;

    /**
     * Initialize the value (called from bootstrap).
     *
     * Looks for the build number, in priority order:
     *   1. `{rootPath}/data/version`     ← writable by www-data (bumped from web/dashboard)
     *   2. `{rootPath}/.version`         ← canonical (root-level dotfile, CLI bump)
     *   3. `{rootPath}/scripts/.version` ← alternative if you prefer no-sudo
     *   4. `{rootPath}/scripts/BUILD`    ← legacy
     *   5. `{rootPath}/BUILD`            ← legacy
     *
     * `data/version` always wins if it exists: a bump from the dashboard
     * takes effect immediately without touching root-owned files.
     *
     * If no file exists or all are empty, returns the string `dev`.
     */
    public static function init(string $rootPath): string
    {
        if (self::$cached !== null) return self::$cached;
        $root = rtrim($rootPath, '/');
        self::$rootCached = $root;
        foreach (self::candidatePaths($root) as $f) {
            if (is_file($f)) {
                $v = trim((string)file_get_contents($f));
                if ($v !== '') {
                    self::$cached = $v;
                    if (!defined('TYLIO_BUILD')) define('TYLIO_BUILD', $v);
                    return $v;
                }
            }
        }
        self::$cached = 'dev';
        if (!defined('TYLIO_BUILD')) define('TYLIO_BUILD', 'dev');
        return 'dev';
    }

    /**
     * Increment the build number by writing to `{rootPath}/data/version`.
     * Intended to be called from a dashboard "bump build" endpoint.
     *
     * - If the current value is an integer, increment it by 1.
     * - Otherwise (e.g. "dev" or a timestamp), reset to 1.
     *
     * Returns the new value. Throws RuntimeException if it can't write
     * (typically: data/ missing or not writable by www-data).
     */
    public static function increment(): string
    {
        $root = self::$rootCached ?? throw new \RuntimeException('Build::init() must be called before increment()');
        $current = self::$cached ?? 'dev';
        $next = ctype_digit($current) ? (string)((int)$current + 1) : '1';

        $dataDir = $root . '/data';
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0770, true);
        }
        $target = $dataDir . '/version';
        $written = @file_put_contents($target, $next . "\n", LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Cannot write ' . $target . ' (check www-data permissions on the data/ directory)');
        }
        @chmod($target, 0664);

        // update the in-process cache — the next request re-reads from disk
        self::$cached = $next;
        return $next;
    }

    /**
     * @return list<string> Candidate paths sorted by decreasing priority.
     */
    private static function candidatePaths(string $root): array
    {
        return [
            $root . '/data/version',
            $root . '/.version',
            $root . '/scripts/.version',
            $root . '/scripts/BUILD',
            $root . '/BUILD',
        ];
    }

    /** Return the cached version, or 'dev' if not initialized. */
    public static function version(): string
    {
        return self::$cached ?? 'dev';
    }

    /** Append `?v=BUILD` to a path. Useful in templates. */
    public static function v(string $path): string
    {
        $sep = str_contains($path, '?') ? '&' : '?';
        return $path . $sep . 'v=' . self::version();
    }
}
