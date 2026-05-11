<?php
declare(strict_types=1);

namespace Tylio\Services;

use Tylio\Config;

/**
 * Login rate-limit. Counts per-IP failures within
 * `LOGIN_LOCKOUT_SECONDS` and locks out after `LOGIN_MAX_ATTEMPTS`
 * failed attempts. Defaults: 5 attempts per 15 minutes.
 *
 * **Tenant scoping.** OSS callers pass `$tenantId = null` (single
 * user, one global bucket). The multi-tenant overlay passes the
 * resolved tenant id so each tenant maintains its own counter — this
 * matters for `clearForIp()`: without scoping, a successful login on
 * tenant A would zero out a brute-force in progress against tenant B
 * from the same IP, defeating the lockout.
 *
 * The `tenant_id` column on `login_attempts` is created by the
 * platform migration overlay (`1000_multitenant.sql`); when the
 * column is absent (plain OSS install) callers MUST pass `null` so
 * the query doesn't reference a non-existent column.
 *
 * **Extendable by design.** Non-`final`; sub-classes can override
 * `loginCheck()`/`clearForIp()` to add IP-allow-list logic, captcha
 * hooks, or shared cache stores.
 */
class RateLimit
{
    public function __construct(protected DB $db, protected Config $config) {}

    /** Returns null if allowed, or seconds-to-wait if locked out. */
    public function loginCheck(string $ip, ?int $tenantId = null): ?int
    {
        $max = $this->config->int('LOGIN_MAX_ATTEMPTS', 5);
        $window = $this->config->int('LOGIN_LOCKOUT_SECONDS', 900);

        [$sql, $params] = $this->scopedQuery(
            "SELECT COUNT(*) AS c, MAX(attempted_at) AS last_at
             FROM login_attempts
             WHERE ip = ? AND success = 0 AND attempted_at > datetime('now', ?)",
            [$ip, sprintf('-%d seconds', $window)],
            $tenantId,
        );

        $row = $this->db->one($sql, $params);
        $count = (int)($row['c'] ?? 0);
        if ($count < $max) return null;

        $lastTs = strtotime((string)($row['last_at'] ?? 'now'));
        $wait = ($lastTs + $window) - time();
        return max(1, $wait);
    }

    public function clearForIp(string $ip, ?int $tenantId = null): void
    {
        [$sql, $params] = $this->scopedQuery(
            "DELETE FROM login_attempts WHERE ip = ? AND success = 0",
            [$ip],
            $tenantId,
        );
        $this->db->query($sql, $params);
    }

    /**
     * Appends the `AND tenant_id = ?` clause only when a tenant id is
     * supplied — keeps the query column-list compatible with both the
     * OSS schema (no `tenant_id`) and the platform schema.
     *
     * @param array<int,mixed> $params
     * @return array{0:string,1:array<int,mixed>}
     */
    protected function scopedQuery(string $sql, array $params, ?int $tenantId): array
    {
        if ($tenantId === null) {
            return [$sql, $params];
        }
        $sql .= ' AND tenant_id = ?';
        $params[] = $tenantId;
        return [$sql, $params];
    }
}
