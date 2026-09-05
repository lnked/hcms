<?php

declare(strict_types=1);

namespace Cms\Database;

use Cms\Content\Slug;
use Cms\Fields\FieldRepository;
use Cms\Fields\SqlTypeMapper;
use Cms\Resources\ResourceRepository;
use InvalidArgumentException;
use RuntimeException;

final class MigrationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ResourceRepository $resources,
        private readonly FieldRepository $fields,
        private readonly SqlTypeMapper $mapper,
        private readonly SchemaDiff $diff,
    ) {
    }

    public static function tableName(string $slug): string
    {
        if (!Slug::isValid($slug)) {
            throw new InvalidArgumentException('Invalid content type slug for table');
        }

        return 'res_' . $slug;
    }

    /**
     * Apply schema to physical table and bump schema_version.
     *
     * @param array{confirmDestructive?: bool} $options
     * @return array{table: string, version: int, operations: list<array<string, mixed>>}
     */
    public function applyForResource(int $resourceId, array $options = []): array
    {
        $resource = $this->resources->find($resourceId);
        if ($resource === null) {
            throw new RuntimeException('Resource not found', 404);
        }

        $slug = (string) $resource['content_type_slug'];
        $table = self::tableName($slug);
        $fieldRows = $this->fields->forContentType((int) $resource['content_type_id']);
        $desired = [];
        foreach ($fieldRows as $row) {
            $spec = is_string($row['spec_json']) ? json_decode((string) $row['spec_json'], true) : $row['spec_json'];
            $desired[] = $this->mapper->columnFor([
                'name' => $row['name'],
                'type' => $row['type'],
                'nullable' => is_array($spec) ? ($spec['nullable'] ?? true) : true,
                'unique' => is_array($spec) ? ($spec['unique'] ?? false) : false,
                'indexed' => is_array($spec) ? ($spec['indexed'] ?? false) : false,
                'config' => is_array($spec) && is_array($spec['config'] ?? null) ? $spec['config'] : [],
            ]);
        }

        $exists = $this->tableExists($table);
        $ops = [];

        if (!$exists) {
            $this->createTable($table, $desired);
            $ops[] = ['op' => 'create_table', 'table' => $table];
        } else {
            $current = $this->describeTable($table);
            $plan = $this->diff->plan($current, $desired);
            foreach ($plan as $step) {
                if (($step['op'] === 'drop_field' || $step['op'] === 'change_type') && empty($options['confirmDestructive'])) {
                    throw new InvalidArgumentException(
                        'Destructive migration requires confirmDestructive=true: ' . $step['op'],
                    );
                }
                $this->applyStep($table, $step);
                $ops[] = $step;
            }
        }

        $version = (int) $resource['schema_version'] + 1;
        $schemaJson = json_encode(['fields' => $fieldRows], JSON_UNESCAPED_SLASHES);
        $this->db->execute(
            'UPDATE cms_resources SET schema_version = :version, updated_at = :now WHERE id = :id',
            ['version' => $version, 'now' => date('Y-m-d H:i:s'), 'id' => $resourceId],
        );
        $this->db->execute(
            'INSERT INTO cms_schema_revisions (content_type_id, version, schema_json, diff_json, applied_at)
             VALUES (:content_type_id, :version, :schema_json, :diff_json, :applied_at)',
            [
                'content_type_id' => $resource['content_type_id'],
                'version' => $version,
                'schema_json' => $schemaJson,
                'diff_json' => json_encode($ops, JSON_UNESCAPED_SLASHES),
                'applied_at' => date('Y-m-d H:i:s'),
            ],
        );

        return [
            'table' => $table,
            'version' => $version,
            'operations' => $ops,
        ];
    }

    public function tableExists(string $table): bool
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table',
            ['table' => $table],
        );

        return $row !== null && (int) $row['c'] > 0;
    }

    /**
     * @return list<ColumnDefinition>
     */
    public function describeTable(string $table): array
    {
        $rows = $this->db->select('SHOW COLUMNS FROM ' . $this->mapper->quoteIdent($table));
        $cols = [];
        foreach ($rows as $row) {
            $name = (string) $row['Field'];
            if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }
            $cols[] = new ColumnDefinition(
                name: $name,
                sqlType: strtoupper((string) $row['Type']),
                nullable: strtoupper((string) $row['Null']) === 'YES',
                unique: (string) $row['Key'] === 'UNI',
                indexed: (string) $row['Key'] !== '',
            );
        }

        return $cols;
    }

    /**
     * @param list<ColumnDefinition> $columns
     */
    private function createTable(string $table, array $columns): void
    {
        $parts = [
            '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            '`created_at` DATETIME NOT NULL',
            '`updated_at` DATETIME NOT NULL',
        ];
        foreach ($columns as $column) {
            $parts[] = $this->columnSql($column);
        }
        $parts[] = 'PRIMARY KEY (`id`)';
        foreach ($columns as $column) {
            if ($column->unique) {
                $parts[] = 'UNIQUE KEY `uq_' . $column->name . '` (' . $this->mapper->quoteIdent($column->name) . ')';
            } elseif ($column->indexed) {
                $parts[] = 'KEY `idx_' . $column->name . '` (' . $this->mapper->quoteIdent($column->name) . ')';
            }
        }

        $sql = 'CREATE TABLE ' . $this->mapper->quoteIdent($table) . ' (' . implode(', ', $parts)
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->db->execRaw($sql);
    }

    /**
     * @param array{op: string, column?: ColumnDefinition, name?: string} $step
     */
    private function applyStep(string $table, array $step): void
    {
        $t = $this->mapper->quoteIdent($table);
        if ($step['op'] === 'add_field' && isset($step['column'])) {
            $col = $step['column'];
            $sql = 'ALTER TABLE ' . $t . ' ADD COLUMN ' . $this->columnSql($col);
            $this->db->execRaw($sql);
            if ($col->unique) {
                $this->db->execRaw(
                    'ALTER TABLE ' . $t . ' ADD UNIQUE KEY `uq_' . $col->name . '` (' . $this->mapper->quoteIdent($col->name) . ')',
                );
            } elseif ($col->indexed) {
                $this->db->execRaw(
                    'ALTER TABLE ' . $t . ' ADD KEY `idx_' . $col->name . '` (' . $this->mapper->quoteIdent($col->name) . ')',
                );
            }

            return;
        }

        if ($step['op'] === 'drop_field' && isset($step['name'])) {
            $this->db->execRaw('ALTER TABLE ' . $t . ' DROP COLUMN ' . $this->mapper->quoteIdent($step['name']));

            return;
        }

        if ($step['op'] === 'change_type' && isset($step['column'])) {
            $col = $step['column'];
            $this->db->execRaw('ALTER TABLE ' . $t . ' MODIFY COLUMN ' . $this->columnSql($col));

            return;
        }

        if ($step['op'] === 'add_index' && isset($step['name'])) {
            $this->db->execRaw(
                'ALTER TABLE ' . $t . ' ADD KEY `idx_' . $step['name'] . '` (' . $this->mapper->quoteIdent($step['name']) . ')',
            );

            return;
        }

        if ($step['op'] === 'drop_index' && isset($step['name'])) {
            $this->db->execRaw('ALTER TABLE ' . $t . ' DROP INDEX `idx_' . $step['name'] . '`');
        }
    }

    private function columnSql(ColumnDefinition $column): string
    {
        $sql = $this->mapper->quoteIdent($column->name) . ' ' . $column->sqlType;
        $sql .= $column->nullable ? ' NULL' : ' NOT NULL';
        if ($column->defaultSql !== null) {
            $sql .= ' DEFAULT ' . $column->defaultSql;
        }

        return $sql;
    }
}
