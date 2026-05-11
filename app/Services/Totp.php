<?php
declare(strict_types=1);

namespace Tylio\Services;

/**
 * TOTP (Time-Based One-Time Password) — RFC 6238.
 *
 * Compatible with any authenticator app (Google Authenticator, Aegis,
 * 1Password, Authy, Microsoft Authenticator, etc.).
 *
 * Standard parameters:
 *   - hash: HMAC-SHA1
 *   - time step: 30 seconds
 *   - digits: 6
 *   - secret: base32 (160 bit = 32 chars)
 *
 * Pure PHP, no external dependencies.
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    public const ISSUER = 'tylio.app';

    /**
     * Generate a fresh TOTP secret: 20 random bytes encoded in base32.
     * 160 bits of entropy = RFC 4226 standard.
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Verify an OTP code against a secret. Window = 1 = tolerance of
     * ±1 time-step (±30s) to absorb small clock drift between server and
     * the user's device.
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if ($code === null || strlen($code) !== self::DIGITS) return false;

        $now = (int)floor(time() / self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::generate($secret, $now + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function generate(string $secret, ?int $timeSlot = null): string
    {
        $timeSlot ??= (int)floor(time() / self::PERIOD);
        $key = self::base32Decode($secret);
        // 8-byte big-endian unsigned counter
        $counter = pack('N*', 0) . pack('N*', $timeSlot);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0xf;
        $value =
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff);
        $code = $value % (10 ** self::DIGITS);
        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Standard otpauth:// URI used to generate the QR code scanned by the
     * authenticator app. RFC 6238 + Google Authenticator key URI format.
     *
     * @param string $accountName typically the username (e.g. "alice")
     */
    public static function provisioningUri(string $accountName, string $secret, string $issuer = self::ISSUER): string
    {
        $label = rawurlencode($issuer . ':' . $accountName);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0; $i < strlen($data); $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i < strlen($bits); $i += 5) {
            $chunk = substr($bits, $i, 5);
            if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0');
            $out .= $alphabet[bindec($chunk)];
        }
        return $out;
    }

    private static function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        $bits = '';
        for ($i = 0; $i < strlen($secret); $i++) {
            $idx = strpos($alphabet, $secret[$i]);
            if ($idx === false) continue;
            $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }
}
