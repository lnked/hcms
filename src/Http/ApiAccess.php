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

        return OriginMatcher::matchesAny($this->allowedOrigins, $origin);
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
        return OriginMatcher::normalize($raw);
    }
}
