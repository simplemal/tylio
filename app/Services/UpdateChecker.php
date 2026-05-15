<?php
declare(strict_types=1);

namespace Tylio\Services;

use Tylio\Config;
use Tylio\Util\Markdown;

/**
 * Compares the locally installed tylio version with the latest GitHub
 * release of `simplemal/tylio`, with a 24h cache to avoid hammering the
 * GitHub API rate limit.
 *
 * Used by {@see \Tylio\Controllers\UpdateController} (route
 * `GET /api/admin/update-check`) which the admin SPA's Settings view
 * polls to surface an "Update available" banner.
 *
 * **Extendable by design.** The SaaS overlay disables the controller
 * entirely (the operator updates all tenants centrally), so the service
 * itself does not need a SaaS sub-class — but kept non-`final` for
 * symmetry with the rest of the OSS service layer.
 */
class UpdateChecker
{
    /** Settings table key used as the 24h cache for the last GitHub poll. */
    public const SETTINGS_CACHE_KEY = 'tylio.update_check';

    public const CACHE_TTL_SECONDS = 86400; // 24h

    private const GITHUB_API_URL = 'https://api.github.com/repos/simplemal/tylio/releases/latest';

    private const HTTP_TIMEOUT_SECONDS = 5;

    public function __construct(
        protected DB $db,
        protected Config $config,
    ) {}

    /**
     * Current installed version, in priority order:
     *   1. `git describe --tags --always` if a `.git` directory exists
     *      and `exec()` is available (returns `v0.3.0` or
     *      `v0.3.0-15-gabc1234` after extra commits past the last tag).
     *   2. BUILD/.version file via {@see \Tylio\Util\Build::version()}
     *      → prefixed `build-` so it never looks like a semver tag.
     *   3. Fallback `dev`.
     */
    public function currentVersion(): string
    {
        $root = $this->config->rootPath;
        if (is_dir($root . '/.git') && $this->execEnabled()) {
            $describe = $this->gitDescribe($root);
            if ($describe !== '') return $describe;
        }
        // Fallback: read the BUILD/.version file via the cache-buster util.
        $build = \Tylio\Util\Build::version();
        if ($build !== '' && $build !== 'dev') {
            // If the build file contains something that already looks like
            // a semver tag (`v0.1.0`), return as-is. Otherwise prefix with
            // `build-` to make it obvious this isn't a release tag.
            return preg_match('/^v?\d+\.\d+\.\d+/', $build) ? $build : 'build-' . $build;
        }
        return 'dev';
    }

