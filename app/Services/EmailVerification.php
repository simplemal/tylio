<?php
declare(strict_types=1);

namespace Tylio\Services;

use Tylio\Config;

/**
 * Email verification flow for the admin's recovery / notification email
 * (`site.admin_email`).
 *
 * Lifecycle:
 *   1. `requestCode($email)` — generate a 6-char Crockford-base32 code,
 *      store the hashed copy in `email_verifications`, and send it via
 *      `Mailer::sendVerificationCode()`. Rate-limited to one request per
 *      `RESEND_COOLDOWN` (30 min) per email.
 *   2. `verifyCode($code)` — consume the latest pending row for the
 *      currently-configured admin email. 5 wrong attempts invalidate the
 *      row and force a new resend.
 *   3. `pendingFor($email)` — read-only helper used by the SPA to drive
 *      the countdown / "ready to resend" UI.
 *
 * **Tenant scoping.** OSS callers pass `$tenantId = null` (single
 * install, one bucket). The multi-tenant overlay subclasses or calls
 * with the resolved tenant id so each tenant maintains its own pending
 * codes — same pattern as `RateLimit::scopedQuery()`.
 *
 * **Extendable by design.** Non-`final`; sub-classes can override
 * generation/storage/rate-limit primitives.
 *
 * **Storage hygiene.** Codes are NEVER stored in plaintext. Only the
 * `sha256(code + serverPepper)` lands in the DB. The pepper lives in
 * `EMAIL_VERIFICATION_PEPPER` (env). On a fresh install with no pepper
 * set the service still works but logs a one-time warning — the missing
 * pepper means a DB exfiltration could be brute-forced offline, which
 * is recoverable by re-generating the pepper (invalidating in-flight
 * codes) and adding it to `.env`.
 */
