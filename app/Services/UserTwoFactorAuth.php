<?php
declare(strict_types=1);

namespace Tylio\Services;

/**
 * TOTP 2FA for the site user (once signed in as admin).
 *
 * Storage: columns `users.totp_secret`, `users.totp_backup_codes`,
 * `users.totp_enabled_at` (see migration 0004).
 *
 * Backup codes schema: a JSON array of SHA-256 hashes of the plaintext codes.
 * The plaintext is shown to the user only once at setup/regenerate; only the
 * hash is stored on disk. `verifyAndConsume` removes a hash after a match.
 *
 * Typical flow:
 *   1. setupInit($userId)            → returns secret + provisioningUri
 *                                       (preview only, NOT saved yet)
 *   2. setupConfirm($userId, $secret, $code) → verify `code` against `secret`,
 *                                              save the secret and return the
 *                                              first set of plaintext backup
 *                                              codes.
 *   3. verifyTotp($userId, $code)    → checked on each login (step 2)
 *   4. disable($userId)              → wipe secret + backup codes (full revoke)
 */
final class UserTwoFactorAuth
{
    public function __construct(private DB $db) {}

    public function isEnabled(int $userId): bool
    {
        $row = $this->db->one('SELECT totp_secret FROM users WHERE id = ?', [$userId]);
        if (!$row) return false;
        return (string)($row['totp_secret'] ?? '') !== '';
    }

    public function totpSecret(int $userId): string
    {
        $row = $this->db->one('SELECT totp_secret FROM users WHERE id = ?', [$userId]);
        return (string)($row['totp_secret'] ?? '');
    }

    public function verifyTotp(int $userId, string $code): bool
    {
        $secret = $this->totpSecret($userId);
        if ($secret === '') return false;
        return Totp::verify($secret, $code);
    }

    /**
     * Verify a backup code (single-use): if it matches one of the stored
     * hashes, remove it and return true. SHA-256 hash of the plaintext.
     */
    public function verifyAndConsumeBackupCode(int $userId, string $code): bool
    {
        $code = trim($code);
        if ($code === '') return false;
        $codes = $this->backupCodeHashes($userId);
        if (empty($codes)) return false;
        $hash = hash('sha256', $code);
        $idx = array_search($hash, $codes, true);
        if ($idx === false) return false;
        unset($codes[$idx]);
        $codes = array_values($codes);
        return $this->saveBackupCodeHashes($userId, $codes);
    }

    /** @return list<string> SHA-256 hashes of still-valid backup codes */
    public function backupCodeHashes(int $userId): array
    {
        $row = $this->db->one('SELECT totp_backup_codes FROM users WHERE id = ?', [$userId]);
        if (!$row) return [];
        $raw = (string)($row['totp_backup_codes'] ?? '[]');
        $arr = json_decode($raw, true);
        if (!is_array($arr)) return [];
        return array_values(array_filter(array_map('strval', $arr)));
    }

    public function remainingBackupCount(int $userId): int
    {
        return count($this->backupCodeHashes($userId));
    }

    /**
     * Generate N new plaintext backup codes + persist their hashes. Returns
     * the plaintext codes (shown only once). Replaces any previous codes —
     * regenerating invalidates the old ones.
     *
     * @return list<string>
     */
    public function regenerateBackupCodes(int $userId, int $n = 10): array
    {
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < $n; $i++) {
            // 8 hex chars = 32 bits of entropy. Enough as a backup (it does
            // not replace the password, and keeps bots out for years).
            $code = bin2hex(random_bytes(4));
            $plain[] = $code;
            $hashes[] = hash('sha256', $code);
        }
        if (!$this->saveBackupCodeHashes($userId, $hashes)) return [];
        return $plain;
    }

    /**
     * Save the secret + enable 2FA. Called after setupConfirm verifies the
     * TOTP code. Also generates the first set of backup codes.
     *
     * @return list<string>|null plaintext backup codes (shown only once),
     *                            null on error.
     */
    public function enable(int $userId, string $base32Secret): ?array
    {
        // Atomically save secret + timestamp + hashed backup codes.
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < 10; $i++) {
            $code = bin2hex(random_bytes(4));
            $plain[] = $code;
            $hashes[] = hash('sha256', $code);
        }
        $ok = $this->db->update('users', [
            'totp_secret' => $base32Secret,
            'totp_backup_codes' => json_encode($hashes, JSON_UNESCAPED_SLASHES),
            'totp_enabled_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $userId]);
        return $ok ? $plain : null;
    }

    /** Wipe the secret + all backup codes. Disables 2FA for the user. */
    public function disable(int $userId): bool
    {
        return (bool)$this->db->update('users', [
            'totp_secret' => '',
            'totp_backup_codes' => '[]',
            'totp_enabled_at' => null,
        ], 'id = :id', ['id' => $userId]);
    }

    /** @param list<string> $hashes */
    private function saveBackupCodeHashes(int $userId, array $hashes): bool
    {
        return (bool)$this->db->update('users', [
            'totp_backup_codes' => json_encode($hashes, JSON_UNESCAPED_SLASHES),
        ], 'id = :id', ['id' => $userId]);
    }
}
