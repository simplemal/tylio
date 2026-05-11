<?php
declare(strict_types=1);

namespace Tylio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tylio\Util\Net;

/**
 * Net::ipInRanges is critical for the bootstrap's trust-proxy logic: a bug
 * here would let an external client spoof X-Forwarded-For and pretend to
 * come from Cloudflare/pfSense, bypassing rate-limits and geo-restrictions.
 */
final class NetTest extends TestCase
{
    public function test_exact_ip_match_no_cidr(): void
    {
        $this->assertTrue(Net::ipInRanges('1.2.3.4', ['1.2.3.4']));
        $this->assertFalse(Net::ipInRanges('1.2.3.4', ['1.2.3.5']));
    }

    public function test_ipv4_cidr_match(): void
    {
        $this->assertTrue(Net::ipInRanges('10.55.15.17', ['10.55.15.0/24']));
        $this->assertTrue(Net::ipInRanges('10.55.15.255', ['10.55.15.0/24']));
        $this->assertFalse(Net::ipInRanges('10.55.16.1', ['10.55.15.0/24']));
        $this->assertFalse(Net::ipInRanges('10.0.0.1', ['10.55.15.0/24']));
    }

    public function test_ipv4_large_cidr(): void
    {
        // /16
        $this->assertTrue(Net::ipInRanges('192.168.1.1', ['192.168.0.0/16']));
        $this->assertTrue(Net::ipInRanges('192.168.255.254', ['192.168.0.0/16']));
        $this->assertFalse(Net::ipInRanges('192.169.0.1', ['192.168.0.0/16']));
    }

    public function test_ipv4_cloudflare_range(): void
    {
        // One of the real CF ranges present in TRUSTED_PROXIES
        $this->assertTrue(Net::ipInRanges('173.245.50.1', ['173.245.48.0/20']));
        $this->assertFalse(Net::ipInRanges('173.246.0.1', ['173.245.48.0/20']));
    }

    public function test_multiple_ranges_first_match_wins(): void
    {
        $ranges = ['10.0.0.0/8', '192.168.0.0/16', '172.16.0.0/12'];
        $this->assertTrue(Net::ipInRanges('10.5.6.7', $ranges));
        $this->assertTrue(Net::ipInRanges('192.168.50.1', $ranges));
        $this->assertTrue(Net::ipInRanges('172.16.0.1', $ranges));
        $this->assertFalse(Net::ipInRanges('1.2.3.4', $ranges));
    }

    public function test_invalid_ip_returns_false(): void
    {
        $this->assertFalse(Net::ipInRanges('not-an-ip', ['10.0.0.0/8']));
        $this->assertFalse(Net::ipInRanges('', ['10.0.0.0/8']));
        $this->assertFalse(Net::ipInRanges('999.999.999.999', ['10.0.0.0/8']));
    }

    public function test_invalid_cidr_returns_false(): void
    {
        $this->assertFalse(Net::ipInRanges('10.0.0.1', ['not-a-cidr']));
    }

    public function test_empty_ranges_returns_false(): void
    {
        $this->assertFalse(Net::ipInRanges('1.2.3.4', []));
    }

    public function test_ipv6_cidr_match(): void
    {
        $this->assertTrue(Net::ipInRanges('2001:db8::1', ['2001:db8::/32']));
        $this->assertFalse(Net::ipInRanges('2001:db9::1', ['2001:db8::/32']));
    }

    public function test_ipv4_and_ipv6_mismatch_returns_false(): void
    {
        // An IPv4 against an IPv6 range (or vice versa) must not match.
        $this->assertFalse(Net::ipInRanges('1.2.3.4', ['2001:db8::/32']));
        $this->assertFalse(Net::ipInRanges('2001:db8::1', ['10.0.0.0/8']));
    }

    public function test_zero_mask_matches_all(): void
    {
        // /0 matches the entire address space — useful as a canary, not as a prod config.
        $this->assertTrue(Net::ipInRanges('1.2.3.4', ['0.0.0.0/0']));
        $this->assertTrue(Net::ipInRanges('255.255.255.255', ['0.0.0.0/0']));
    }
}
