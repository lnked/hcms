<?php

declare(strict_types=1);

namespace Cms\Http;

/**
 * Normalization and matching for origin / host allowlist entries.
 * Shared by the global API access policy and per-token policies.
 */
final class OriginMatcher
{
    public static function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || str_contains($raw, ' ') || str_contains($raw, '..')) {
            return null;
        }

        if (!str_contains($raw, '://')) {
            // Host-only / wildcard host — keep as host pattern (lowercase).
            $host = strtolower(rtrim($raw, '/'));
            if ($host === '' || !self::isValidHostPattern($host)) {
                return null;
            }

            return $host;
        }

        $parts = parse_url($raw);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || !self::isValidHostPattern($host)) {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $origin = $scheme . '://' . $host;
        if ($port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    /**
     * @param list<string> $allowed Normalized entries
     */
    public static function matchesAny(array $allowed, string $origin): bool
    {
        $normalized = self::normalize($origin);
        if ($normalized === null) {
            return false;
        }

        foreach ($allowed as $entry) {
            if (self::matches($entry, $normalized)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $allowed, string $origin): bool
    {
        if ($allowed === $origin) {
            return true;
        }

        // Host-only entry: match any scheme/port for that host (and *.example.com).
        if (!str_contains($allowed, '://')) {
            $parts = parse_url($origin);
            $host = strtolower((string) ($parts['host'] ?? ''));
            if ($host === '') {
                return false;
            }
            if ($allowed === $host) {
                return true;
            }
            if (str_starts_with($allowed, '*.')) {
                $suffix = substr($allowed, 1); // .example.com

                return str_ends_with($host, $suffix) && $host !== ltrim($suffix, '.');
            }
        }

        return false;
    }

    public static function isValidHostPattern(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }
        if (str_starts_with($host, '*.')) {
            $base = substr($host, 2);

            return $base !== '' && (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $base);
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $host)
            || (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host);
    }
}
