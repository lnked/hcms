<?php

declare(strict_types=1);

namespace Cms\Http;

/**
 * Resolve client IP behind optional trusted reverse proxies.
 */
final class ClientIp
{
    /**
     * @param list<string> $trustedProxies CIDR or exact IPs
     */
    public static function resolve(string $remoteAddr, ?string $forwardedFor, array $trustedProxies): string
    {
        $remoteAddr = trim($remoteAddr);
        if ($remoteAddr === '' || $trustedProxies === []) {
            return $remoteAddr;
        }

        if (!self::matchesAny($remoteAddr, $trustedProxies)) {
            return $remoteAddr;
        }

        if ($forwardedFor === null || trim($forwardedFor) === '') {
            return $remoteAddr;
        }

        $parts = array_map('trim', explode(',', $forwardedFor));
        // Leftmost is the original client when proxies append.
        foreach ($parts as $candidate) {
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return $remoteAddr;
    }

    /**
     * @param list<string> $trustedProxies
     */
    public static function matchesAny(string $ip, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                if (self::inCidr($ip, $entry)) {
                    return true;
                }
                continue;
            }
            if (hash_equals($entry, $ip)) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$subnet, $maskRaw] = $parts;
        if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($subnet, FILTER_VALIDATE_IP)) {
            return false;
        }
        $mask = (int) $maskRaw;
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $len = strlen($ipBin) * 8;
        if ($mask < 0 || $mask > $len) {
            return false;
        }
        $bytes = intdiv($mask, 8);
        $bits = $mask % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }
        $maskByte = chr((0xFF << (8 - $bits)) & 0xFF);

        return ($ipBin[$bytes] & $maskByte) === ($subnetBin[$bytes] & $maskByte);
    }

    /**
     * @param mixed $raw from settings
     * @return list<string>
     */
    public static function normalizeTrustedList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }
}
