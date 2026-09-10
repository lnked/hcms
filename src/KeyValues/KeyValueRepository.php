<?php

declare(strict_types=1);

namespace Cms\KeyValues;

use Cms\Database\Connection;
use RuntimeException;

final class KeyValueRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $search = null): array
    {
        $sql = 'SELECT kv.*,
                       cu.name AS created_by_name, cu.email AS created_by_email,
                       uu.name AS updated_by_name, uu.email AS updated_by_email
                FROM cms_key_values kv
                LEFT JOIN cms_users cu ON cu.id = kv.created_by
                LEFT JOIN cms_users uu ON uu.id = kv.updated_by';
        $params = [];
        if ($search !== null && $search !== '') {
            $sql .= ' WHERE kv.entry_key LIKE :search';
            $params['search'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY kv.entry_key ASC';

        return $this->db->select($sql, $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->db->select(
            'SELECT entry_key, value_json FROM cms_key_values ORDER BY entry_key ASC',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT kv.*,
                    cu.name AS created_by_name, cu.email AS created_by_email,
                    uu.name AS updated_by_name, uu.email AS updated_by_email
             FROM cms_key_values kv
             LEFT JOIN cms_users cu ON cu.id = kv.created_by
             LEFT JOIN cms_users uu ON uu.id = kv.updated_by
             WHERE kv.id = :id',
            ['id' => $id],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(string $key): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_key_values WHERE entry_key = :entry_key',
            ['entry_key' => $key],
        );
    }

    /**
     * @param array{entry_key: string, value_json: string, created_by: ?int, updated_by: ?int} $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_key_values
             (entry_key, value_json, created_by, updated_by, created_at, updated_at)
             VALUES (:entry_key, :value_json, :created_by, :updated_by, :created_at, :updated_at)',
            [
                'entry_key' => $data['entry_key'],
                'value_json' => $data['value_json'],
                'created_by' => $data['created_by'],
                'updated_by' => $data['updated_by'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create key-value entry');
        }

        return $row;
    }

    /**
     * @param array{value_json?: string, updated_by?: ?int} $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $sets = [];
        $params = ['id' => $id, 'updated_at' => date('Y-m-d H:i:s')];
        foreach (['value_json', 'updated_by'] as $col) {
            if (array_key_exists($col, $data)) {
                $sets[] = $col . ' = :' . $col;
                $params[$col] = $data[$col];
            }
        }
        if ($sets === []) {
            $row = $this->find($id);
            if ($row === null) {
                throw new RuntimeException('Key-value entry not found');
            }

            return $row;
        }
        $sets[] = 'updated_at = :updated_at';
        $this->db->execute(
            'UPDATE cms_key_values SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $params,
        );
        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Key-value entry not found');
        }

        return $row;
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM cms_key_values WHERE id = :id', ['id' => $id]);
    }

    public function maxUpdatedAt(): ?string
    {
        $row = $this->db->selectOne('SELECT MAX(updated_at) AS m FROM cms_key_values');
        if ($row === null || $row['m'] === null) {
            return null;
        }

        return (string) $row['m'];
    }
}
