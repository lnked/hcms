<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Database\Connection;

final class TokenPolicyRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function forToken(int $tokenId): TokenPolicy
    {
        $row = $this->db->selectOne(
            'SELECT allowed_origins, require_origin, allowed_ips
             FROM cms_token_policies WHERE token_id = :token_id',
            ['token_id' => $tokenId],
        );

        return $row === null ? TokenPolicy::unrestricted() : self::hydrate($row);
    }

    /**
     * An unrestricted policy is stored as "no row" so the common case stays cheap.
     */
    public function replace(int $tokenId, TokenPolicy $policy): void
    {
        $this->db->execute(
            'DELETE FROM cms_token_policies WHERE token_id = :token_id',
            ['token_id' => $tokenId],
        );

        if (!$policy->isRestricted()) {
            return;
        }

        $this->db->execute(
            'INSERT INTO cms_token_policies (token_id, allowed_origins, require_origin, allowed_ips)
             VALUES (:token_id, :allowed_origins, :require_origin, :allowed_ips)',
            [
                'token_id' => $tokenId,
                'allowed_origins' => self::encode($policy->allowedOrigins),
                'require_origin' => $policy->requireOrigin ? 1 : 0,
                'allowed_ips' => self::encode($policy->allowedIps),
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): TokenPolicy
    {
        return new TokenPolicy(
            self::decode($row['allowed_origins'] ?? null),
            (bool) ($row['require_origin'] ?? false),
            self::decode($row['allowed_ips'] ?? null),
        );
    }

    /**
     * @param list<string> $entries
     */
    private static function encode(array $entries): ?string
    {
        return $entries === [] ? null : (string) json_encode($entries, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<string>
     */
    private static function decode(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }
}