    /**
     * Fetches the latest release from the GitHub API for `simplemal/tylio`.
     * Returns `null` on any failure (network, HTTP non-2xx, malformed JSON,
     * rate-limit, repo not found). The caller treats `null` as "couldn't
     * verify" and renders a neutral status badge.
     *
     * @return array{tag_name:string,name:string,body:string,published_at:string,html_url:string}|null
     */
    public function latestRelease(): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
                'header' => implode("\r\n", [
                    'Accept: application/vnd.github+json',
                    // GitHub REST API requires a non-empty User-Agent header
                    // — otherwise responds 403. We send a stable identifier
                    // tied to the checker rather than the host's PHP UA so
                    // GitHub can throttle / debug abuse if needed.
                    'User-Agent: tylio-update-checker/1.0',
                    'X-GitHub-Api-Version: 2022-11-28',
                ]),
                'ignore_errors' => true, // we want to read body even on 4xx/5xx
            ],
        ]);
        $body = @file_get_contents(self::GITHUB_API_URL, false, $context);
        if ($body === false || $body === '') {
            return null;
        }
        // $http_response_header is auto-populated by file_get_contents when
        // a stream context with `http` is used. After the body succeeds it's
        // guaranteed set — PHPStan rejects `?? []` here as "always exists",
        // so we read it directly.
        /** @var list<string> $http_response_header */
        $status = $this->parseHttpStatus($http_response_header);
        if ($status === null || $status < 200 || $status >= 300) {
            return null;
        }
        try {
            /** @var array<string,mixed>|null $json */
            $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($json) || !isset($json['tag_name']) || !is_string($json['tag_name'])) {
            return null;
        }
        return [
            'tag_name'     => (string)$json['tag_name'],
            'name'         => isset($json['name']) && is_string($json['name']) ? $json['name'] : (string)$json['tag_name'],
            'body'         => isset($json['body']) && is_string($json['body']) ? $json['body'] : '',
            'published_at' => isset($json['published_at']) && is_string($json['published_at']) ? $json['published_at'] : '',
            'html_url'     => isset($json['html_url']) && is_string($json['html_url']) ? $json['html_url'] : '',
        ];
    }

    /**
     * Orchestrates the check, caches the result in the `settings` table
     * under {@see self::SETTINGS_CACHE_KEY} with a 24h TTL.
     *
     * Returns:
     *  - `current`: local version string
     *  - `latest`: latest release tag, or `null` if the fetch failed
     *  - `is_outdated`: bool — `true` only if both versions are known and
     *    the local one is strictly older than the remote tag
     *  - `last_checked`: ISO-8601 timestamp of the last successful poll
     *  - `changelog_html`: sanitized HTML of the release body (Markdown
     *    rendered server-side via {@see \Tylio\Util\Markdown}), empty
     *    string if no release was fetched
     *  - `release_url`: GitHub release page URL (`html_url`), empty string
     *    if no release was fetched
     *  - `release_name`: human title from `name`, empty string if no
     *    release was fetched
     *
     * @return array{
     *   current:string, latest:?string, is_outdated:bool,
     *   last_checked:string, changelog_html:string,
     *   release_url:string, release_name:string
     * }
     */
    public function check(bool $force = false): array
    {
        $current = $this->currentVersion();

        // Cache hit (within TTL): return without hitting GitHub.
        $cached = $force ? null : $this->readCache();
        if ($cached !== null) {
            // Recompute `is_outdated` against the freshly-read local
            // version: if the user just ran `git pull` the cached row
            // might still mark them as outdated, surface the new state
            // immediately without forcing a re-poll.
            $cached['current'] = $current;
            $cached['is_outdated'] = $cached['latest'] !== null
                && $this->compareVersions($current, (string)$cached['latest']) < 0;
            return $cached;
        }

        $latest = $this->latestRelease();
        if ($latest === null) {
            // Network/API failure: don't cache so the next click retries.
            return [
                'current'        => $current,
                'latest'         => null,
                'is_outdated'    => false,
                'last_checked'   => gmdate('c'),
                'changelog_html' => '',
                'release_url'    => '',
                'release_name'   => '',
            ];
        }

        $changelogHtml = $latest['body'] !== '' ? Markdown::render($latest['body']) : '';
        $result = [
            'current'        => $current,
            'latest'         => $latest['tag_name'],
            'is_outdated'    => $this->compareVersions($current, $latest['tag_name']) < 0,
            'last_checked'   => gmdate('c'),
            'changelog_html' => $changelogHtml,
            'release_url'    => $latest['html_url'],
            'release_name'   => $latest['name'],
        ];

        $this->writeCache($result);
        return $result;
    }

    /**
     * Semver-aware comparison. Returns -1, 0, or +1. Strips leading `v`,
     * splits on `.`, compares numerically up to the third component.
     * Pre-release suffixes (`-rc.1`, `-15-gabc1234`) are considered
     * "older than" the same base tag without suffix — matching the
     * intuition that `v0.3.0-15-gabc1234` is a dev build past v0.3.0
     * but still before the next release.
     *
     * `dev` / `build-*` / anything non-semver compares as `-PHP_INT_MAX`
     * so it is always "older" than any released tag.
     */
    public function compareVersions(string $current, string $latest): int
    {
        $a = $this->parseSemver($current);
        $b = $this->parseSemver($latest);
        if ($a === null && $b === null) return 0;
        if ($a === null) return -1;
        if ($b === null) return 1;

        for ($i = 0; $i < 3; $i++) {
            if ($a[$i] !== $b[$i]) return $a[$i] <=> $b[$i];
        }
        // Major.minor.patch match: the side WITH a pre-release suffix is
        // considered older than the same base tag without one.
        $aPre = $a[3] !== '';
        $bPre = $b[3] !== '';
        if ($aPre && !$bPre) return -1;
        if (!$aPre && $bPre) return 1;
        // Both have or both lack a suffix: equal enough for our purposes.
        return 0;
    }

    /**
     * Parse a semver-ish string into [major, minor, patch, pre-release].
     * Returns null on anything that doesn't look like a semver tag —
     * including `dev`, `build-*`, and arbitrary git SHAs.
     *
     * @return array{0:int,1:int,2:int,3:string}|null
     */
    private function parseSemver(string $raw): ?array
    {
        $s = ltrim(trim($raw), 'vV');
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)(.*)$/', $s, $m)) return null;
        return [(int)$m[1], (int)$m[2], (int)$m[3], (string)$m[4]];
    }

    /**
     * Read the cached result, returning `null` if missing or stale.
     *
     * @return array{
     *   current:string, latest:?string, is_outdated:bool,
     *   last_checked:string, changelog_html:string,
     *   release_url:string, release_name:string
     * }|null
     */
    private function readCache(): ?array
    {
        $row = $this->cacheRow();
        if ($row === null) return null;
        $payload = json_decode((string)$row['value'], true);
        if (!is_array($payload)) return null;
        $lastChecked = isset($payload['last_checked']) && is_string($payload['last_checked'])
            ? strtotime($payload['last_checked'])
            : false;
        if ($lastChecked === false || (time() - $lastChecked) > self::CACHE_TTL_SECONDS) {
            return null;
        }
        // Defensive: fill in any missing key with a safe default so
        // callers don't have to re-check shape on every field.
        return [
            'current'        => isset($payload['current']) && is_string($payload['current']) ? $payload['current'] : '',
            'latest'         => array_key_exists('latest', $payload)
                ? (is_string($payload['latest']) ? $payload['latest'] : null)
                : null,
            'is_outdated'    => (bool)($payload['is_outdated'] ?? false),
            'last_checked'   => (string)$payload['last_checked'],
            'changelog_html' => isset($payload['changelog_html']) && is_string($payload['changelog_html']) ? $payload['changelog_html'] : '',
            'release_url'    => isset($payload['release_url']) && is_string($payload['release_url']) ? $payload['release_url'] : '',
            'release_name'   => isset($payload['release_name']) && is_string($payload['release_name']) ? $payload['release_name'] : '',
        ];
    }

    /**
     * Read the cache row from `settings`. Tolerates both the OSS schema
     * (no `tenant_id` column) and the SaaS schema (`tenant_id NOT NULL`).
     * The update-check cache is process-global — not per-tenant — so on
     * the SaaS schema we store it under `tenant_id = 0` (a sentinel
     * never used by real tenants).
     *
     * The current SaaS overlay overrides {@see UpdateController::check}
     * to return `{disabled: true}` and never invokes this service, but
     * the dual-schema handling keeps the OSS code working when
     * someone clones tylio into a layout where the platform migrations
     * (1000_multitenant) have also been applied (e.g. a former SaaS
     * tenant that ran `/admin/export` → OSS clone).
     *
     * @return array{value:string}|null
     */
    private function cacheRow(): ?array
    {
        $pdo = $this->db->pdo();
        if ($this->hasTenantColumn()) {
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ? AND tenant_id = 0 LIMIT 1');
        } else {
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ? LIMIT 1');
        }
        $stmt->execute([self::SETTINGS_CACHE_KEY]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? ['value' => (string)$row['value']] : null;
    }

    /** @param array<string,mixed> $result */
    private function writeCache(array $result): void
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return;
        $pdo = $this->db->pdo();
        if ($this->hasTenantColumn()) {
            $stmt = $pdo->prepare(
                "INSERT OR REPLACE INTO settings (tenant_id, key, value, updated_at) VALUES (0, ?, ?, datetime('now'))"
            );
            $stmt->execute([self::SETTINGS_CACHE_KEY, $json]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))"
            );
            $stmt->execute([self::SETTINGS_CACHE_KEY, $json]);
        }
    }

    /** Cached on first call (each request rebuilds the service). */
    private ?bool $hasTenantColumn = null;
    private function hasTenantColumn(): bool
    {
        if ($this->hasTenantColumn !== null) return $this->hasTenantColumn;
        $pdo = $this->db->pdo();
        $cols = $pdo->query("PRAGMA table_info('settings')")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            if (isset($c['name']) && $c['name'] === 'tenant_id') {
                return $this->hasTenantColumn = true;
            }
        }
        return $this->hasTenantColumn = false;
    }

    /**
     * Run `git describe --tags --always` in the project root, return '' on any failure.
     *
     * `-c safe.directory=$root` is passed inline so the call works even when
     * `www-data` (PHP-FPM user) has no entry in its `$HOME/.gitconfig` for this
     * repo (typical on installs deployed via `sudo git clone` into `/var/www/`).
     * Without this, git refuses with "fatal: detected dubious ownership" and we
     * fall back to BUILD file → "dev" in the UI even when the repo is on a
     * proper release tag.
     */
    private function gitDescribe(string $root): string
    {
        $cmd = 'git -c safe.directory=' . escapeshellarg($root)
            . ' -C ' . escapeshellarg($root)
            . ' describe --tags --always 2>/dev/null';
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        if ($code !== 0) return '';
        $line = isset($out[0]) ? trim((string)$out[0]) : '';
        return $line;
    }

    /** Whether PHP's `exec()` is callable (not in `disable_functions`). */
    private function execEnabled(): bool
    {
        if (!function_exists('exec')) return false;
        $disabled = (string)ini_get('disable_functions');
        if ($disabled === '') return true;
        foreach (explode(',', $disabled) as $f) {
            if (strcasecmp(trim($f), 'exec') === 0) return false;
        }
        return true;
    }

    /**
     * Parse the HTTP status code from a `$http_response_header` array.
     *
     * @param list<string> $headers
     */
    private function parseHttpStatus(array $headers): ?int
    {
        // The first line is the status-line: "HTTP/1.1 200 OK".
        // On redirect chains PHP appends each response's headers, so the
        // FIRST line in the LAST status block is what matters. Walk
        // backwards looking for the most recent "HTTP/x …" prefix.
        for ($i = count($headers) - 1; $i >= 0; $i--) {
            $line = $headers[$i];
            if (str_starts_with($line, 'HTTP/')) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    return (int)$m[1];
                }
            }
        }
        return null;
    }
}
