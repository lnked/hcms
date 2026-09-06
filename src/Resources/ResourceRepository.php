<?php

declare(strict_types=1);

namespace Cms\Resources;

use Cms\Database\Connection;
use RuntimeException;

final class ResourceRepository
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
            'SELECT r.*, ct.label AS content_type_label, ct.slug AS content_type_slug, ct.is_system
             FROM cms_resources r
             INNER JOIN cms_content_types ct ON ct.id = r.content_type_id
             ORDER BY r.slug ASC, r.id ASC',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT r.*, ct.label AS content_type_label, ct.slug AS content_type_slug, ct.is_system
             FROM cms_resources r
             INNER JOIN cms_content_types ct ON ct.id = r.content_type_id
             WHERE r.id = :id',
            ['id' => $id],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->selectOne(
            'SELECT r.*, ct.label AS content_type_label, ct.slug AS content_type_slug, ct.is_system
             FROM cms_resources r
             INNER JOIN cms_content_types ct ON ct.id = r.content_type_id
             WHERE r.slug = :slug',
            ['slug' => $slug],
        );
    }

    /**
     * Resolve a public route segment (`/api/{key}` or `/api/v1/{key}`) by endpoint first, then slug.
     *
     * @return array<string, mixed>|null
     */
    public function findByPublicKey(string $key): ?array
    {
        return $this->db->selectOne(
            'SELECT r.*, ct.label AS content_type_label, ct.slug AS content_type_slug, ct.is_system
             FROM cms_resources r
             INNER JOIN cms_content_types ct ON ct.id = r.content_type_id
             WHERE r.endpoint = :api
                OR r.endpoint = :api_v1
                OR r.slug = :key
             ORDER BY
                CASE
                    WHEN r.endpoint = :api OR r.endpoint = :api_v1 THEN 0
                    ELSE 1
                END,
                r.id ASC
             LIMIT 1',
            [
                'key' => $key,
                'api' => '/api/' . $key,
                'api_v1' => '/api/v1/' . $key,
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEndpoint(string $endpoint, ?int $exceptId = null): ?array
    {
        $sql = 'SELECT r.*, ct.label AS content_type_label, ct.slug AS content_type_slug, ct.is_system
             FROM cms_resources r
             INNER JOIN cms_content_types ct ON ct.id = r.content_type_id
             WHERE r.endpoint = :endpoint';
        $params = ['endpoint' => $endpoint];
        if ($exceptId !== null) {
            $sql .= ' AND r.id <> :except_id';
            $params['except_id'] = $exceptId;
        }

        return $this->db->selectOne($sql, $params);
    }

    /**
     * Collision when another resource owns the same public key via slug or endpoint.
     *
     * @return array<string, mixed>|null
     */
    public function findPublicKeyConflict(string $key, ?int $exceptId = null): ?array
    {
        $sql = 'SELECT r.*, ct.label AS content_type_label, ct.slug AS content_type_slug, ct.is_system
             FROM cms_resources r
             INNER JOIN cms_content_types ct ON ct.id = r.content_type_id
             WHERE (r.slug = :key OR r.endpoint = :api OR r.endpoint = :api_v1)';
        $params = [
            'key' => $key,
            'api' => '/api/' . $key,
            'api_v1' => '/api/v1/' . $key,
        ];
        if ($exceptId !== null) {
            $sql .= ' AND r.id <> :except_id';
            $params['except_id'] = $exceptId;
        }
        $sql .= ' ORDER BY r.id ASC LIMIT 1';

        return $this->db->selectOne($sql, $params);
    }

    /**
     * @param array{
     *   content_type_id: int,
     *   slug: string,
     *   endpoint: string,
     *   api_version?: string,
     *   status?: string,
     *   settings: array<string, mixed>
     * } $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_resources
             (content_type_id, slug, endpoint, api_version, status, schema_version, settings_json, created_at, updated_at)
             VALUES
             (:content_type_id, :slug, :endpoint, :api_version, :status, 0, :settings_json, :created_at, :updated_at)',
            [
                'content_type_id' => $data['content_type_id'],
                'slug' => $data['slug'],
                'endpoint' => $data['endpoint'],
                'api_version' => $data['api_version'] ?? 'v1',
                'status' => $data['status'] ?? 'draft',
                'settings_json' => json_encode($data['settings'], JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create resource');
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
            throw new RuntimeException('Resource not found');
        }

        $settings = $existing['settings_json'];
        if (isset($data['settings']) && is_array($data['settings'])) {
            $decoded = is_string($settings) ? json_decode($settings, true) : $settings;
            $settings = json_encode(
                array_replace_recursive(is_array($decoded) ? $decoded : [], $data['settings']),
                JSON_UNESCAPED_SLASHES,
            );
        }

        $this->db->execute(
            'UPDATE cms_resources
             SET endpoint = :endpoint, status = :status, settings_json = :settings_json, updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'endpoint' => $data['endpoint'] ?? $existing['endpoint'],
                'status' => $data['status'] ?? $existing['status'],
                'settings_json' => is_string($settings) ? $settings : json_encode($settings, JSON_UNESCAPED_SLASHES),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );

        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Resource not found after update');
        }

        return $row;
    }

    public function delete(int $id): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Resource not found');
        }
        if ((int) ($existing['is_system'] ?? 0) === 1) {
            throw new RuntimeException('System resources cannot be deleted');
        }

        $this->db->execute('DELETE FROM cms_resources WHERE id = :id', ['id' => $id]);
    }
}
