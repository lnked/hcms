<?php

declare(strict_types=1);

namespace Cms\Integrations;

use Cms\Database\Connection;
use RuntimeException;

final class IntegrationApiRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forIntegration(string $integrationKey): array
    {
        return $this->db->select(
            'SELECT * FROM cms_integration_apis
             WHERE integration_key = :integration_key
             ORDER BY slug ASC, id ASC',
            ['integration_key' => $integrationKey],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabledForIntegration(string $integrationKey): array
    {
        return $this->db->select(
            'SELECT * FROM cms_integration_apis
             WHERE integration_key = :integration_key AND enabled = 1
             ORDER BY slug ASC, id ASC',
            ['integration_key' => $integrationKey],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_integration_apis WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByKeyAndSlug(string $integrationKey, string $slug): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_integration_apis
             WHERE integration_key = :integration_key AND slug = :slug',
            ['integration_key' => $integrationKey, 'slug' => $slug],
        );
    }

    /**
     * @param array{
     *   integration_key: string,
     *   slug: string,
     *   label: string,
     *   enabled?: bool,
     *   defaults: array<string, mixed>,
     *   settings: array<string, mixed>
     * } $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_integration_apis
             (integration_key, slug, label, enabled, defaults_json, settings_json, created_at, updated_at)
             VALUES
             (:integration_key, :slug, :label, :enabled, :defaults_json, :settings_json, :created_at, :updated_at)',
            [
                'integration_key' => $data['integration_key'],
                'slug' => $data['slug'],
                'label' => $data['label'],
                'enabled' => ($data['enabled'] ?? true) ? 1 : 0,
                'defaults_json' => json_encode($data['defaults'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'settings_json' => json_encode($data['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create integration API');
        }

        return $row;
    }

    /**
     * @param array{
     *   slug: string,
     *   label: string,
     *   enabled: bool,
     *   defaults: array<string, mixed>,
     *   settings: array<string, mixed>
     * } $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $this->db->execute(
            'UPDATE cms_integration_apis
             SET slug = :slug,
                 label = :label,
                 enabled = :enabled,
                 defaults_json = :defaults_json,
                 settings_json = :settings_json,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'slug' => $data['slug'],
                'label' => $data['label'],
                'enabled' => $data['enabled'] ? 1 : 0,
                'defaults_json' => json_encode($data['defaults'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'settings_json' => json_encode($data['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );

        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Integration API not found', 404);
        }

        return $row;
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM cms_integration_apis WHERE id = :id', ['id' => $id]);
    }
}
