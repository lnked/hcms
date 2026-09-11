<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\Database\Connection;
use RuntimeException;

final class ContentTypeRepository
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
            'SELECT * FROM cms_content_types ORDER BY label ASC, id ASC',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_content_types WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_content_types WHERE slug = :slug',
            ['slug' => $slug],
        );
    }

    /**
     * @param array{name: string, slug: string, label: string, description: ?string, is_system?: bool} $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_content_types (name, slug, label, description, is_system, created_at, updated_at)
             VALUES (:name, :slug, :label, :description, :is_system, :created_at, :updated_at)',
            [
                'name' => $data['name'],
                'slug' => $data['slug'],
                'label' => $data['label'],
                'description' => $data['description'],
                'is_system' => !empty($data['is_system']) ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create content type');
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
            throw new RuntimeException('Content type not found');
        }

        $this->db->execute(
            'UPDATE cms_content_types
             SET name = :name, label = :label, description = :description, updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'name' => $data['name'] ?? $existing['name'],
                'label' => $data['label'] ?? $existing['label'],
                'description' => \array_key_exists('description', $data) ? $data['description'] : $existing['description'],
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );

        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Content type not found after update');
        }

        return $row;
    }

    public function delete(int $id): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Content type not found');
        }
        if ((int) $existing['is_system'] === 1) {
            throw new RuntimeException('System content types cannot be deleted');
        }

        $this->db->execute('DELETE FROM cms_content_types WHERE id = :id', ['id' => $id]);
    }
}
