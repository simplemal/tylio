<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Config;
use Tylio\Services\Auth;
use Tylio\Services\DB;
use Tylio\Services\Totp;
use Tylio\Services\UserTwoFactorAuth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Endpoints for TOTP 2FA management by the logged-in user.
 *
 * All endpoints require auth + CSRF (mounted in the authenticated group).
 *
 * Setup flow:
 *   1. POST /2fa/setup/init     → generates a secret + provisioningUri
 *                                  (NOT yet persisted)
 *   2. POST /2fa/setup/confirm  → body: { secret, code } → verifies +
 *                                  enables + returns 10 plaintext backup
 *                                  codes
 *
 * Disable flow: POST /2fa/disable with body { confirm: 'DISATTIVA' }
 * Regenerate backup codes: POST /2fa/backup/regenerate (requires 2FA on).
 */
class TwoFactorController
{
    public function __construct(
        protected Auth $auth,
        protected UserTwoFactorAuth $twoFactor,
        protected DB $db,
        protected Config $config,
    ) {}

    /**
     * Issuer string embedded in the otpauth URI / shown by the user's
     * authenticator app. Reads `APP_NAME` so a fork that rebrands the
     * install (`APP_NAME=foo`) also rebrands the 2FA entry. Falls back
     * to {@see Totp::ISSUER} when unset.
     */
    protected function totpIssuer(): string
    {
        $name = trim((string)$this->config->get('APP_NAME', ''));
        return $name !== '' ? $name : Totp::ISSUER;
    }

    public function status(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return AuthController::json($response, ['error' => 'unauthenticated'], 401);

        return AuthController::json($response, [
            'enabled' => $this->twoFactor->isEnabled($userId),
            'remaining_backup_codes' => $this->twoFactor->remainingBackupCount($userId),
        ]);
    }

    public function setupInit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return AuthController::json($response, ['error' => 'unauthenticated'], 401);

        // Generate a fresh secret (preview only — NOT yet persisted). The
        // user receives it via QR code + confirmation form. The secret is
        // saved only after setupConfirm succeeds.
        $secret = Totp::generateSecret();
        $username = (string)($user['username'] ?? 'user');
        $uri = Totp::provisioningUri($username, $secret, $this->totpIssuer());

        return AuthController::json($response, [
            'secret' => $secret,
            'provisioning_uri' => $uri,
        ]);
    }

    public function setupConfirm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return AuthController::json($response, ['error' => 'unauthenticated'], 401);

        $body = (array)$request->getParsedBody();
        $secret = trim((string)($body['secret'] ?? ''));
        $code = trim((string)($body['code'] ?? ''));

        // Validate base32 secret format + 6-digit code format.
        if (!preg_match('/^[A-Z2-7]{16,64}$/', $secret)) {
            return AuthController::json($response, ['error' => 'invalid_secret'], 400);
        }
        if (!preg_match('/^\d{6}$/', $code)) {
            return AuthController::json($response, ['error' => 'invalid_code_format'], 400);
        }
        if (!Totp::verify($secret, $code)) {
            return AuthController::json($response, [
                'error' => 'invalid_code',
                'message' => 'Invalid code. Make sure your phone clock is in sync.',
            ], 400);
        }

        $backupCodes = $this->twoFactor->enable($userId, $secret);
        if ($backupCodes === null) {
            return AuthController::json($response, ['error' => 'save_failed'], 500);
        }

        $this->db->insert('audit_log', [
            'user_id' => $userId,
            'action' => '2fa.enable',
            'ip' => $this->auth->clientIp($request),
        ]);

        return AuthController::json($response, [
            'ok' => true,
            'backup_codes' => $backupCodes,
        ]);
    }

    public function disable(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return AuthController::json($response, ['error' => 'unauthenticated'], 401);

        $body = (array)$request->getParsedBody();
        $confirm = trim((string)($body['confirm'] ?? ''));
        if ($confirm !== 'DISATTIVA') {
            return AuthController::json($response, [
                'error' => 'confirm_required',
                'message' => 'To disable, type DISATTIVA in the confirmation field.',
            ], 400);
        }
        if (!$this->twoFactor->isEnabled($userId)) {
            return AuthController::json($response, ['error' => 'not_enabled'], 400);
        }
        if (!$this->twoFactor->disable($userId)) {
            return AuthController::json($response, ['error' => 'save_failed'], 500);
        }
        $this->db->insert('audit_log', [
            'user_id' => $userId,
            'action' => '2fa.disable',
            'ip' => $this->auth->clientIp($request),
        ]);
        return AuthController::json($response, ['ok' => true]);
    }

    public function regenerateBackup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return AuthController::json($response, ['error' => 'unauthenticated'], 401);

        if (!$this->twoFactor->isEnabled($userId)) {
            return AuthController::json($response, ['error' => 'not_enabled'], 400);
        }
        $codes = $this->twoFactor->regenerateBackupCodes($userId);
        if (empty($codes)) {
            return AuthController::json($response, ['error' => 'save_failed'], 500);
        }
        $this->db->insert('audit_log', [
            'user_id' => $userId,
            'action' => '2fa.regenerate_backup',
            'ip' => $this->auth->clientIp($request),
        ]);
        return AuthController::json($response, ['backup_codes' => $codes]);
    }
}
