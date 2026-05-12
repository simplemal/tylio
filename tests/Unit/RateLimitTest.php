<?php
declare(strict_types=1);

namespace Tylio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tylio\Config;
use Tylio\Services\DB;
use Tylio\Services\RateLimit;

/**
 * Tenant-scope semantics on `RateLimit`.
 *
 * The bug being guarded against: before the per-tenant fix, a successful
 * login on tenant A would clear (via `clearForIp`) every failed-attempt
 * row for that IP, including those belonging to tenant B — defeating
 * the lockout on B. The fix added an optional `$tenantId` arg that
 * narrows both `loginCheck()` and `clearForIp()` to a single tenant's
 * counter, while keeping the OSS single-bucket behaviour intact when
 * `$tenantId === null`.
 *
 * These tests use the platform-shaped schema (`login_attempts` with a
 * `tenant_id` column) to exercise the scoped path; the unscoped path
 * is covered by passing `null`.
 */
final class RateLimitTest extends TestCase
{
    private function makeDb(): DB
    {
        $config = new Config(dirname(__DIR__, 2));
        $_ENV['DATABASE_PATH'] = ':memory:';
        $db = new DB($config);
        unset($_ENV['DATABASE_PATH']);
        $db->exec(<<<'SQL'
            CREATE TABLE login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL DEFAULT 1,
                ip TEXT NOT NULL,
                username TEXT NOT NULL DEFAULT '',
                success INTEGER NOT NULL DEFAULT 0,
                attempted_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        return $db;
    }

    private function makeRateLimit(DB $db): RateLimit
    {
        // Force a small window + low max so we can drive the lockout in tests.
        $_ENV['LOGIN_MAX_ATTEMPTS'] = '3';
        $_ENV['LOGIN_LOCKOUT_SECONDS'] = '900';
        $config = new Config(dirname(__DIR__, 2));
        return new RateLimit($db, $config);
    }

    protected function tearDown(): void
    {
        unset($_ENV['LOGIN_MAX_ATTEMPTS'], $_ENV['LOGIN_LOCKOUT_SECONDS']);
    }

    public function test_clear_for_ip_scoped_does_not_touch_other_tenants(): void
    {
        $db = $this->makeDb();
        // 3 fails for tenant 1 + 3 fails for tenant 2 from the same IP.
        for ($i = 0; $i < 3; $i++) {
            $db->insert('login_attempts', ['ip' => '198.51.100.1', 'tenant_id' => 1, 'success' => 0]);
            $db->insert('login_attempts', ['ip' => '198.51.100.1', 'tenant_id' => 2, 'success' => 0]);
        }
        $rl = $this->makeRateLimit($db);

        // Pre-state: both tenants are locked out for this IP.
        $this->assertNotNull($rl->loginCheck('198.51.100.1', 1));
        $this->assertNotNull($rl->loginCheck('198.51.100.1', 2));

        // Successful login on tenant 1 clears ONLY tenant 1's counter.
        $rl->clearForIp('198.51.100.1', 1);

        $this->assertNull(
            $rl->loginCheck('198.51.100.1', 1),
            'tenant 1 should be unlocked after clearForIp(ip, 1)',
        );
        $this->assertNotNull(
            $rl->loginCheck('198.51.100.1', 2),
            'tenant 2 must STAY locked — this is the bypass the fix closes',
        );
    }

    public function test_clear_for_ip_unscoped_clears_everything_for_oss(): void
    {
        // OSS single-bucket behaviour: passing null wipes ALL rows for
        // the IP regardless of tenant column.
        $db = $this->makeDb();
        for ($i = 0; $i < 3; $i++) {
            $db->insert('login_attempts', ['ip' => '198.51.100.2', 'tenant_id' => 1, 'success' => 0]);
            $db->insert('login_attempts', ['ip' => '198.51.100.2', 'tenant_id' => 2, 'success' => 0]);
        }
        $rl = $this->makeRateLimit($db);

        $rl->clearForIp('198.51.100.2'); // unscoped

        $this->assertNull($rl->loginCheck('198.51.100.2', 1));
        $this->assertNull($rl->loginCheck('198.51.100.2', 2));
        $this->assertNull($rl->loginCheck('198.51.100.2'));
    }

    public function test_login_check_counts_only_scoped_tenant(): void
    {
        $db = $this->makeDb();
        // tenant 1: 1 fail (below max=3), tenant 2: 3 fails (at max).
        $db->insert('login_attempts', ['ip' => '198.51.100.3', 'tenant_id' => 1, 'success' => 0]);
        for ($i = 0; $i < 3; $i++) {
            $db->insert('login_attempts', ['ip' => '198.51.100.3', 'tenant_id' => 2, 'success' => 0]);
        }
        $rl = $this->makeRateLimit($db);

        $this->assertNull(
            $rl->loginCheck('198.51.100.3', 1),
            'tenant 1 has only 1 fail, should NOT be locked',
        );
        $this->assertNotNull(
            $rl->loginCheck('198.51.100.3', 2),
            'tenant 2 hit max=3, should be locked',
        );
    }
}
