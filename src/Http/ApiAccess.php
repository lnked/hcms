<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Core\Settings;

/**
 * Public API (/api/*) origin policy + CORS headers.
 * Admin API is never gated by this.
 */
final class ApiAccess
{
    private const DEFAULT_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
    private const DEFAULT_HEADERS = 'Authorization, Content-Type, Accept';

    /**
     * @param list<string> $allowedOrigins Normalized origins or hostnames
     */
    public function __construct(
        public readonly bool $unrestricted,
        public readonly array $allowedOrigins,
    ) {
    }

    public static function defaults(): self
    {
        return new self(true, []);
    }

    public static function fromSettings(Settings $settings): self
    {
        $raw = $settings->get('api.access');
        if (!is_array($raw)) {
            return self::defaults();
        }

        $unrestricted = array_key_exists('unrestricted', $raw)
            ? (bool) $raw['unrestricted']
            : true;
        $list = [];
        if (isset($raw['allowedOrigins']) && is_array($raw['allowedOrigins'])) {
            foreach ($raw['allowedOrigins'] as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $normalized = self::normalizeEntry($item);
                if ($normalized !== null) {
                    $list[] = $normalized;
                }
            }
        }

        return new self($unrestricted, array_values(array_unique($list)));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: true, value: array{unrestricted: bool, allowedOrigins: list<string>}}|array{ok: false, error: array<string, list<string>>}
     */
    public static function validatePayload(array $payload): array
    {
        if (!array_key_exists('unrestricted', $payload) || !is_bool($payload['unrestricted'])) {
            return ['ok' => false, 'error' => ['unrestricted' => ['Must be a boolean']]];
        }

        $origins = $payload['allowedOrigins'] ?? [];
        if (!is_array($origins)) {
            return ['ok' => false, 'error' => ['allowedOrigins' => ['Must be a list of domains']]];
        }

        $normalized = [];
        $invalid = [];
        foreach ($origins as $i => $item) {
            if (!is_string($item)) {
                $invalid[] = 'Entry #' . ((int) $i + 1) . ' must be a string';

                continue;
            }
            $entry = self::normalizeEntry($item);
            if ($entry === null) {
                $invalid[] = 'Invalid domain: ' . trim($item);

                continue;
            }
            $normalized[] = $entry;
        }

        if ($invalid !== []) {
            return ['ok' => false, 'error' => ['allowedOrigins' => $invalid]];
        }

        $unrestricted = $payload['unrestricted'];
        $unique = array_values(array_unique($normalized));
        if (!$unrestricted && $unique === []) {
            return ['ok' => false, 'error' => ['allowedOrigins' => ['Add at least one domain, or enable unrestricted access']]];
        }

        return [
            'ok' => true,
            'value' => [
                'unrestricted' => $unrestricted,
                'allowedOrigins' => $unique,
            ],
        ];
    }

    /**
     * @return array{unrestricted: bool, allowedOrigins: list<string>}
     */
    public function toArray(): array
    {
        return [
            'unrestricted' => $this->unrestricted,
            'allowedOrigins' => $this->allowedOrigins,
        ];
    }

    public function allows(?string $origin): bool
    {
        if ($this->unrestricted) {
            return true;
        }

        // Non-browser / server-to-server clients typically omit Origin.
        if ($origin === null || trim($origin) === '') {
            return true;
        }

        $normalized = self::normalizeEntry($origin);
        if ($normalized === null) {
            return false;
        }

        foreach ($this->allowedOrigins as $allowed) {
            if ($this->originMatches($allowed, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public function corsHeaders(?string $origin): array
    {
        if ($origin === null || trim($origin) === '') {
            return [];
        }

        if (!$this->allows($origin)) {
            return [];
        }

        $normalized = self::normalizeEntry($origin);
        if ($normalized === null) {
            return [];
        }

        return [
            'Access-Control-Allow-Origin' => $normalized,
            'Access-Control-Allow-Methods' => self::DEFAULT_METHODS,
            'Access-Control-Allow-Headers' => self::DEFAULT_HEADERS,
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin',
        ];
    }

    public static function normalizeEntry(string $raw): ?string
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

    private function originMatches(string $allowed, string $origin): bool
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

    private static function isValidHostPattern(string $host): bool
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
