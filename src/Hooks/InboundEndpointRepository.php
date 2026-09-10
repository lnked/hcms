<?php

declare(strict_types=1);

namespace Cms\Hooks;

use Cms\Database\Connection;
use RuntimeException;

final class InboundEndpointRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->select(
            'SELECT id, slug, label, target_url, secret, persist_resource_id, field_map, enabled, timeout_ms, on_failure, created_at, updated_at
             FROM cms_inbound_endpoints
             ORDER BY id DESC',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, slug, label, target_url, secret, persist_resource_id, field_map, enabled, timeout_ms, on_failure, created_at, updated_at
             FROM cms_inbound_endpoints WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->selectOne(
            'SELECT id, slug, label, target_url, secret, persist_resource_id, field_map, enabled, timeout_ms, on_failure, created_at, updated_at
             FROM cms_inbound_endpoints WHERE slug = :slug',
            ['slug' => $slug],
        );
    }

    /**
     * @param array{
     *   slug: string,
     *   label: string,
     *   target_url: string,
     *   secret: string,
     *   persist_resource_id: int|null,
     *   field_map: array<string, string>|null,
     *   enabled: bool,
     *   timeout_ms: int,
     *   on_failure: string
     * } $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_inbound_endpoints
             (slug, label, target_url, secret, persist_resource_id, field_map, enabled, timeout_ms, on_failure, created_at, updated_at)
             VALUES
             (:slug, :label, :target_url, :secret, :persist_resource_id, :field_map, :enabled, :timeout_ms, :on_failure, :created_at, :updated_at)',
            [
                'slug' => $data['slug'],
                'label' => $data['label'],
                'target_url' => $data['target_url'],
                'secret' => $data['secret'],
                'persist_resource_id' => $data['persist_resource_id'],
                'field_map' => $data['field_map'] === null
                    ? null
                    : json_encode($data['field_map'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'enabled' => $data['enabled'] ? 1 : 0,
                'timeout_ms' => $data['timeout_ms'],
                'on_failure' => $data['on_failure'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create inbound endpoint');
        }

        return $row;
    }

    /**
     * @param array{
     *   slug?: string,
     *   label?: string,
     *   target_url?: string,
     *   secret?: string,
     *   persist_resource_id?: int|null,
     *   field_map?: array<string, string>|null,
     *   enabled?: bool,
     *   timeout_ms?: int,
     *   on_failure?: string
     * } $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Inbound endpoint not found', 404);
        }

        $fieldMap = $existing['field_map'];
        if (array_key_exists('field_map', $data)) {
            $fieldMap = $data['field_map'] === null
                ? null
                : json_encode($data['field_map'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (!is_string($fieldMap) && $fieldMap !== null) {
            $fieldMap = json_encode($fieldMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $enabled = array_key_exists('enabled', $data)
            ? ($data['enabled'] ? 1 : 0)
            : (int) $existing['enabled'];

        $this->db->execute(
            'UPDATE cms_inbound_endpoints
             SET slug = :slug,
                 label = :label,
                 target_url = :target_url,
                 secret = :secret,
                 persist_resource_id = :persist_resource_id,
                 field_map = :field_map,
                 enabled = :enabled,
                 timeout_ms = :timeout_ms,
                 on_failure = :on_failure,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'slug' => $data['slug'] ?? $existing['slug'],
                'label' => $data['label'] ?? $existing['label'],
                'target_url' => $data['target_url'] ?? $existing['target_url'],
                'secret' => $data['secret'] ?? $existing['secret'],
                'persist_resource_id' => array_key_exists('persist_resource_id', $data)
                    ? $data['persist_resource_id']
                    : $existing['persist_resource_id'],
                'field_map' => $fieldMap,
                'enabled' => $enabled,
                'timeout_ms' => $data['timeout_ms'] ?? $existing['timeout_ms'],
                'on_failure' => $data['on_failure'] ?? $existing['on_failure'],
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );

        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Inbound endpoint not found after update', 404);
        }

        return $row;
    }

    public function delete(int $id): void
    {
        $affected = $this->db->execute('DELETE FROM cms_inbound_endpoints WHERE id = :id', ['id' => $id]);
        if ($affected === 0) {
            throw new RuntimeException('Inbound endpoint not found', 404);
        }
    }
}
