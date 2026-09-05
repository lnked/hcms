<?php

declare(strict_types=1);

namespace Cms\Fields;

use Cms\Database\Connection;
use RuntimeException;

final class FieldRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forContentType(int $contentTypeId): array
    {
        return $this->db->select(
            'SELECT * FROM cms_fields WHERE content_type_id = :id ORDER BY sort_order ASC, id ASC',
            ['id' => $contentTypeId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM cms_fields WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array{
     *   content_type_id: int,
     *   name: string,
     *   type: string,
     *   sort_order: int,
     *   spec: array<string, mixed>
     * } $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_fields (content_type_id, name, type, sort_order, spec_json, created_at, updated_at)
             VALUES (:content_type_id, :name, :type, :sort_order, :spec_json, :created_at, :updated_at)',
            [
                'content_type_id' => $data['content_type_id'],
                'name' => $data['name'],
                'type' => $data['type'],
                'sort_order' => $data['sort_order'],
                'spec_json' => json_encode($data['spec'], JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create field');
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
            throw new RuntimeException('Field not found');
        }

        $spec = $existing['spec_json'];
        if (isset($data['spec']) && is_array($data['spec'])) {
            $spec = json_encode($data['spec'], JSON_UNESCAPED_SLASHES);
        }

        $this->db->execute(
            'UPDATE cms_fields
             SET name = :name, type = :type, sort_order = :sort_order, spec_json = :spec_json, updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'name' => $data['name'] ?? $existing['name'],
                'type' => $data['type'] ?? $existing['type'],
                'sort_order' => $data['sort_order'] ?? $existing['sort_order'],
                'spec_json' => is_string($spec) ? $spec : json_encode($spec, JSON_UNESCAPED_SLASHES),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );

        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Field not found after update');
        }

        return $row;
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM cms_fields WHERE id = :id', ['id' => $id]);
    }

    public function deleteForContentType(int $contentTypeId): void
    {
        $this->db->execute(
            'DELETE FROM cms_fields WHERE content_type_id = :id',
            ['id' => $contentTypeId],
        );
    }

    /**
     * @param list<array{id: int, sort_order: int}> $orders
     */
    public function reorder(array $orders): void
    {
        foreach ($orders as $item) {
            $this->db->execute(
                'UPDATE cms_fields SET sort_order = :sort_order, updated_at = :updated_at WHERE id = :id',
                [
                    'id' => $item['id'],
                    'sort_order' => $item['sort_order'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ],
            );
        }
    }
}
