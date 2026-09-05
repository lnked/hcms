<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Database\Connection;

final class TokenGrantRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forToken(int $tokenId): array
    {
        return $this->db->select(
            'SELECT id, token_id, resource_id, can_read, can_create, can_update, can_delete
             FROM cms_token_grants WHERE token_id = :token_id ORDER BY id ASC',
            ['token_id' => $tokenId],
        );
    }

    /**
     * @param list<array{resourceId: int|null, canRead: bool, canCreate: bool, canUpdate: bool, canDelete: bool}> $grants
     */
    public function replace(int $tokenId, array $grants): void
    {
        $this->db->execute('DELETE FROM cms_token_grants WHERE token_id = :token_id', ['token_id' => $tokenId]);
        foreach ($grants as $grant) {
            $this->db->execute(
                'INSERT INTO cms_token_grants (token_id, resource_id, can_read, can_create, can_update, can_delete)
                 VALUES (:token_id, :resource_id, :can_read, :can_create, :can_update, :can_delete)',
                [
                    'token_id' => $tokenId,
                    'resource_id' => $grant['resourceId'],
                    'can_read' => $grant['canRead'] ? 1 : 0,
                    'can_create' => $grant['canCreate'] ? 1 : 0,
                    'can_update' => $grant['canUpdate'] ? 1 : 0,
                    'can_delete' => $grant['canDelete'] ? 1 : 0,
                ],
            );
        }
    }

    public function allows(int $tokenId, int $resourceId, string $action): bool
    {
        $rows = $this->db->select(
            'SELECT resource_id, can_read, can_create, can_update, can_delete
             FROM cms_token_grants
             WHERE token_id = :token_id
               AND (resource_id IS NULL OR resource_id = :resource_id)',
            ['token_id' => $tokenId, 'resource_id' => $resourceId],
        );

        return GrantPolicy::allows($rows, $resourceId, $action);
    }
}
