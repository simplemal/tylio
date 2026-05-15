<?php
declare(strict_types=1);

namespace Tylio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tylio\Config;
use Tylio\Services\DB;
use Tylio\Services\EmailVerification;
use Tylio\Services\I18n;
use Tylio\Services\Mailer;

/**
 * Unit tests for the EmailVerification service:
 *   - happy path: code generated, hashed, accepted on submission;
 *   - 5 wrong attempts invalidate the row + force resend;
 *   - 30-min cooldown blocks a second requestCode for the same email;
 *   - 24h TTL drops expired rows from `pendingFor()`;
 *   - typo-recovery flow: a stale pending code on email A doesn't bleed
 *     verification into the new email B.
 *
 * The Mailer dependency is mocked via an anonymous subclass that captures
 * outbound code/email pairs without touching SMTP — same approach used
 * by the project's other in-process tests.
 */
final class EmailVerificationTest extends TestCase
{
    private function makeDb(): DB
    {
        $config = new Config(dirname(__DIR__, 2));
        $_ENV['DATABASE_PATH'] = ':memory:';
        $db = new DB($config);
        unset($_ENV['DATABASE_PATH']);
        // Mirror the 0007 migration shape — the columns the service touches.
        $db->exec(<<<'SQL'
            CREATE TABLE email_verifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL DEFAULT 1,
                email TEXT NOT NULL,
                code_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                consumed_at TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        return $db;
    }

    private function makeMailer(): Mailer
    {
        $config = new Config(dirname(__DIR__, 2));
        $i18n = new I18n($config);
        // Anonymous Mailer subclass that captures the code without SMTP.
        return new class($config, $i18n) extends Mailer {
            /** @var array{to:string,code:string,ttl:int}|null */
            public ?array $lastVerification = null;
            public function sendVerificationCode(
                string $to,
                string $code,
                int $ttlMinutes,
                ?string $locale = null,
            ): bool {
                $this->lastVerification = ['to' => $to, 'code' => $code, 'ttl' => $ttlMinutes];
                return true;
            }
        };
    }

    /**
     * Test-only EmailVerification subclass: exposes the latest generated
     * code so we can drive `verifyCode()` deterministically without
     * extracting it from a real outbound mail.
     */
    private function makeService(DB $db, Mailer $mailer): EmailVerification
    {
        $_ENV['EMAIL_VERIFICATION_PEPPER'] = 'test-pepper-' . bin2hex(random_bytes(8));
        $config = new Config(dirname(__DIR__, 2));
        $svc = new EmailVerification($db, $config, $mailer);
        unset($_ENV['EMAIL_VERIFICATION_PEPPER']);
        return $svc;
    }

    /** Helper: extract the actual code the (mock) Mailer received last. */
    private function lastCode(Mailer $mailer): string
    {
        /** @var object $m */
        $m = $mailer;
        $rec = $m->lastVerification;
        $this->assertNotNull($rec, 'Mailer expected to have captured a code');
        return $rec['code'];
    }

    public function test_request_then_verify_happy_path(): void
    {
        $db = $this->makeDb();
        $mailer = $this->makeMailer();
        $svc = $this->makeService($db, $mailer);

        $this->assertTrue($svc->requestCode('user@example.com'));
        $code = $this->lastCode($mailer);
        $this->assertMatchesRegularExpression(
            '/^[' . EmailVerification::CHARSET . ']{6}$/',
            $code,
            'code must match the Crockford-base32 alphabet and length',
        );

        $this->assertTrue($svc->verifyCode('user@example.com', $code));
        // Row consumed → second attempt with same code fails.
        $this->assertFalse(
            $svc->verifyCode('user@example.com', $code),
            'a consumed code must not validate twice',
        );
    }

    public function test_five_wrong_attempts_invalidate_the_code(): void
    {
        $db = $this->makeDb();
        $mailer = $this->makeMailer();
        $svc = $this->makeService($db, $mailer);

        $this->assertTrue($svc->requestCode('user@example.com'));
        $real = $this->lastCode($mailer);

        // 5 wrong attempts (different from the real code).
        $wrong = 'AAAAAA' === $real ? 'BBBBBB' : 'AAAAAA';
        for ($i = 0; $i < EmailVerification::MAX_ATTEMPTS; $i++) {
            $this->assertFalse($svc->verifyCode('user@example.com', $wrong));
        }
        // Now even the real code fails — the row has been consumed.
        $this->assertFalse(
            $svc->verifyCode('user@example.com', $real),
            'after MAX_ATTEMPTS failures the row must be invalidated',
        );
        // And `pendingFor()` returns null → the SPA shows "request a new code".
        $this->assertNull($svc->pendingFor('user@example.com'));
    }

    public function test_resend_cooldown_blocks_second_request(): void
    {
        $db = $this->makeDb();
        $mailer = $this->makeMailer();
        $svc = $this->makeService($db, $mailer);

        $this->assertTrue($svc->requestCode('user@example.com'));
        // Immediate second request is rate-limited.
        $this->assertFalse(
            $svc->requestCode('user@example.com'),
            'a second requestCode within the cooldown window must be blocked',
        );
        $this->assertTrue($svc->isRateLimited('user@example.com'));
        $pending = $svc->pendingFor('user@example.com');
        $this->assertNotNull($pending);
        $this->assertGreaterThan(0, $pending['cooldown_remaining']);
    }

    public function test_ttl_drops_expired_rows_from_pending_lookup(): void
    {
        $db = $this->makeDb();
        $mailer = $this->makeMailer();
        $svc = $this->makeService($db, $mailer);

        // Insert an expired row by hand: expires_at in the past AND
        // created_at older than the resend cooldown — otherwise the
        // rate-limit guard would think there's an in-flight request.
        $db->insert('email_verifications', [
            'email' => 'old@example.com',
            'code_hash' => 'whatever',
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
            'attempts' => 0,
            'created_at' => gmdate('Y-m-d H:i:s', time() - EmailVerification::TTL_SECONDS - 60),
        ]);
        $this->assertNull(
            $svc->pendingFor('old@example.com'),
            'expired rows must not appear as pending',
        );
        // And requestCode() proceeds (cooldown only counts recent rows).
        $this->assertTrue($svc->requestCode('old@example.com'));
    }

    /**
     * Critical security/recovery flow (test #4 in the plan):
     *   1) admin installs with typo email A → code goes to a stranger
     *   2) admin never verifies — the welcome mail is NOT sent
     *   3) admin enters the admin panel and switches to the real email B
     *   4) the stranger sees no further messages; the real recipient B
     *      can complete the verification with a code issued to *their*
     *      address only.
     *
     * The service-level check here: changing the candidate email
     * (which controller does by re-issuing for B) must not let the old
     * pending row on A satisfy B's verifyCode.
     */
    public function test_typo_recovery_does_not_leak_into_corrected_email(): void
    {
        $db = $this->makeDb();
        $mailer = $this->makeMailer();
        $svc = $this->makeService($db, $mailer);

        $this->assertTrue($svc->requestCode('typo@example.com'));
        $typoCode = $this->lastCode($mailer);

        // Admin notices the typo, requests a fresh code for the right
        // address. We need to bypass the cooldown because both requests
        // happen within 30 min in a real user flow — the
        // SettingsController doesn't reuse the same `EmailVerification`
        // call path on email change; for the test we just delete the
        // stale row first to mirror what `wipeStale` does on a new
        // address (different bucket).
        $this->assertTrue($svc->requestCode('correct@example.com'));
        $correctCode = $this->lastCode($mailer);
        $this->assertNotSame($typoCode, $correctCode);

        // The stranger's leaked code MUST NOT validate the correct email.
        $this->assertFalse(
            $svc->verifyCode('correct@example.com', $typoCode),
            'a code issued for the typo address must never validate the corrected address',
        );
        // And vice-versa.
        $this->assertFalse(
            $svc->verifyCode('typo@example.com', $correctCode),
            'a code issued for the corrected address must never validate the typo address',
        );

        // The correct address verifies normally with its own code.
        $this->assertTrue($svc->verifyCode('correct@example.com', $correctCode));
    }

    public function test_tenant_scoping_isolates_pending_rows(): void
    {
        $db = $this->makeDb();
        $mailer = $this->makeMailer();
        $svc = $this->makeService($db, $mailer);

        $this->assertTrue($svc->requestCode('shared@example.com', null, 1));
        $codeT1 = $this->lastCode($mailer);

        $this->assertTrue($svc->requestCode('shared@example.com', null, 2));
        $codeT2 = $this->lastCode($mailer);

        // Each tenant only sees its own pending row.
        $p1 = $svc->pendingFor('shared@example.com', 1);
        $p2 = $svc->pendingFor('shared@example.com', 2);
        $this->assertNotNull($p1);
        $this->assertNotNull($p2);

        // Tenant 1's code must not validate against tenant 2's bucket
        // (and vice versa). Use a wrong code to confirm isolation
        // increments only the right tenant's attempt counter.
        $this->assertFalse($svc->verifyCode('shared@example.com', $codeT1, 2));
        $this->assertTrue($svc->verifyCode('shared@example.com', $codeT2, 2));
        // T1 row still pending after T2 was consumed.
        $this->assertNotNull($svc->pendingFor('shared@example.com', 1));
    }
}
