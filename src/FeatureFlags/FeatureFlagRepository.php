<?php

declare(strict_types=1);

namespace Cms\FeatureFlags;

use Cms\Database\Connection;
use RuntimeException;

final class FeatureFlagRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $search = null, ?string $type = null, ?bool $enabled = null): array
    {
        $where = [];
        $params = [];
        if ($search !== null && $search !== '') {
            $where[] = '(name LIKE :search OR flag_key LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        if ($type !== null && $type !== '') {
            $where[] = 'type = :type';
            $params['type'] = $type;
        }
        if ($enabled !== null) {
            $where[] = 'enabled = :enabled';
            $params['enabled'] = $enabled ? 1 : 0;
        }
        $sql = 'SELECT * FROM cms_feature_flags';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY flag_key ASC';

        return $this->db->select($sql, $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEnabled(): array
    {
        return $this->db->select(
            'SELECT * FROM cms_feature_flags WHERE enabled = 1 ORDER BY flag_key ASC',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM cms_feature_flags WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(string $key): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_feature_flags WHERE flag_key = :flag_key',
            ['flag_key' => $key],
        );
    }

    /**
     * @param array{
     *   name: string,
     *   flag_key: string,
     *   type: string,
     *   value_json: string,
     *   description: ?string,
     *   enabled: int,
     *   ab_test: int,
     *   rollout_percent: int
     * } $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_feature_flags
             (name, flag_key, type, value_json, description, enabled, ab_test, rollout_percent, created_at, updated_at)
             VALUES (:name, :flag_key, :type, :value_json, :description, :enabled, :ab_test, :rollout_percent, :created_at, :updated_at)',
            [
                'name' => $data['name'],
                'flag_key' => $data['flag_key'],
                'type' => $data['type'],
                'value_json' => $data['value_json'],
                'description' => $data['description'],
                'enabled' => $data['enabled'],
                'ab_test' => $data['ab_test'],
                'rollout_percent' => $data['rollout_percent'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create feature flag');
        }

        return $row;
    }

    /**
     * @param array{
     *   name?: string,
     *   type?: string,
     *   value_json?: string,
     *   description?: ?string,
     *   enabled?: int,
     *   ab_test?: int,
     *   rollout_percent?: int
     * } $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $sets = [];
        $params = ['id' => $id, 'updated_at' => date('Y-m-d H:i:s')];
        foreach (['name', 'type', 'value_json', 'description', 'enabled', 'ab_test', 'rollout_percent'] as $col) {
            if (\array_key_exists($col, $data)) {
                $sets[] = $col . ' = :' . $col;
                $params[$col] = $data[$col];
            }
        }
        if ($sets === []) {
            $row = $this->find($id);
            if ($row === null) {
                throw new RuntimeException('Feature flag not found');
            }

            return $row;
        }
        $sets[] = 'updated_at = :updated_at';
        $this->db->execute(
            'UPDATE cms_feature_flags SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $params,
        );
        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Feature flag not found');
        }

        return $row;
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM cms_feature_flags WHERE id = :id', ['id' => $id]);
    }

    public function maxUpdatedAt(): ?string
    {
        $row = $this->db->selectOne('SELECT MAX(updated_at) AS m FROM cms_feature_flags');
        if ($row === null || $row['m'] === null) {
            return null;
        }

        return (string) $row['m'];
    }
}
