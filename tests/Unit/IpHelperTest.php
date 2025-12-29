<?php

namespace Potelo\LaravelBlockBots\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Potelo\LaravelBlockBots\Helpers\IpHelper;

class IpHelperTest extends TestCase
{
    // ========================
    // IPv6 Detection Tests
    // ========================

    public function test_is_ipv6_with_full_ipv6()
    {
        $this->assertTrue(IpHelper::isIPv6('2001:0db8:1234:5678:aaaa:bbbb:cccc:dddd'));
    }

    public function test_is_ipv6_with_compressed_ipv6()
    {
        $this->assertTrue(IpHelper::isIPv6('2001:db8::1'));
        $this->assertTrue(IpHelper::isIPv6('::1'));
        $this->assertTrue(IpHelper::isIPv6('fe80::1'));
    }

    public function test_is_ipv6_with_ipv4()
    {
        $this->assertFalse(IpHelper::isIPv6('192.168.1.1'));
        $this->assertFalse(IpHelper::isIPv6('127.0.0.1'));
    }

    public function test_is_ipv6_with_null()
    {
        $this->assertFalse(IpHelper::isIPv6(null));
    }

    public function test_is_ipv6_with_empty_string()
    {
        $this->assertFalse(IpHelper::isIPv6(''));
    }

    public function test_is_ipv6_with_invalid()
    {
        $this->assertFalse(IpHelper::isIPv6('not-an-ip'));
        $this->assertFalse(IpHelper::isIPv6('2001:db8::gggg'));
    }

    // ========================
    // IPv4 Detection Tests
    // ========================

    public function test_is_ipv4_with_ipv4()
    {
        $this->assertTrue(IpHelper::isIPv4('192.168.1.1'));
        $this->assertTrue(IpHelper::isIPv4('127.0.0.1'));
        $this->assertTrue(IpHelper::isIPv4('0.0.0.0'));
    }

    public function test_is_ipv4_with_ipv6()
    {
        $this->assertFalse(IpHelper::isIPv4('2001:db8::1'));
        $this->assertFalse(IpHelper::isIPv4('::1'));
    }

    public function test_is_ipv4_with_null()
    {
        $this->assertFalse(IpHelper::isIPv4(null));
    }

    // ========================
    // IPv6 Prefix Normalization Tests
    // ========================

    public function test_normalize_ipv6_to_prefix_64()
    {
        // Full address should normalize to /64 prefix
        $result = IpHelper::normalizeIPv6ToPrefix('2001:db8:1234:5678:aaaa:bbbb:cccc:dddd', 64);
        $this->assertEquals('2001:db8:1234:5678::', $result);
    }

    public function test_normalize_ipv6_to_prefix_64_already_prefix()
    {
        // Already a /64 prefix should stay the same
        $result = IpHelper::normalizeIPv6ToPrefix('2001:db8:1234:5678::', 64);
        $this->assertEquals('2001:db8:1234:5678::', $result);
    }

    public function test_normalize_ipv6_to_prefix_56()
    {
        // /56 prefix should zero out more bits
        $result = IpHelper::normalizeIPv6ToPrefix('2001:db8:1234:5678:aaaa:bbbb:cccc:dddd', 56);
        $this->assertEquals('2001:db8:1234:5600::', $result);
    }

    public function test_normalize_ipv6_to_prefix_48()
    {
        // /48 prefix (common for business allocations)
        $result = IpHelper::normalizeIPv6ToPrefix('2001:db8:1234:5678:aaaa:bbbb:cccc:dddd', 48);
        $this->assertEquals('2001:db8:1234::', $result);
    }

    public function test_normalize_ipv6_to_prefix_128()
    {
        // /128 should keep the full address
        $result = IpHelper::normalizeIPv6ToPrefix('2001:db8:1234:5678:aaaa:bbbb:cccc:dddd', 128);
        $this->assertEquals('2001:db8:1234:5678:aaaa:bbbb:cccc:dddd', $result);
    }

    public function test_normalize_ipv6_to_prefix_with_compressed_address()
    {
        // Compressed addresses should work too
        $result = IpHelper::normalizeIPv6ToPrefix('2001:db8::1', 64);
        $this->assertEquals('2001:db8::', $result);
    }

    public function test_normalize_ipv6_to_prefix_localhost()
    {
        // ::1 with /64 should become ::
        $result = IpHelper::normalizeIPv6ToPrefix('::1', 64);
        $this->assertEquals('::', $result);
    }

    public function test_normalize_ipv6_returns_null_for_ipv4()
    {
        $this->assertNull(IpHelper::normalizeIPv6ToPrefix('192.168.1.1', 64));
    }

    public function test_normalize_ipv6_returns_null_for_invalid()
    {
        $this->assertNull(IpHelper::normalizeIPv6ToPrefix('not-an-ip', 64));
    }

    // ========================
    // getTrackableIp Tests
    // ========================

    public function test_get_trackable_ip_returns_ipv4_unchanged()
    {
        $this->assertEquals('192.168.1.1', IpHelper::getTrackableIp('192.168.1.1', 64));
        $this->assertEquals('127.0.0.1', IpHelper::getTrackableIp('127.0.0.1', 64));
    }

