<?php
declare(strict_types=1);

namespace Tylio\Services;

use Tylio\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class Auth
{
    public const SESSION_LIFETIME_DEFAULT = 86400; // 1 day
    /**
     * Base suffix. On the wire, the cookie becomes `__Host-tylio_sid` on
     * HTTPS (a prefix the browser enforces to be Secure + Path=/ +
     * Domain-less), or `tylio_sid` on HTTP (local dev). See `cookieName()`.
     */
    public const COOKIE_NAME = 'tylio_sid';
    public const COOKIE_NAME_SECURE = '__Host-tylio_sid';

    protected ?array $user = null;
    protected ?array $session = null;

    public function __construct(protected DB $db, protected Config $config) {}

    /**
     * Cookie name to SET for the current request. Uses the `__Host-`
     * prefix on HTTPS (safer), the plain name on HTTP (local dev).
     */
    public function cookieName(ServerRequestInterface $request): string
    {
        return self::isHttps($request) ? self::COOKIE_NAME_SECURE : self::COOKIE_NAME;
    }

    public static function isHttps(ServerRequestInterface $request): bool
    {
        return $request->getUri()->getScheme() === 'https';
    }

    public function hashPassword(string $plain): string
    {
        // Argon2id when available (sodium), bcrypt as a fallback (never used in practice on PHP 8+).
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($plain, $algo);
    }

    public function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public function findUserByUsername(string $username): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE username = ?', [$username]);
    }

    /**
     * Create a user session. If $pending2fa=true the session exists but is
     * not yet authenticated: AuthMiddleware rejects it until the client
     * completes /api/auth/login/2fa (which promotes it to non-pending).
     */
    public function createSession(int $userId, ServerRequestInterface $request, bool $pending2fa = false): array
    {
        $sid = self::randomToken(32);
        $csrf = self::randomToken(24);
        $lifetime = $this->config->int('SESSION_LIFETIME', self::SESSION_LIFETIME_DEFAULT);
        $expiresAt = (new \DateTimeImmutable('+' . $lifetime . ' seconds'))->format('Y-m-d H:i:s');

        $this->db->insert('sessions', [
            'id' => $sid,
            'user_id' => $userId,
            'csrf_token' => $csrf,
            'user_agent' => substr((string)($request->getHeaderLine('User-Agent')), 0, 255),
            'ip' => $this->clientIp($request),
            'expires_at' => $expiresAt,
            'pending_2fa' => $pending2fa ? 1 : 0,
        ]);
        // last_login_at: updated only when login is fully completed
        // (post-2FA), not on the first step. See clearPending2fa() for that.
        if (!$pending2fa) {
            $this->db->update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $userId]);
        }

        return ['id' => $sid, 'csrf' => $csrf, 'expires_at' => $expiresAt];
    }

    public function destroySession(string $sid): void
    {
        $this->db->query('DELETE FROM sessions WHERE id = ?', [$sid]);
    }

    /**
     * Load a COMPLETE session (post-2FA). Rejects sessions where
     * pending_2fa=1 — those are only accepted by loadPendingFromRequest()
     * for the login step-2 flow.
     */
    public function loadFromRequest(ServerRequestInterface $request): bool
    {
        $cookies = $request->getCookieParams();
        $sid = $cookies[self::COOKIE_NAME_SECURE] ?? $cookies[self::COOKIE_NAME] ?? null;
        if (!$sid) return false;

        $row = $this->db->one(
            'SELECT * FROM sessions
             WHERE id = ? AND expires_at > datetime("now")
               AND COALESCE(pending_2fa, 0) = 0',
            [$sid],
        );
        if (!$row) return false;

        $user = $this->db->one(
            'SELECT id, username, created_at, last_login_at FROM users WHERE id = ?',
            [$row['user_id']],
        );
        if (!$user) return false;

        $this->session = $row;
        $this->user = $user;
        $this->db->update('sessions', ['last_seen_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $sid]);
        return true;
    }

    /**
     * Load a PENDING session (step 1 password OK, step 2 TOTP not yet
     * passed). Used exclusively by AuthController::login2fa to validate
     * the code and promote the session.
     */
    public function loadPendingFromRequest(ServerRequestInterface $request): ?array
    {
        $cookies = $request->getCookieParams();
        $sid = $cookies[self::COOKIE_NAME_SECURE] ?? $cookies[self::COOKIE_NAME] ?? null;
        if (!$sid) return null;
        $row = $this->db->one(
            'SELECT * FROM sessions
             WHERE id = ? AND expires_at > datetime("now")
               AND pending_2fa = 1',
            [$sid],
        );
        return $row ?: null;
    }

    /** Promote a pending session to complete (post 2FA OK). */
    public function clearPending2fa(string $sid): void
    {
        $this->db->update('sessions', ['pending_2fa' => 0], 'id = :id', ['id' => $sid]);
        // last_login_at was skipped in createSession when pending=true;
        // update it now that login is truly complete (step 2 passed).
        $row = $this->db->one('SELECT user_id FROM sessions WHERE id = ?', [$sid]);
        if ($row && isset($row['user_id'])) {
            $this->db->update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int)$row['user_id']]);
        }
    }

    public function user(): ?array { return $this->user; }
    public function session(): ?array { return $this->session; }
    public function csrf(): ?string { return $this->session['csrf_token'] ?? null; }
    public function check(): bool { return $this->user !== null; }

    public function buildSessionCookie(ServerRequestInterface $request, string $sid, ?int $expiresAt = null): string
    {
        $lifetime = $this->config->int('SESSION_LIFETIME', self::SESSION_LIFETIME_DEFAULT);
        $expires = $expiresAt ?? (time() + $lifetime);
        $secure = self::isHttps($request);
        $name = $this->cookieName($request);
        $parts = [
            $name . '=' . $sid,
            'Path=/',
            'Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT',
            'Max-Age=' . $lifetime,
            'HttpOnly',
            'SameSite=Strict',
        ];
        // `Secure` is required with the `__Host-` prefix, and obviously only on HTTPS.
        if ($secure) $parts[] = 'Secure';
        return implode('; ', $parts);
    }

    /**
     * To guarantee a clean logout across browsers we emit TWO Set-Cookie
     * headers: one with the current name (prefixed or not) and one with
     * the legacy non-prefixed name. This clears any residual cookie. The
     * caller must use withAddedHeader.
     *
     * @return list<string>
     */
    public function buildClearCookies(ServerRequestInterface $request): array
    {
        $secure = self::isHttps($request);
        $names = $secure ? [self::COOKIE_NAME_SECURE, self::COOKIE_NAME] : [self::COOKIE_NAME];
        $cookies = [];
        foreach ($names as $name) {
            $parts = [
                $name . '=',
                'Path=/',
                'Expires=Thu, 01 Jan 1970 00:00:00 GMT',
                'Max-Age=0',
                'HttpOnly',
                'SameSite=Strict',
            ];
            // The `__Host-` prefix requires Secure even to be "consumed" by the browser as a delete.
            if ($secure || str_starts_with($name, '__Host-')) $parts[] = 'Secure';
            $cookies[] = implode('; ', $parts);
        }
        return $cookies;
    }

    public function clientIp(ServerRequestInterface $request): string
    {
        $params = $request->getServerParams();
        $ip = (string)($params['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    public function logAttempt(string $ip, ?string $username, bool $success): void
    {
        $this->db->insert('login_attempts', [
            'ip' => $ip,
            'username' => $username,
            'success' => $success ? 1 : 0,
        ]);
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public function purgeExpiredSessions(): void
    {
        $this->db->query('DELETE FROM sessions WHERE expires_at < datetime("now")');
    }
}
