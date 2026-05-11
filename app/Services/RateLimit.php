<?php
declare(strict_types=1);

namespace Tylio\Services;

use Tylio\Config;

/**
 * Admin login rate-limit. Single-user: one account, one policy.
 *
 * Counts per-IP failures within `LOGIN_LOCKOUT_SECONDS` and locks out
 * after `LOGIN_MAX_ATTEMPTS` failed attempts. Defaults: 5 attempts per
 * 15 minutes.
 */
class RateLimit
{
    public function __construct(protected DB $db, protected Config $config) {}

    /** Returns null if allowed, or seconds-to-wait if locked out. */
    public function loginCheck(string $ip): ?int
    {
        $max = $this->config->int('LOGIN_MAX_ATTEMPTS', 5);
        $window = $this->config->int('LOGIN_LOCKOUT_SECONDS', 900);

        $row = $this->db->one(
            "SELECT COUNT(*) AS c, MAX(attempted_at) AS last_at
             FROM login_attempts
             WHERE ip = ? AND success = 0 AND attempted_at > datetime('now', ?)",
            [$ip, sprintf('-%d seconds', $window)],
        );
        $count = (int)($row['c'] ?? 0);
        if ($count < $max) return null;

        $lastTs = strtotime((string)($row['last_at'] ?? 'now'));
        $wait = ($lastTs + $window) - time();
        return max(1, $wait);
    }

    public function clearForIp(string $ip): void
    {
        $this->db->query(
            "DELETE FROM login_attempts WHERE ip = ? AND success = 0",
            [$ip],
        );
    }
}
