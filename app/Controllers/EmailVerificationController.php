<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Config;
use Tylio\Services\DB;
use Tylio\Services\EmailVerification;
use Tylio\Services\Mailer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP surface for the admin email verification flow:
 *   POST /api/admin/email/verify       — consume a code,
 *   POST /api/admin/email/resend-code  — re-request a code (30-min cooldown).
 *
 * The setting `site.admin_email` is set by InstallController /
 * SettingsController and the verification request is auto-triggered
 * there — so this controller's job is the *interactive* code-paste
 * step plus the recovery resend.
 *
 * **Extendable by design.** Non-`final` so the SaaS overlay can subclass
 * to scope every lookup by `tenant_id` (`TenantEmailVerificationController`,
 * commit 6).
 */
class EmailVerificationController
{
    public function __construct(
        protected DB $db,
        protected Config $config,
        protected EmailVerification $emailVerification,
        protected Mailer $mailer,
    ) {}

    public function verify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $code = trim((string)($body['code'] ?? ''));
        if ($code === '') {
            return AuthController::json($response, ['error' => 'missing_code'], 422);
        }

        $email = $this->resolveAdminEmail($request);
        if ($email === '') {
            return AuthController::json($response, ['error' => 'no_email_configured'], 422);
        }

        $tenantId = $this->tenantIdFromRequest($request);
        $ok = $this->emailVerification->verifyCode($email, $code, $tenantId);

        $user = $request->getAttribute('user');
        $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $this->writeAudit($user, $ok ? 'email_verification.verify.ok' : 'email_verification.verify.fail', $email, $ip);

        if (!$ok) {
            $pending = $this->emailVerification->pendingFor($email, $tenantId);
            return AuthController::json($response, [
                'error' => 'invalid_code',
                'pending' => $pending,
            ], 422);
        }

        // Code accepted: mark verified + (if not already done) send the
        // welcome mail with credentials/URL. `site.welcome_sent_at`
        // protects against a re-send on later email changes.
        $this->markVerifiedAndMaybeWelcome($request, $email, $user);

        return AuthController::json($response, [
            'ok' => true,
            'verified_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function resend(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $email = $this->resolveAdminEmail($request);
        if ($email === '') {
            return AuthController::json($response, ['error' => 'no_email_configured'], 422);
        }
        $tenantId = $this->tenantIdFromRequest($request);

        if ($this->emailVerification->isRateLimited($email, $tenantId)) {
            $pending = $this->emailVerification->pendingFor($email, $tenantId);
            return AuthController::json($response, [
                'error' => 'rate_limited',
                'pending' => $pending,
            ], 429);
        }

        $locale = $this->resolveStringSetting($request, 'site.locale');
        $sent = $this->emailVerification->requestCode(
            $email,
            $locale !== '' ? $locale : null,
            $tenantId,
        );

        $user = $request->getAttribute('user');
        $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $this->writeAudit(
            $user,
            $sent ? 'email_verification.resend.ok' : 'email_verification.resend.fail',
            $email,
            $ip,
        );

        return AuthController::json($response, [
            'ok' => $sent,
            'pending' => $this->emailVerification->pendingFor($email, $tenantId),
        ]);
    }

    /**
     * Lightweight status endpoint so the SPA can render the verified-tick /
     * countdown without parsing every settings row.
     */
    public function status(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $email = $this->resolveAdminEmail($request);
        $verifiedAt = $this->resolveStringSetting($request, 'site.admin_email_verified_at');
        // "Verified" only makes sense bound to an address: if `admin_email`
        // is unset, force `verified_at` to null in the response too. Guards
        // against an inconsistent legacy/seed state where the badge would
        // otherwise read "Verificata" next to an empty input.
        if ($email === '') $verifiedAt = '';
        $tenantId = $this->tenantIdFromRequest($request);
        return AuthController::json($response, [
            'email' => $email,
            'verified_at' => $verifiedAt !== '' ? $verifiedAt : null,
            'pending' => $email !== '' ? $this->emailVerification->pendingFor($email, $tenantId) : null,
        ]);
    }

    /**
     * Write `site.admin_email_verified_at = now()` and, on first ever
     * verification (`site.welcome_sent_at IS NULL`), ship the welcome
     * mail with credentials + URL.
     *
     * The site URL is derived from `APP_URL` (caller-controlled in OSS;
     * the SaaS overlay constructs it from the resolved tenant slug).
     *
     * @param array<string,mixed>|null $user
     */
    protected function markVerifiedAndMaybeWelcome(
        ServerRequestInterface $request,
        string $email,
        ?array $user,
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $this->writeSetting($request, 'site.admin_email_verified_at', $now);

        $welcomeSentAt = $this->resolveStringSetting($request, 'site.welcome_sent_at');
        if ($welcomeSentAt !== '') {
            return;
        }

        $username = is_array($user) && isset($user['username']) ? (string)$user['username'] : '';
        $locale = $this->resolveStringSetting($request, 'site.locale');
        $siteUrl = $this->siteUrlForRequest($request);
        $adminUrl = rtrim($siteUrl, '/') . $this->config->adminPath();
        $siteLabel = (string)(parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl);

        $delivered = $this->mailer->sendWelcomeAfterVerified(
            $email,
            $username,
            $siteUrl,
            $adminUrl,
            $siteLabel,
            $locale !== '' ? $locale : null,
        );
        if ($delivered) {
            $this->writeSetting($request, 'site.welcome_sent_at', $now);
        }
    }

    /**
     * The site URL used for the welcome mail. OSS reads APP_URL; the
     * SaaS overlay overrides to derive `https://<slug>.<apex>`.
     */
    protected function siteUrlForRequest(ServerRequestInterface $request): string
    {
        $cfg = trim((string)$this->config->get('APP_URL', ''));
        if ($cfg !== '') return rtrim($cfg, '/');
        $uri = $request->getUri();
        $scheme = $uri->getScheme() ?: 'https';
        $host = $uri->getHost();
        return $scheme . '://' . $host;
    }

    /**
     * Resolve `site.admin_email` — overridden by the SaaS overlay for
     * tenant scoping. Default reads from the flat OSS `settings` table.
     */
    protected function resolveAdminEmail(ServerRequestInterface $request): string
    {
        return $this->resolveStringSetting($request, 'site.admin_email');
    }

    protected function resolveStringSetting(ServerRequestInterface $request, string $key): string
    {
        $row = $this->db->one('SELECT value FROM settings WHERE key = ? LIMIT 1', [$key]);
        if ($row === null) return '';
        $decoded = json_decode((string)($row['value'] ?? ''), true);
        return is_string($decoded) ? $decoded : '';
    }

    /**
     * Persist a single JSON-encoded settings value. Overridden by the
     * SaaS overlay for tenant scoping.
     */
    protected function writeSetting(ServerRequestInterface $request, string $key, mixed $value): void
    {
        $this->db->pdo()->prepare(
            "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))"
        )->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }

    protected function tenantIdFromRequest(ServerRequestInterface $request): ?int
    {
        return null;
    }

    /**
     * @param array<string,mixed>|null $user
     */
    protected function writeAudit(?array $user, string $action, string $email, string $ip): void
    {
        $this->db->insert('audit_log', [
            'user_id' => $user['id'] ?? null,
            'action' => $action,
            'resource' => $email,
            'ip' => $ip,
        ]);
    }
}
