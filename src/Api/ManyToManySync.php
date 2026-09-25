<?php

declare(strict_types=1);

namespace Cms\Api;

use Cms\Database\Connection;
use Cms\Database\MigrationService;

/**
 * Syncs many-to-many join rows for relation fields.
 * Extracted from QueryEngine to keep orchestration thinner.
 */
final class ManyToManySync
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string, mixed> $validated
     * @param array<string, array<string, mixed>> $fieldMap
     * @return array{0: array<string, mixed>, 1: array<string, list<int>>}
     */
    public function extract(array $validated, array $fieldMap): array
    {
        $m2m = [];
        $data = $validated;
        foreach ($fieldMap as $name => $meta) {
            $config = \is_array($meta['spec']['config'] ?? null) ? $meta['spec']['config'] : [];
            if (($meta['type'] ?? '') !== 'relation' || ($config['cardinality'] ?? '') !== 'manyToMany') {
                continue;
            }
            if (!\array_key_exists($name, $data)) {
                continue;
            }
            /** @var list<int> $ids */
            $ids = \is_array($data[$name]) ? $data[$name] : [];
            $m2m[$name] = $ids;
            unset($data[$name]);
        }

        return [$data, $m2m];
    }

    /**
     * @param array<string, list<int>> $m2m
     */
    public function sync(string $slug, int $leftId, array $m2m): void
    {
        foreach ($m2m as $field => $ids) {
            $join = MigrationService::manyToManyJoinTable($slug, $field);
            $this->db->execute('DELETE FROM `' . $join . '` WHERE `left_id` = :id', ['id' => $leftId]);
            foreach ($ids as $rightId) {
                $this->db->execute(
                    'INSERT INTO `' . $join . '` (`left_id`, `right_id`) VALUES (:l, :r)',
                    ['l' => $leftId, 'r' => $rightId],
                );
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $fieldMap
     */
    public function clear(string $slug, int $leftId, array $fieldMap): void
    {
        foreach ($fieldMap as $name => $meta) {
            $config = \is_array($meta['spec']['config'] ?? null) ? $meta['spec']['config'] : [];
            if (($meta['type'] ?? '') !== 'relation' || ($config['cardinality'] ?? '') !== 'manyToMany') {
                continue;
            }
            $join = MigrationService::manyToManyJoinTable($slug, $name);
            try {
                $this->db->execute('DELETE FROM `' . $join . '` WHERE `left_id` = :id', ['id' => $leftId]);
            } catch (\Throwable) {
                // Join table may not exist yet.
            }
        }
    }

    /** @return list<int> */
    public function loadIds(string $slug, string $field, int $leftId): array
    {
        $join = MigrationService::manyToManyJoinTable($slug, $field);
        try {
            $rows = $this->db->select(
                'SELECT `right_id` FROM `' . $join . '` WHERE `left_id` = :id ORDER BY `right_id` ASC',
                ['id' => $leftId],
            );
        } catch (\Throwable) {
            return [];
        }
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['right_id'];
        }

        return $ids;
    }
}
