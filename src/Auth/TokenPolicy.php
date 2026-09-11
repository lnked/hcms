<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Http\OriginMatcher;
use Cms\Security\IpMatcher;
use InvalidArgumentException;

/**
 * Per-token usage restrictions: browser origins and source IPs.
 *
 * Origin is set by the browser and can be forged by non-browser clients, so the
 * allowlist limits misuse of a leaked frontend token rather than acting as a
 * security boundary. `requireOrigin` is what actually blocks script usage, and
 * `allowedIps` is the only unforgeable constraint here.
 */
final class TokenPolicy
{
    private const MAX_ENTRIES = 50;

    /**
     * @param list<string> $allowedOrigins Normalized origins / host patterns; empty = any
     * @param list<string> $allowedIps Normalized IPs / CIDRs; empty = any
     */
    public function __construct(
        public readonly array $allowedOrigins = [],
        public readonly bool $requireOrigin = false,
        public readonly array $allowedIps = [],
    ) {
    }

    public static function unrestricted(): self
    {
        return new self();
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromInput(mixed $origins, mixed $requireOrigin, mixed $ips): self
    {
        return new self(
            self::normalizeOrigins($origins),
            (bool) $requireOrigin,
            self::normalizeIps($ips),
        );
    }

    public function isRestricted(): bool
    {
        return $this->allowedOrigins !== [] || $this->requireOrigin || $this->allowedIps !== [];
    }

    public function allowsOrigin(?string $origin): bool
    {
        $origin = trim((string) $origin);

        // Non-browser clients omit Origin; requireOrigin is what rejects them.
        if ($origin === '') {
            return !$this->requireOrigin;
        }

        if ($this->allowedOrigins === []) {
            return true;
        }

        return OriginMatcher::matchesAny($this->allowedOrigins, $origin);
    }

    public function allowsIp(string $ip): bool
    {
        if ($this->allowedIps === []) {
            return true;
        }

        return IpMatcher::matchesAny($this->allowedIps, $ip);
    }

    /**
     * @return array{allowedOrigins: list<string>, requireOrigin: bool, allowedIps: list<string>}
     */
    public function toArray(): array
    {
        return [
            'allowedOrigins' => $this->allowedOrigins,
            'requireOrigin' => $this->requireOrigin,
            'allowedIps' => $this->allowedIps,
        ];
    }

    /**
     * @return list<string>
     * @throws InvalidArgumentException
     */
    private static function normalizeOrigins(mixed $input): array
    {
        return self::normalizeList(
            $input,
            'allowedOrigins',
            static fn (string $entry): ?string => OriginMatcher::normalize($entry),
            'Invalid domain: ',
        );
    }

    /**
     * @return list<string>
     * @throws InvalidArgumentException
     */
    private static function normalizeIps(mixed $input): array
    {
        return self::normalizeList(
            $input,
            'allowedIps',
            static fn (string $entry): ?string => IpMatcher::normalizePattern($entry),
            'Invalid IP or CIDR: ',
        );
    }

    /**
     * @param callable(string): ?string $normalizer
     * @return list<string>
     * @throws InvalidArgumentException
     */
    private static function normalizeList(
        mixed $input,
        string $field,
        callable $normalizer,
        string $errorPrefix,
    ): array {
        if ($input === null || $input === '') {
            return [];
        }
        if (!\is_array($input)) {
            throw new InvalidArgumentException($field . ' must be an array');
        }

        $out = [];
        foreach ($input as $item) {
            if (!\is_string($item)) {
                throw new InvalidArgumentException($field . ' entries must be strings');
            }
            if (trim($item) === '') {
                continue;
            }
            $normalized = $normalizer($item);
            if ($normalized === null) {
                throw new InvalidArgumentException($errorPrefix . trim($item));
            }
            $out[] = $normalized;
        }

        $unique = array_values(array_unique($out));
        if (\count($unique) > self::MAX_ENTRIES) {
            throw new InvalidArgumentException($field . ' allows at most ' . self::MAX_ENTRIES . ' entries');
        }

        return $unique;
    }
}
