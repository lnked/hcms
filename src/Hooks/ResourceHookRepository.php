<?php

declare(strict_types=1);

namespace Cms\Hooks;

use Cms\Database\Connection;
use RuntimeException;

final class ResourceHookRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forResource(int $resourceId): array
    {
        return $this->db->select(
            'SELECT id, resource_id, name, phase, url, secret, timeout_ms, on_failure, status, created_at, updated_at
             FROM cms_resource_hooks
             WHERE resource_id = :resource_id
             ORDER BY id ASC',
            ['resource_id' => $resourceId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findActiveForPhase(int $resourceId, string $phase): array
    {
        return $this->db->select(
            "SELECT id, resource_id, name, phase, url, secret, timeout_ms, on_failure, status, created_at, updated_at
             FROM cms_resource_hooks
             WHERE resource_id = :resource_id
               AND phase = :phase
               AND status = 'active'
             ORDER BY id ASC",
            ['resource_id' => $resourceId, 'phase' => $phase],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, resource_id, name, phase, url, secret, timeout_ms, on_failure, status, created_at, updated_at
             FROM cms_resource_hooks WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * @param array{
     *   resource_id: int,
     *   name: string,
     *   phase: string,
     *   url: string,
     *   secret: string,
     *   timeout_ms: int,
     *   on_failure: string,
     *   status: string
     * } $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_resource_hooks
             (resource_id, name, phase, url, secret, timeout_ms, on_failure, status, created_at, updated_at)
             VALUES
             (:resource_id, :name, :phase, :url, :secret, :timeout_ms, :on_failure, :status, :created_at, :updated_at)',
            [
                'resource_id' => $data['resource_id'],
                'name' => $data['name'],
                'phase' => $data['phase'],
                'url' => $data['url'],
                'secret' => $data['secret'],
                'timeout_ms' => $data['timeout_ms'],
                'on_failure' => $data['on_failure'],
                'status' => $data['status'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create resource hook');
        }

        return $row;
    }

    /**
     * @param array{
     *   name?: string,
     *   phase?: string,
     *   url?: string,
     *   secret?: string,
     *   timeout_ms?: int,
     *   on_failure?: string,
     *   status?: string
     * } $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Resource hook not found', 404);
        }

        $this->db->execute(
            'UPDATE cms_resource_hooks
             SET name = :name,
                 phase = :phase,
                 url = :url,
                 secret = :secret,
                 timeout_ms = :timeout_ms,
                 on_failure = :on_failure,
                 status = :status,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'name' => $data['name'] ?? $existing['name'],
                'phase' => $data['phase'] ?? $existing['phase'],
                'url' => $data['url'] ?? $existing['url'],
                'secret' => $data['secret'] ?? $existing['secret'],
                'timeout_ms' => $data['timeout_ms'] ?? $existing['timeout_ms'],
                'on_failure' => $data['on_failure'] ?? $existing['on_failure'],
                'status' => $data['status'] ?? $existing['status'],
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );

        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Resource hook not found after update', 404);
        }

        return $row;
    }

    public function delete(int $id): void
    {
        $affected = $this->db->execute('DELETE FROM cms_resource_hooks WHERE id = :id', ['id' => $id]);
        if ($affected === 0) {
            throw new RuntimeException('Resource hook not found', 404);
        }
    }
}
