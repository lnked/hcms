<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Database\Connection;
use Cms\Resources\ResourceRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class ApiTokenService
{
    private const POLICY_KEYS = ['allowedOrigins', 'requireOrigin', 'allowedIps'];

    public function __construct(
        private readonly Connection $db,
        private readonly TokenService $tokens,
        private readonly TokenGrantRepository $grants,
        private readonly ResourceRepository $resources,
        private readonly TokenPolicyRepository $policies,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $rows = $this->db->select(
            "SELECT id, type, name, token_prefix, expires_at, revoked_at, last_used_at, created_at
             FROM cms_tokens
             WHERE type = 'api'
             ORDER BY id DESC",
        );

        return array_map(
            fn (array $row): array => $this->serialize(
                $row,
                $this->grants->forToken((int) $row['id']),
                $this->grants->integrationGrantsForToken((int) $row['id']),
                $this->policies->forToken((int) $row['id']),
            ),
            $rows,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $row = $this->findApi($id);
        if ($row === null) {
            throw new RuntimeException('Token not found', 404);
        }

        return $this->serialize(
            $row,
            $this->grants->forToken($id),
            $this->grants->integrationGrantsForToken($id),
            $this->policies->forToken($id),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{token: string, meta: array<string, mixed>}
     */
    public function create(array $payload): array
    {
        $name = isset($payload['name']) && is_string($payload['name']) ? trim($payload['name']) : '';
        if ($name === '') {
            throw new InvalidArgumentException('Name is required');
        }

        $expiresAt = null;
        if (isset($payload['expiresAt']) && is_string($payload['expiresAt']) && $payload['expiresAt'] !== '') {
            $expiresAt = (new DateTimeImmutable($payload['expiresAt']))->format('Y-m-d H:i:s');
        }

        $grantInput = $payload['grants'] ?? [];
        if (!is_array($grantInput)) {
            throw new InvalidArgumentException('grants must be an array');
        }
        $normalized = $this->normalizeGrants($grantInput);
        $integrationGrants = $this->normalizeIntegrationGrants($payload['integrationGrants'] ?? []);
        $policy = TokenPolicy::fromInput(
            $payload['allowedOrigins'] ?? [],
            $payload['requireOrigin'] ?? false,
            $payload['allowedIps'] ?? [],
        );

        $issued = $this->tokens->issue('api', null, $name, $expiresAt);
        $this->grants->replace($issued['id'], $normalized);
        $this->grants->replaceIntegrationGrants($issued['id'], $integrationGrants);
        $this->policies->replace($issued['id'], $policy);

        return [
            'token' => $issued['token'],
            'meta' => $this->get($issued['id']),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        if ($this->findApi($id) === null) {
            throw new RuntimeException('Token not found', 404);
        }

        // Resolved up front so an invalid domain/IP rejects the whole request
        // before any column is written.
        $policy = $this->resolvePolicyUpdate($id, $payload);

        $sets = [];
        $params = ['id' => $id];

        if (array_key_exists('name', $payload)) {
            $name = is_string($payload['name']) ? trim($payload['name']) : '';
            if ($name === '') {
                throw new InvalidArgumentException('Name is required');
            }
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }

        if (array_key_exists('expiresAt', $payload)) {
            $expiresAt = null;
            if (is_string($payload['expiresAt']) && $payload['expiresAt'] !== '') {
                $expiresAt = (new DateTimeImmutable($payload['expiresAt']))->format('Y-m-d H:i:s');
            }
            $sets[] = 'expires_at = :expires_at';
            $params['expires_at'] = $expiresAt;
        }

        if ($sets !== []) {
            $this->db->execute(
                'UPDATE cms_tokens SET ' . implode(', ', $sets) . ' WHERE id = :id AND type = \'api\'',
                $params,
            );
        }

        if (array_key_exists('grants', $payload)) {
            $grantInput = $payload['grants'];
            if (!is_array($grantInput)) {
                throw new InvalidArgumentException('grants must be an array');
            }
            $this->grants->replace($id, $this->normalizeGrants($grantInput));
        }

        if (array_key_exists('integrationGrants', $payload)) {
            $this->grants->replaceIntegrationGrants(
                $id,
                $this->normalizeIntegrationGrants($payload['integrationGrants']),
            );
        }

        if ($policy !== null) {
            $this->policies->replace($id, $policy);
        }

        if (
            $sets === []
            && !array_key_exists('grants', $payload)
            && !array_key_exists('integrationGrants', $payload)
            && $policy === null
        ) {
            throw new InvalidArgumentException('Nothing to update');
        }

        return $this->get($id);
    }

    public function revoke(int $id): void
    {
        if ($this->findApi($id) === null) {
            throw new RuntimeException('Token not found', 404);
        }
        $this->tokens->revoke($id);
    }

    public function restore(int $id): void
    {
        $row = $this->findApi($id);
        if ($row === null) {
            throw new RuntimeException('Token not found', 404);
        }
        if ($row['revoked_at'] === null) {
            throw new InvalidArgumentException('Token is not revoked');
        }
        $this->tokens->restore($id);
    }

    /**
     * Partial update: each policy key falls back to the stored value.
     *
     * @param array<string, mixed> $payload
     * @return TokenPolicy|null Null when the payload does not touch the policy
     */
    private function resolvePolicyUpdate(int $id, array $payload): ?TokenPolicy
    {
        $touched = array_filter(
            self::POLICY_KEYS,
            static fn (string $key): bool => array_key_exists($key, $payload),
        );
        if ($touched === []) {
            return null;
        }

        $current = $this->policies->forToken($id);

        return TokenPolicy::fromInput(
            array_key_exists('allowedOrigins', $payload) ? $payload['allowedOrigins'] : $current->allowedOrigins,
            array_key_exists('requireOrigin', $payload) ? $payload['requireOrigin'] : $current->requireOrigin,
            array_key_exists('allowedIps', $payload) ? $payload['allowedIps'] : $current->allowedIps,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findApi(int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT id, type, name, token_prefix, expires_at, revoked_at, last_used_at, created_at
             FROM cms_tokens WHERE id = :id AND type = 'api'",
            ['id' => $id],
        );
    }

    /**
     * @param mixed $input
     * @return list<array{resourceId: int|null, canRead: bool, canCreate: bool, canUpdate: bool, canDelete: bool}>
     */
    private function normalizeGrants(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $out = [];
        foreach ($input as $item) {
            if (!is_array($item)) {
                continue;
            }
            $resourceId = null;
            if (array_key_exists('resourceId', $item) && $item['resourceId'] !== null && $item['resourceId'] !== '') {
                $resourceId = (int) $item['resourceId'];
                if ($this->resources->find($resourceId) === null) {
                    throw new InvalidArgumentException('Unknown resourceId: ' . $resourceId);
                }
            }
            $out[] = [
                'resourceId' => $resourceId,
                'canRead' => (bool) ($item['canRead'] ?? false),
                'canCreate' => (bool) ($item['canCreate'] ?? false),
                'canUpdate' => (bool) ($item['canUpdate'] ?? false),
                'canDelete' => (bool) ($item['canDelete'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * @param mixed $input
     * @return list<array{integrationKey: string, canUse: bool}>
     */
    private function normalizeIntegrationGrants(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $allowed = ['email'];
        $out = [];
        foreach ($input as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = isset($item['integrationKey']) && is_string($item['integrationKey'])
                ? trim($item['integrationKey'])
                : '';
            if ($key === '' || !in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Unknown integrationKey: ' . $key);
            }
            $out[] = [
                'integrationKey' => $key,
                'canUse' => (bool) ($item['canUse'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $grants
     * @param list<array<string, mixed>> $integrationGrants
     * @return array<string, mixed>
     */
    private function serialize(
        array $row,
        array $grants,
        array $integrationGrants = [],
        ?TokenPolicy $policy = null,
    ): array {
        return [
            'id' => (int) $row['id'],
            'type' => $row['type'],
            'name' => $row['name'],
            'prefix' => $row['token_prefix'],
            'expiresAt' => $row['expires_at'],
            'revokedAt' => $row['revoked_at'],
            'lastUsedAt' => $row['last_used_at'],
            'createdAt' => $row['created_at'],
            'grants' => array_map(static fn (array $g): array => [
                'id' => (int) $g['id'],
                'resourceId' => $g['resource_id'] === null ? null : (int) $g['resource_id'],
                'canRead' => (bool) $g['can_read'],
                'canCreate' => (bool) $g['can_create'],
                'canUpdate' => (bool) $g['can_update'],
                'canDelete' => (bool) $g['can_delete'],
            ], $grants),
            'integrationGrants' => array_map(static fn (array $g): array => [
                'id' => (int) $g['id'],
                'integrationKey' => (string) $g['integration_key'],
                'canUse' => (bool) $g['can_use'],
            ], $integrationGrants),
            ...($policy ?? TokenPolicy::unrestricted())->toArray(),
        ];
    }
}
