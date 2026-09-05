<?php

declare(strict_types=1);

namespace Cms\Database;

final class SchemaDiff
{
    /**
     * @param list<ColumnDefinition> $current
     * @param list<ColumnDefinition> $desired
     * @return list<array{op: string, column?: ColumnDefinition, name?: string, from?: string, to?: string}>
     */
    public function plan(array $current, array $desired): array
    {
        $currentByName = [];
        foreach ($current as $col) {
            $currentByName[$col->name] = $col;
        }
        $desiredByName = [];
        foreach ($desired as $col) {
            $desiredByName[$col->name] = $col;
        }

        $ops = [];
        foreach ($desiredByName as $name => $col) {
            if (!isset($currentByName[$name])) {
                $ops[] = ['op' => 'add_field', 'column' => $col];
                continue;
            }
            $old = $currentByName[$name];
            if (
                $old->sqlType !== $col->sqlType
                || $old->nullable !== $col->nullable
                || $old->unique !== $col->unique
            ) {
                $ops[] = ['op' => 'change_type', 'column' => $col];
            }
            if (!$old->indexed && $col->indexed) {
                $ops[] = ['op' => 'add_index', 'name' => $name];
            }
            if ($old->indexed && !$col->indexed && !$col->unique) {
                $ops[] = ['op' => 'drop_index', 'name' => $name];
            }
        }

        foreach ($currentByName as $name => $col) {
            if (!isset($desiredByName[$name]) && !in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                $ops[] = ['op' => 'drop_field', 'name' => $name];
            }
        }

        return $ops;
    }
}
