<?php
declare(strict_types=1);

namespace Tylio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Uri;
use Tylio\Services\Auth;

/**
 * Auth — covers the security-critical aspects that do NOT depend on the DB:
 *   - Argon2id hashing (parameters, round-trip, mismatch)
 *   - cookie name selection based on HTTPS (`__Host-` prefix)
 *   - HTTPS detection via PSR-7 URI (post-X-Forwarded-Proto override)
 */
final class AuthTest extends TestCase
{
    private Auth $auth;

    protected function setUp(): void
    {
        $this->auth = (new ReflectionClass(Auth::class))->newInstanceWithoutConstructor();
    }

    // ===== hashing =====================================================

    public function test_hash_uses_argon2id_when_available(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            $this->markTestSkipped('PHP build without Argon2id (sodium missing)');
        }
        $hash = $this->auth->hashPassword('s3cret-pass');
        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function test_hash_verify_roundtrip(): void
    {
        $hash = $this->auth->hashPassword('correct-horse-battery-staple');
        $this->assertTrue($this->auth->verifyPassword('correct-horse-battery-staple', $hash));
    }

    public function test_verify_rejects_wrong_password(): void
    {
        $hash = $this->auth->hashPassword('right');
        $this->assertFalse($this->auth->verifyPassword('wrong', $hash));
        $this->assertFalse($this->auth->verifyPassword('', $hash));
    }

    public function test_each_hash_is_different_due_to_salt(): void
    {
        $h1 = $this->auth->hashPassword('same-password');
        $h2 = $this->auth->hashPassword('same-password');
        $this->assertNotSame($h1, $h2, 'Argon2id generates a random salt — hashes must differ');
        // But both must verify against the same password
        $this->assertTrue($this->auth->verifyPassword('same-password', $h1));
        $this->assertTrue($this->auth->verifyPassword('same-password', $h2));
    }

    public function test_random_token_is_hex_and_correct_length(): void
    {
        $t = Auth::randomToken(16);
        $this->assertSame(32, strlen($t)); // 16 bytes → 32 hex chars
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $t);

        $t2 = Auth::randomToken(16);
        $this->assertNotSame($t, $t2);
    }

    // ===== HTTPS detection + cookie naming =============================

    public function test_isHttps_true_when_scheme_https(): void
    {
        $req = $this->makeRequest('https');
        $this->assertTrue(Auth::isHttps($req));
    }

    public function test_isHttps_false_when_scheme_http(): void
    {
        $req = $this->makeRequest('http');
        $this->assertFalse(Auth::isHttps($req));
    }

    public function test_cookie_name_uses_host_prefix_on_https(): void
    {
        $req = $this->makeRequest('https');
        $this->assertSame('__Host-tylio_sid', $this->auth->cookieName($req));
    }

    public function test_cookie_name_plain_on_http(): void
    {
        $req = $this->makeRequest('http');
        $this->assertSame('tylio_sid', $this->auth->cookieName($req));
    }

    // ===== utilities ===================================================

    private function makeRequest(string $scheme): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        $uri = (new Uri($scheme, 'tylio.example', $scheme === 'https' ? 443 : 80, '/'));
        return $factory->createServerRequest('GET', $uri);
    }
}
