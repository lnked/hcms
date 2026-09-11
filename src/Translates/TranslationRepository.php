<?php

declare(strict_types=1);

namespace Cms\Translates;

use Cms\Database\Connection;
use RuntimeException;

final class TranslationRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $search = null): array
    {
        if ($search !== null && $search !== '') {
            return $this->db->select(
                'SELECT * FROM cms_translations
                 WHERE translation_key LIKE :search OR description LIKE :search
                 ORDER BY translation_key ASC',
                ['search' => '%' . $search . '%'],
            );
        }

        return $this->db->select(
            'SELECT * FROM cms_translations ORDER BY translation_key ASC',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_translations WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(string $key): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_translations WHERE translation_key = :translation_key',
            ['translation_key' => $key],
        );
    }

    /**
     * @param array{translation_key: string, description: ?string, values_json: string} $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_translations (translation_key, description, values_json, updated_at)
             VALUES (:translation_key, :description, :values_json, :updated_at)',
            [
                'translation_key' => $data['translation_key'],
                'description' => $data['description'],
                'values_json' => $data['values_json'],
                'updated_at' => $now,
            ],
        );
        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create translation');
        }

        return $row;
    }

    /**
     * @param array{description?: ?string, values_json?: string} $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $sets = ['updated_at = :updated_at'];
        $params = ['id' => $id, 'updated_at' => date('Y-m-d H:i:s')];
        foreach (['description', 'values_json'] as $col) {
            if (\array_key_exists($col, $data)) {
                $sets[] = $col . ' = :' . $col;
                $params[$col] = $data[$col];
            }
        }
        $this->db->execute(
            'UPDATE cms_translations SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $params,
        );
        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Translation not found');
        }

        return $row;
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM cms_translations WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allRows(): array
    {
        return $this->db->select('SELECT * FROM cms_translations ORDER BY translation_key ASC');
    }

    public function maxUpdatedAt(): ?string
    {
        $row = $this->db->selectOne('SELECT MAX(updated_at) AS m FROM cms_translations');
        if ($row === null || $row['m'] === null) {
            return null;
        }

        return (string) $row['m'];
    }
}
