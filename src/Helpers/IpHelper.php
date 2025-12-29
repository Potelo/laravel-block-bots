<?php

namespace Potelo\LaravelBlockBots\Helpers;

/**
 * Helper class for IP address operations, with special handling for IPv6 prefixes.
 *
 * IPv6 users typically receive a prefix (e.g., /64 or /56) from their ISP,
 * giving them millions of different IP addresses. This class normalizes
 * IPv6 addresses to their prefix for consistent rate limiting and tracking.
 */
class IpHelper
{
    /**
     * Check if an IP address is IPv6.
     *
     * @param string|null $ip The IP address to check
     * @return bool True if the IP is IPv6, false otherwise
     */
    public static function isIPv6(?string $ip): bool
    {
        if (empty($ip)) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * Check if an IP address is IPv4.
     *
     * @param string|null $ip The IP address to check
     * @return bool True if the IP is IPv4, false otherwise
     */
    public static function isIPv4(?string $ip): bool
    {
        if (empty($ip)) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Normalize an IPv6 address to its prefix.
     *
     * For example, with prefix length 64:
     * "2001:db8:1234:5678:aaaa:bbbb:cccc:dddd" → "2001:db8:1234:5678::"
     *
     * @param string $ip The IPv6 address to normalize
     * @param int $prefixLength The prefix length (default: 64)
     * @return string|null The normalized IPv6 prefix, or null if invalid
     */
    public static function normalizeIPv6ToPrefix(string $ip, int $prefixLength = 64): ?string
    {
        // Validate it's a proper IPv6 address
        if (!self::isIPv6($ip)) {
            return null;
        }

        // Clamp prefix length to valid range
        $prefixLength = max(1, min(128, $prefixLength));

        // Convert IPv6 to binary representation
        $binary = inet_pton($ip);
        if ($binary === false) {
            return null;
        }

        // Calculate how many bits to zero out
        $bitsToKeep = $prefixLength;
        $bitsToZero = 128 - $bitsToKeep;

        // Create a mask with $bitsToKeep ones followed by $bitsToZero zeros
        $mask = str_repeat("\xff", (int)floor($bitsToKeep / 8));
        
        // Handle partial byte
        $remainingBits = $bitsToKeep % 8;
        if ($remainingBits > 0) {
            $mask .= chr(0xff << (8 - $remainingBits));
        }
        
        // Pad with zeros
        $mask = str_pad($mask, 16, "\x00");

        // Apply the mask
        $prefixBinary = '';
        for ($i = 0; $i < 16; $i++) {
            $prefixBinary .= chr(ord($binary[$i]) & ord($mask[$i]));
        }

        // Convert back to IPv6 string representation
        $prefixIp = inet_ntop($prefixBinary);
        if ($prefixIp === false) {
            return null;
        }

        return $prefixIp;
    }

    /**
     * Get the trackable IP for rate limiting and storage.
     *
     * For IPv6 addresses, this returns the normalized prefix.
     * For IPv4 addresses, this returns the original IP.
     * For null/invalid IPs, this returns null.
     *
     * @param string|null $ip The original IP address
     * @param int $ipv6PrefixLength The IPv6 prefix length (default: 64)
     * @return string|null The trackable IP or prefix
     */
    public static function getTrackableIp(?string $ip, int $ipv6PrefixLength = 64): ?string
    {
        if (empty($ip)) {
            return null;
        }

        // IPv4 addresses pass through unchanged
        if (self::isIPv4($ip)) {
            return $ip;
        }

        // IPv6 addresses get normalized to their prefix
        if (self::isIPv6($ip)) {
            return self::normalizeIPv6ToPrefix($ip, $ipv6PrefixLength);
        }

        // Invalid IP - return as-is (could be a hostname)
        return $ip;
    }

    /**
     * Expand an IPv6 address to its full form.
     *
     * For example: "2001:db8::1" → "2001:0db8:0000:0000:0000:0000:0000:0001"
     *
     * @param string $ip The IPv6 address to expand
     * @return string|null The expanded IPv6 address, or null if invalid
     */
    public static function expandIPv6(string $ip): ?string
    {
        if (!self::isIPv6($ip)) {
            return null;
        }

        $binary = inet_pton($ip);
        if ($binary === false) {
            return null;
        }

        // Convert binary to hex groups
        $hex = bin2hex($binary);
        $groups = str_split($hex, 4);

        return implode(':', $groups);
    }

    /**
     * Check if two IPs are in the same IPv6 prefix.
     *
     * @param string $ip1 First IP address
     * @param string $ip2 Second IP address
     * @param int $prefixLength The prefix length to compare
     * @return bool True if both IPs are in the same prefix
     */
    public static function isSameIPv6Prefix(string $ip1, string $ip2, int $prefixLength = 64): bool
    {
        $prefix1 = self::normalizeIPv6ToPrefix($ip1, $prefixLength);
        $prefix2 = self::normalizeIPv6ToPrefix($ip2, $prefixLength);

        if ($prefix1 === null || $prefix2 === null) {
            return false;
        }

        return $prefix1 === $prefix2;
    }

    /**
     * Check if an IP matches a CIDR range.
     *
     * Supports both IPv4 and IPv6 CIDR notation.
     * For example: "192.168.1.100" matches "192.168.1.0/24"
     *
     * @param string $ip The IP address to check
     * @param string $cidr The CIDR range (e.g., "192.168.0.0/16" or "2001:db8::/32")
     * @return bool True if the IP is within the CIDR range
     */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        // Use Symfony's IpUtils if available (it's already a dependency via Laravel)
        if (class_exists(\Symfony\Component\HttpFoundation\IpUtils::class)) {
            return \Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, $cidr);
        }

        // Fallback implementation
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        list($range, $prefix) = explode('/', $cidr, 2);
        $prefix = (int)$prefix;

        // Determine if we're dealing with IPv4 or IPv6
        if (self::isIPv4($ip) && self::isIPv4($range)) {
            return self::ipv4InCidr($ip, $range, $prefix);
        }

        if (self::isIPv6($ip) && self::isIPv6($range)) {
            return self::ipv6InCidr($ip, $range, $prefix);
        }

        return false;
    }

    /**
     * Check if an IP matches any of the given CIDR ranges.
     *
     * @param string $ip The IP address to check
     * @param array $cidrs Array of CIDR ranges to check against
     * @return bool True if the IP matches any range
     */
    public static function ipInAnyCidr(string $ip, array $cidrs): bool
    {
        if (empty($cidrs)) {
            return false;
        }

        foreach ($cidrs as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an IPv4 address is within a CIDR range.
     */
    private static function ipv4InCidr(string $ip, string $range, int $prefix): bool
    {
        $ipLong = ip2long($ip);
        $rangeLong = ip2long($range);

        if ($ipLong === false || $rangeLong === false) {
            return false;
        }

        $mask = -1 << (32 - $prefix);

        return ($ipLong & $mask) === ($rangeLong & $mask);
    }

    /**
     * Check if an IPv6 address is within a CIDR range.
     */
    private static function ipv6InCidr(string $ip, string $range, int $prefix): bool
    {
        $ipPrefix = self::normalizeIPv6ToPrefix($ip, $prefix);
        $rangePrefix = self::normalizeIPv6ToPrefix($range, $prefix);

        return $ipPrefix !== null && $ipPrefix === $rangePrefix;
    }
}