    public function test_get_trackable_ip_normalizes_ipv6_to_prefix()
    {
        $result = IpHelper::getTrackableIp('2001:db8:1234:5678:aaaa:bbbb:cccc:dddd', 64);
        $this->assertEquals('2001:db8:1234:5678::', $result);
    }

    public function test_get_trackable_ip_respects_prefix_length()
    {
        $ip = '2001:db8:1234:5678:aaaa:bbbb:cccc:dddd';

        $this->assertEquals('2001:db8:1234:5678::', IpHelper::getTrackableIp($ip, 64));
        $this->assertEquals('2001:db8:1234:5600::', IpHelper::getTrackableIp($ip, 56));
        $this->assertEquals('2001:db8:1234::', IpHelper::getTrackableIp($ip, 48));
    }

    public function test_get_trackable_ip_returns_null_for_null()
    {
        $this->assertNull(IpHelper::getTrackableIp(null));
    }

    public function test_get_trackable_ip_returns_null_for_empty()
    {
        $this->assertNull(IpHelper::getTrackableIp(''));
    }

    public function test_get_trackable_ip_returns_original_for_invalid()
    {
        // Invalid IPs are returned as-is (might be a hostname)
        $this->assertEquals('some-hostname', IpHelper::getTrackableIp('some-hostname', 64));
    }

    // ========================
    // isSameIPv6Prefix Tests
    // ========================

    public function test_is_same_ipv6_prefix_returns_true_for_same_prefix()
    {
        $ip1 = '2001:db8:1234:5678:aaaa:bbbb:cccc:dddd';
        $ip2 = '2001:db8:1234:5678:1111:2222:3333:4444';

        $this->assertTrue(IpHelper::isSameIPv6Prefix($ip1, $ip2, 64));
    }

    public function test_is_same_ipv6_prefix_returns_false_for_different_prefix()
    {
        $ip1 = '2001:db8:1234:5678:aaaa:bbbb:cccc:dddd';
        $ip2 = '2001:db8:1234:9999:1111:2222:3333:4444';

        $this->assertFalse(IpHelper::isSameIPv6Prefix($ip1, $ip2, 64));
    }

    public function test_is_same_ipv6_prefix_with_larger_prefix()
    {
        // These are different in /64 but same in /48
        $ip1 = '2001:db8:1234:5678:aaaa:bbbb:cccc:dddd';
        $ip2 = '2001:db8:1234:9999:1111:2222:3333:4444';

        $this->assertFalse(IpHelper::isSameIPv6Prefix($ip1, $ip2, 64));
        $this->assertTrue(IpHelper::isSameIPv6Prefix($ip1, $ip2, 48));
    }

    public function test_is_same_ipv6_prefix_returns_false_for_ipv4()
    {
        $this->assertFalse(IpHelper::isSameIPv6Prefix('192.168.1.1', '192.168.1.2', 64));
    }

    // ========================
    // expandIPv6 Tests
    // ========================

    public function test_expand_ipv6_full_address()
    {
        $result = IpHelper::expandIPv6('2001:0db8:1234:5678:aaaa:bbbb:cccc:dddd');
        $this->assertEquals('2001:0db8:1234:5678:aaaa:bbbb:cccc:dddd', $result);
    }

    public function test_expand_ipv6_compressed_address()
    {
        $result = IpHelper::expandIPv6('2001:db8::1');
        $this->assertEquals('2001:0db8:0000:0000:0000:0000:0000:0001', $result);
    }

    public function test_expand_ipv6_localhost()
    {
        $result = IpHelper::expandIPv6('::1');
        $this->assertEquals('0000:0000:0000:0000:0000:0000:0000:0001', $result);
    }

    public function test_expand_ipv6_returns_null_for_ipv4()
    {
        $this->assertNull(IpHelper::expandIPv6('192.168.1.1'));
    }

    // ========================
    // ipInCidr Tests
    // ========================

    public function test_ip_in_cidr_ipv4_match()
    {
        $this->assertTrue(IpHelper::ipInCidr('192.168.1.100', '192.168.1.0/24'));
        $this->assertTrue(IpHelper::ipInCidr('192.168.1.1', '192.168.0.0/16'));
    }

    public function test_ip_in_cidr_ipv4_no_match()
    {
        $this->assertFalse(IpHelper::ipInCidr('192.168.2.1', '192.168.1.0/24'));
        $this->assertFalse(IpHelper::ipInCidr('10.0.0.1', '192.168.0.0/16'));
    }

    public function test_ip_in_cidr_ipv6_match()
    {
        $this->assertTrue(IpHelper::ipInCidr('2001:db8:1234:5678::1', '2001:db8:1234:5678::/64'));
        $this->assertTrue(IpHelper::ipInCidr('2001:db8:1234:5678:aaaa:bbbb:cccc:dddd', '2001:db8:1234:5678::/64'));
    }

    public function test_ip_in_cidr_ipv6_no_match()
    {
        $this->assertFalse(IpHelper::ipInCidr('2001:db8:1234:9999::1', '2001:db8:1234:5678::/64'));
    }

