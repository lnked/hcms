<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Security\IpMatcher;

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
        return IpMatcher::matchesAny($trustedProxies, $ip);
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
