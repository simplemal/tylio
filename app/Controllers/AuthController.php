<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Services\Auth;
use Tylio\Services\DB;
use Tylio\Services\RateLimit;
use Tylio\Services\UserTwoFactorAuth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Session-cookie authentication: `/api/auth/login` (with optional TOTP
 * step at `/api/auth/login/2fa`), `/api/auth/me`, `/api/auth/logout`.
 *
 * **Extendable by design.** Non-`final` and exposes its dependencies as
 * `protected` so an overlay (e.g. the multi-tenant SaaS) can subclass
 * with a tenant-aware `Auth` service. Public method signatures are
 * stable across minor versions.
 */
class AuthController
{
    public function __construct(
        protected Auth $auth,
        protected RateLimit $limit,
        protected DB $db,
        protected UserTwoFactorAuth $twoFactor,
    ) {}

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $ip = $this->auth->clientIp($request);

        if ($username === '' || $password === '') {
            return self::json($response, ['error' => 'missing_credentials'], 400);
        }

        $wait = $this->limit->loginCheck($ip);
        if ($wait !== null) {
            return self::json($response, [
                'error' => 'rate_limited',
                'retry_after' => $wait,
            ], 429);
        }

        $user = $this->auth->findUserByUsername($username);
        if (!$user || !$this->auth->verifyPassword($password, $user['password_hash'])) {
            $this->auth->logAttempt($ip, $username, false);
            usleep(150_000);
            return self::json($response, ['error' => 'invalid_credentials'], 401);
        }

        $this->limit->clearForIp($ip);
        $this->auth->logAttempt($ip, $username, true);

        // If the user has 2FA enabled we create a PENDING session and ask
        // for step 2 (TOTP / backup code) via /api/auth/login/2fa.
        // Otherwise a fully authenticated session is created right away
        // (back-compat: users who never enabled 2FA).
        $needs2fa = $this->twoFactor->isEnabled((int)$user['id']);
        $session = $this->auth->createSession((int)$user['id'], $request, $needs2fa);
        $this->db->insert('audit_log', [
            'user_id' => $user['id'],
            'action' => $needs2fa ? 'login.step1' : 'login',
            'ip' => $ip,
        ]);

        $payload = [
            'user' => ['id' => $user['id'], 'username' => $user['username']],
            'csrf' => $session['csrf'],
            'expires_at' => $session['expires_at'],
        ];
        if ($needs2fa) {
            $payload['requires_2fa'] = true;
        }
        return self::json($response, $payload)
            ->withHeader('Set-Cookie', $this->auth->buildSessionCookie($request, $session['id']));
    }

    /**
     * Login step 2: the user already has a pending_2fa=1 session; the
     * request carries `code` (6-digit TOTP) or `backup` (true) +
     * `code` (8 hex backup code). If valid, promote the session to
     * non-pending and return the same shape as /me. If invalid: 401 +
     * retry (the pending session stays alive until it expires).
     */
    public function login2fa(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pending = $this->auth->loadPendingFromRequest($request);
        if ($pending === null) {
            return self::json($response, ['error' => 'no_pending_session'], 401);
        }
        $userId = (int)($pending['user_id'] ?? 0);
        if ($userId <= 0) {
            return self::json($response, ['error' => 'invalid_session'], 401);
        }

        $body = (array)$request->getParsedBody();
        $code = trim((string)($body['code'] ?? ''));
        $useBackup = !empty($body['backup']);

        // Rate limit here too: brute-forcing the TOTP code averages ~1M
        // attempts (10^6); with a 250 ms delay plus IP-based rate limit it
        // becomes impractical.
        $ip = $this->auth->clientIp($request);
        $wait = $this->limit->loginCheck($ip);
        if ($wait !== null) {
            return self::json($response, ['error' => 'rate_limited', 'retry_after' => $wait], 429);
        }

        $ok = $useBackup
            ? $this->twoFactor->verifyAndConsumeBackupCode($userId, $code)
            : $this->twoFactor->verifyTotp($userId, $code);

        if (!$ok) {
            usleep(250_000); // costo costante anti-enumeration
            $this->auth->logAttempt($ip, (string)($pending['user_id'] ?? ''), false);
            return self::json($response, [
                'error' => $useBackup ? 'invalid_backup_code' : 'invalid_2fa_code',
            ], 401);
        }

        $this->limit->clearForIp($ip);
        $this->auth->clearPending2fa((string)$pending['id']);
        $this->db->insert('audit_log', [
            'user_id' => $userId,
            'action' => $useBackup ? 'login.step2.backup' : 'login.step2.totp',
            'ip' => $ip,
        ]);

        $user = $this->db->one('SELECT id, username FROM users WHERE id = ?', [$userId]);
        return self::json($response, [
            'user' => $user ? ['id' => $user['id'], 'username' => $user['username']] : null,
            'csrf' => $pending['csrf_token'],
            'expires_at' => $pending['expires_at'],
        ]);
    }

    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->auth->loadFromRequest($request)) {
            return self::json($response, ['error' => 'unauthenticated'], 401);
        }
        $user = $this->auth->user();
        return self::json($response, [
            'user' => ['id' => $user['id'], 'username' => $user['username']],
            'csrf' => $this->auth->csrf(),
        ]);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $request->getAttribute('session');
        if ($session) {
            $this->auth->destroySession($session['id']);
            $this->db->insert('audit_log', [
                'user_id' => $session['user_id'],
                'action' => 'logout',
                'ip' => $this->auth->clientIp($request),
            ]);
        }
        $resp = self::json($response, ['ok' => true]);
        foreach ($this->auth->buildClearCookies($request) as $c) {
            $resp = $resp->withAddedHeader('Set-Cookie', $c);
        }
        return $resp;
    }

    public static function json(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
