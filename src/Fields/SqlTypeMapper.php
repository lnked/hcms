<?php

declare(strict_types=1);

namespace Cms\Fields;

use Cms\Database\ColumnDefinition;
use InvalidArgumentException;

/**
 * Maps field types to SQL column definitions for resource table migrations.
 */
final class SqlTypeMapper
{
    public function __construct(private readonly FieldTypeRegistry $types)
    {
    }

    /**
     * @param array<string, mixed> $field serialized field
     */
    public function columnFor(array $field): ?ColumnDefinition
    {
        $name = (string) $field['name'];
        $type = (string) $field['type'];
        if (!$this->types->has($type)) {
            throw new InvalidArgumentException('Unknown field type: ' . $type);
        }

        $nullable = (bool) ($field['nullable'] ?? true);
        $unique = (bool) ($field['unique'] ?? false);
        $indexed = (bool) ($field['indexed'] ?? false) || $unique;
        $config = \is_array($field['config'] ?? null) ? $field['config'] : [];

        if ($type === 'relation' && ($config['cardinality'] ?? 'manyToOne') === 'oneToMany') {
            return null;
        }

        $sqlType = match ($type) {
            'string', 'email', 'slug' => 'VARCHAR(' . (int) ($config['maxLength'] ?? 255) . ')',
            'url' => 'VARCHAR(2048)',
            'text', 'json' => 'TEXT',
            'richtext' => 'MEDIUMTEXT',
            'integer' => 'INT',
            'float' => 'DOUBLE',
            'boolean' => 'TINYINT(1)',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'uuid' => 'CHAR(36)',
            'enum' => 'VARCHAR(64)',
            'relation' => 'BIGINT UNSIGNED',
            'image', 'file' => 'JSON',
            default => throw new InvalidArgumentException('Unsupported SQL mapping for ' . $type),
        };

        return new ColumnDefinition(
            name: $name,
            sqlType: $sqlType,
            nullable: $nullable,
            unique: $unique,
            indexed: $indexed || $type === 'relation',
        );
    }

    public function quoteIdent(string $ident): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $ident)) {
            throw new InvalidArgumentException('Unsafe SQL identifier: ' . $ident);
        }

        return '`' . $ident . '`';
    }
}