    public function test_ip_in_cidr_exact_match()
    {
        $this->assertTrue(IpHelper::ipInCidr('192.168.1.1', '192.168.1.1'));
        $this->assertFalse(IpHelper::ipInCidr('192.168.1.2', '192.168.1.1'));
    }

    // ========================
    // Edge Cases
    // ========================

    public function test_handles_link_local_ipv6()
    {
        $this->assertTrue(IpHelper::isIPv6('fe80::1'));
        $result = IpHelper::normalizeIPv6ToPrefix('fe80::1', 64);
        $this->assertEquals('fe80::', $result);
    }

    public function test_handles_ipv4_mapped_ipv6()
    {
        // ::ffff:192.168.1.1 is an IPv4-mapped IPv6 address
        $ip = '::ffff:192.168.1.1';
        $this->assertTrue(IpHelper::isIPv6($ip));
    }

    public function test_prefix_length_clamping()
    {
        // Test that prefix length is clamped to valid range
        $ip = '2001:db8:1234:5678:aaaa:bbbb:cccc:dddd';
        
        // Prefix 0 should be clamped to 1
        $result = IpHelper::normalizeIPv6ToPrefix($ip, 0);
        $this->assertNotNull($result);
        
        // Prefix > 128 should be clamped to 128
        $result = IpHelper::normalizeIPv6ToPrefix($ip, 200);
        $this->assertEquals('2001:db8:1234:5678:aaaa:bbbb:cccc:dddd', $result);
    }

    // ========================
    // ipInAnyCidr Tests
    // ========================

    public function test_ip_in_any_cidr_matches_first_range()
    {
        $cidrs = [
            '192.168.1.0/24',
            '10.0.0.0/8',
        ];
        $this->assertTrue(IpHelper::ipInAnyCidr('192.168.1.100', $cidrs));
    }

    public function test_ip_in_any_cidr_matches_second_range()
    {
        $cidrs = [
            '192.168.1.0/24',
            '10.0.0.0/8',
        ];
        $this->assertTrue(IpHelper::ipInAnyCidr('10.50.100.200', $cidrs));
    }

    public function test_ip_in_any_cidr_no_match()
    {
        $cidrs = [
            '192.168.1.0/24',
            '10.0.0.0/8',
        ];
        $this->assertFalse(IpHelper::ipInAnyCidr('172.16.0.1', $cidrs));
    }

    public function test_ip_in_any_cidr_empty_array()
    {
        $this->assertFalse(IpHelper::ipInAnyCidr('192.168.1.1', []));
    }

    public function test_ip_in_any_cidr_with_single_ip()
    {
        $cidrs = [
            '192.168.1.100/32',
        ];
        $this->assertTrue(IpHelper::ipInAnyCidr('192.168.1.100', $cidrs));
        $this->assertFalse(IpHelper::ipInAnyCidr('192.168.1.101', $cidrs));
    }

    public function test_ip_in_any_cidr_with_ipv6_ranges()
    {
        $cidrs = [
            '2001:db8:1234:5678::/64',
            '2001:db8:abcd::/48',
        ];
        $this->assertTrue(IpHelper::ipInAnyCidr('2001:db8:1234:5678::1', $cidrs));
        $this->assertTrue(IpHelper::ipInAnyCidr('2001:db8:abcd:1234::1', $cidrs));
        $this->assertFalse(IpHelper::ipInAnyCidr('2001:db8:9999::1', $cidrs));
    }

    public function test_ip_in_any_cidr_real_gptbot_range()
    {
        // Test with actual GPTBot IP range
        $cidrs = [
            '132.196.86.0/24',
            '172.182.202.0/25',
        ];
        $this->assertTrue(IpHelper::ipInAnyCidr('132.196.86.100', $cidrs));
        $this->assertTrue(IpHelper::ipInAnyCidr('172.182.202.50', $cidrs));
        $this->assertFalse(IpHelper::ipInAnyCidr('172.182.202.200', $cidrs)); // Outside /25
    }

    public function test_ip_in_any_cidr_real_claudebot_range()
    {
        // Test with actual ClaudeBot IP range
        $cidrs = [
            '160.79.104.0/21',
        ];
        $this->assertTrue(IpHelper::ipInAnyCidr('160.79.104.1', $cidrs));
        $this->assertTrue(IpHelper::ipInAnyCidr('160.79.111.254', $cidrs)); // Last IP in /21
        $this->assertFalse(IpHelper::ipInAnyCidr('160.79.112.1', $cidrs)); // Outside /21
    }

    public function test_ip_in_any_cidr_real_duckassistbot_individual_ip()
    {
        // Test with DuckAssistBot individual IPs (as /32)
        $cidrs = [
            '57.152.72.128/32',
            '51.8.253.152/32',
        ];
        $this->assertTrue(IpHelper::ipInAnyCidr('57.152.72.128', $cidrs));
        $this->assertTrue(IpHelper::ipInAnyCidr('51.8.253.152', $cidrs));
        $this->assertFalse(IpHelper::ipInAnyCidr('57.152.72.129', $cidrs));
    }
}
