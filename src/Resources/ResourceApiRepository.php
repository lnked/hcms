<?php

declare(strict_types=1);

namespace Cms\Resources;

use Cms\Database\Connection;
use RuntimeException;

final class ResourceApiRepository
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
            'SELECT * FROM cms_resource_apis WHERE resource_id = :resource_id ORDER BY slug ASC, id ASC',
            ['resource_id' => $resourceId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabledForResource(int $resourceId): array
    {
        return $this->db->select(
            'SELECT * FROM cms_resource_apis
             WHERE resource_id = :resource_id AND enabled = 1
             ORDER BY slug ASC, id ASC',
            ['resource_id' => $resourceId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_resource_apis WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByResourceAndSlug(int $resourceId, string $slug): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_resource_apis WHERE resource_id = :resource_id AND slug = :slug',
            ['resource_id' => $resourceId, 'slug' => $slug],
        );
    }

    /**
     * @param array{
     *   resource_id: int,
     *   slug: string,
     *   label: string,
     *   enabled?: bool,
     *   methods: list<string>,
     *   fields: list<string>|null,
     *   joins: list<array<string, mixed>>,
     *   settings: array<string, mixed>
     * } $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_resource_apis
             (resource_id, slug, label, enabled, methods_json, fields_json, joins_json, settings_json, created_at, updated_at)
             VALUES
             (:resource_id, :slug, :label, :enabled, :methods_json, :fields_json, :joins_json, :settings_json, :created_at, :updated_at)',
            [
                'resource_id' => $data['resource_id'],
                'slug' => $data['slug'],
                'label' => $data['label'],
                'enabled' => ($data['enabled'] ?? true) ? 1 : 0,
                'methods_json' => json_encode($data['methods'], JSON_UNESCAPED_SLASHES),
                'fields_json' => $data['fields'] === null
                    ? null
                    : json_encode($data['fields'], JSON_UNESCAPED_SLASHES),
                'joins_json' => json_encode($data['joins'], JSON_UNESCAPED_SLASHES),
                'settings_json' => json_encode($data['settings'], JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create resource API');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Resource API not found');
        }

        $this->db->execute(
            'UPDATE cms_resource_apis
             SET slug = :slug,
                 label = :label,
                 enabled = :enabled,
                 methods_json = :methods_json,
                 fields_json = :fields_json,
                 joins_json = :joins_json,
                 settings_json = :settings_json,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'slug' => $data['slug'] ?? $existing['slug'],
                'label' => $data['label'] ?? $existing['label'],
                'enabled' => \array_key_exists('enabled', $data)
                    ? (($data['enabled'] ?? false) ? 1 : 0)
                    : (int) $existing['enabled'],
                'methods_json' => \array_key_exists('methods', $data)
                    ? json_encode($data['methods'], JSON_UNESCAPED_SLASHES)
                    : $existing['methods_json'],
                'fields_json' => \array_key_exists('fields', $data)
                    ? ($data['fields'] === null ? null : json_encode($data['fields'], JSON_UNESCAPED_SLASHES))
                    : $existing['fields_json'],
                'joins_json' => \array_key_exists('joins', $data)
                    ? json_encode($data['joins'], JSON_UNESCAPED_SLASHES)
                    : $existing['joins_json'],
                'settings_json' => \array_key_exists('settings', $data)
                    ? json_encode($data['settings'], JSON_UNESCAPED_SLASHES)
                    : $existing['settings_json'],
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );

        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Resource API not found after update');
        }

        return $row;
    }

    public function delete(int $id): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Resource API not found');
        }
        $this->db->execute('DELETE FROM cms_resource_apis WHERE id = :id', ['id' => $id]);
    }
}