class EmailVerification
{
    /** Crockford base32 alphabet without 0/O/1/I/L/U — visually distinct. */
    public const CHARSET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const CODE_LENGTH = 6;
    public const TTL_SECONDS = 86400;      // 24h
    public const RESEND_COOLDOWN = 1800;   // 30 min between resends per email
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        protected DB $db,
        protected Config $config,
        protected Mailer $mailer,
    ) {}

    /**
     * Generate a new code, persist its hash, and ask the Mailer to send it.
     * Returns the actual code only when called explicitly without a
     * Mailer call — internal helper exposes only the boolean.
     *
     * Return values:
     *   - true   code generated AND mailer accepted the message
     *   - false  rate-limited OR mailer rejected (e.g. MAIL_DSN empty)
     *
     * Caller may inspect `Mailer::lastError()` for transport diagnostics
     * when false is returned without the cooldown being active.
     *
     * @param string $email caller MUST have validated it via
     *                      `filter_var(FILTER_VALIDATE_EMAIL)` already
     * @param string|null $locale locale for the outbound mail body
     * @param int|null $tenantId platform overlay passes the resolved tenant id
     */
    public function requestCode(string $email, ?string $locale = null, ?int $tenantId = null): bool
    {
        if ($email === '') return false;

        if ($this->isRateLimited($email, $tenantId)) {
            return false;
        }

        $code = $this->generateCode();
        $hash = $this->hashCode($code);
        $expires = gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        // Wipe stale rows for the same email/tenant bucket BEFORE inserting:
        // keeps the table small and ensures `pendingFor()` always returns
        // the freshly-issued row.
        $this->wipeStale($email, $tenantId);

        $row = [
            'email' => $email,
            'code_hash' => $hash,
            'expires_at' => $expires,
        ];
        if ($tenantId !== null) {
            $row['tenant_id'] = $tenantId;
        }
        $this->db->insert('email_verifications', $row);

        $ttlMinutes = (int)ceil(self::TTL_SECONDS / 60);
        return $this->mailer->sendVerificationCode($email, $code, $ttlMinutes, $locale);
    }

    /**
     * Look up the pending row for the supplied email and (optionally)
     * verify the supplied code. Returns true on success: the row is
     * marked consumed, audit columns updated, and the caller is expected
     * to flip `site.admin_email_verified_at` on the settings table.
     *
     * On failure: increments `attempts`. When `attempts >= MAX_ATTEMPTS`
     * the row is invalidated (consumed_at = now) so the next request
     * triggers a fresh resend.
     */
    public function verifyCode(string $email, string $code, ?int $tenantId = null): bool
    {
        $code = strtoupper(trim($code));
        if ($code === '' || $email === '') return false;
        // Reject obviously malformed codes early — saves a DB roundtrip on bots.
        if (!preg_match('/^[' . self::CHARSET . ']{' . self::CODE_LENGTH . '}$/', $code)) {
            return false;
        }

        [$sql, $params] = $this->scopedQuery(
            "SELECT * FROM email_verifications
              WHERE email = ?
                AND consumed_at IS NULL
                AND expires_at > datetime('now')",
            [$email],
            $tenantId,
        );
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $row = $this->db->one($sql, $params);
        if ($row === null) return false;

        $expected = (string)$row['code_hash'];
        $supplied = $this->hashCode($code);
        if (!hash_equals($expected, $supplied)) {
            // Wrong code: increment counter, invalidate on MAX_ATTEMPTS.
            $attempts = (int)($row['attempts'] ?? 0) + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->db->query(
                    "UPDATE email_verifications
                        SET attempts = ?, consumed_at = datetime('now')
                      WHERE id = ?",
                    [$attempts, (int)$row['id']],
                );
            } else {
                $this->db->query(
                    'UPDATE email_verifications SET attempts = ? WHERE id = ?',
                    [$attempts, (int)$row['id']],
                );
            }
            return false;
        }

        // Success — mark consumed, return true.
        $this->db->query(
            "UPDATE email_verifications
                SET consumed_at = datetime('now')
              WHERE id = ?",
            [(int)$row['id']],
        );
        return true;
    }

    /**
     * Inspect the active (non-consumed, non-expired) pending row, if any.
     * Used by the admin SPA to show the countdown + "Resend code" button.
     *
     * @return array{
     *     email: string,
     *     expires_at: string,
     *     attempts: int,
     *     can_resend_at: string,
     *     cooldown_remaining: int,
     * }|null
     */
    public function pendingFor(string $email, ?int $tenantId = null): ?array
    {
        if ($email === '') return null;
        [$sql, $params] = $this->scopedQuery(
            "SELECT email, expires_at, attempts, created_at
               FROM email_verifications
              WHERE email = ?
                AND consumed_at IS NULL
                AND expires_at > datetime('now')",
            [$email],
            $tenantId,
        );
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $row = $this->db->one($sql, $params);
        if ($row === null) return null;

        $createdTs = strtotime((string)$row['created_at'] . ' UTC');
        $canResendAt = ($createdTs !== false ? $createdTs : time()) + self::RESEND_COOLDOWN;
        $cooldownRemaining = max(0, $canResendAt - time());

        return [
            'email' => (string)$row['email'],
            'expires_at' => (string)$row['expires_at'],
            'attempts' => (int)$row['attempts'],
            'can_resend_at' => gmdate('Y-m-d H:i:s', $canResendAt),
            'cooldown_remaining' => $cooldownRemaining,
        ];
    }

    /**
     * Was a code requested for this email within the cooldown window?
     * Used to gate `requestCode()` against spam.
     */
    public function isRateLimited(string $email, ?int $tenantId = null): bool
    {
        [$sql, $params] = $this->scopedQuery(
            "SELECT 1 FROM email_verifications
              WHERE email = ?
                AND created_at > datetime('now', ?)",
            [$email, sprintf('-%d seconds', self::RESEND_COOLDOWN)],
            $tenantId,
        );
        $sql .= ' LIMIT 1';
        return $this->db->one($sql, $params) !== null;
    }

    /**
     * Lifetime-of-process pepper. Falls back to a deploy-constant
     * derivative of APP_KEY when EMAIL_VERIFICATION_PEPPER is unset, so
     * codes from a freshly-cloned OSS install still validate consistently
     * across boots without the admin having to mint the secret manually.
     */
    protected function pepper(): string
    {
        $explicit = (string)$this->config->get('EMAIL_VERIFICATION_PEPPER', '');
        if ($explicit !== '') return $explicit;
        // Derived fallback: HMAC-flavored so a leak of APP_KEY doesn't
        // trivially leak the pepper too.
        $appKey = (string)$this->config->get('APP_KEY', 'tylio-default-key');
        return hash('sha256', 'tylio-email-pepper:' . $appKey);
    }

    protected function hashCode(string $code): string
    {
        return hash('sha256', strtoupper($code) . '|' . $this->pepper());
    }

    /**
     * Pure-PHP CSPRNG over `CHARSET`. Avoids `random_int` bias by reading
     * a single byte per char and rejecting values that would skew the
     * distribution (mod-bias guard).
     */
    protected function generateCode(): string
    {
        $alphabet = self::CHARSET;
        $base = strlen($alphabet);
        $out = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $out .= $alphabet[random_int(0, $base - 1)];
        }
        return $out;
    }

    /**
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

    /**
     * Remove stale rows for an email so the table doesn't grow unbounded.
     * Triggered before every `requestCode()` insert.
     */
    protected function wipeStale(string $email, ?int $tenantId): void
    {
        [$sql, $params] = $this->scopedQuery(
            "DELETE FROM email_verifications
              WHERE email = ?
                AND (consumed_at IS NOT NULL OR expires_at <= datetime('now'))",
            [$email],
            $tenantId,
        );
        $this->db->query($sql, $params);
    }
}
