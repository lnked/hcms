<?php

declare(strict_types=1);

namespace Cms\Security;

/**
 * Single IP and CIDR matching (IPv4 + IPv6) for trusted proxies, token
 * allowlists and IP blocks. Unlike Origin, a source IP cannot be forged.
 *
 * Accepts unvalidated patterns so callers may pass raw settings straight in;
 * anything malformed simply never matches.
 */
final class IpMatcher
{
    private const V4_MAPPED_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    /**
     * @return string|null Normalized "ip" or "ip/bits", or null when invalid
     */
    public static function normalizePattern(string $raw): ?string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return null;
        }

        if (!str_contains($raw, '/')) {
            return filter_var($raw, FILTER_VALIDATE_IP) === false ? null : $raw;
        }

        [$address, $bits] = explode('/', $raw, 2);
        $address = trim($address);
        $bits = trim($bits);
        if (filter_var($address, FILTER_VALIDATE_IP) === false || preg_match('/^\d{1,3}$/', $bits) !== 1) {
            return null;
        }

        $max = str_contains($address, ':') ? 128 : 32;
        $prefix = (int) $bits;
        if ($prefix > $max) {
            return null;
        }

        return $address . '/' . $prefix;
    }

    /**
     * @param list<string> $patterns Exact IPs and/or CIDRs
     */
    public static function matchesAny(array $patterns, string $ip): bool
    {
        foreach ($patterns as $pattern) {
            if (trim($pattern) !== '' && self::matches($pattern, $ip)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $pattern, string $ip): bool
    {
        $target = self::toBinary($ip);
        if ($target === null) {
            return false;
        }

        if (!str_contains($pattern, '/')) {
            $single = self::toBinary($pattern);

            return $single !== null && hash_equals($single, $target);
        }

        [$address, $bits] = explode('/', $pattern, 2);
        $base = self::toBinary($address);
        if ($base === null || \strlen($base) !== \strlen($target)) {
            return false;
        }

        $prefix = (int) $bits;
        if ($prefix < 0 || $prefix > \strlen($base) * 8) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && strncmp($base, $target, $wholeBytes) !== 0) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = \chr((0xFF << (8 - $remainingBits)) & 0xFF);

        return ($base[$wholeBytes] & $mask) === ($target[$wholeBytes] & $mask);
    }

    /**
     * Packs an address and unwraps IPv4-mapped IPv6 (::ffff:1.2.3.4) so that
     * a v4 pattern still matches a client seen through an IPv6 proxy.
     */
    private static function toBinary(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));
        if ($packed === false) {
            return null;
        }

        if (\strlen($packed) === 16 && str_starts_with($packed, self::V4_MAPPED_PREFIX)) {
            return substr($packed, 12);
        }

        return $packed;
    }
}
