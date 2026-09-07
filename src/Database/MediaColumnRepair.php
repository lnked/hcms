<?php

declare(strict_types=1);

namespace Cms\Database;

use Cms\Content\Slug;
use Throwable;

/**
 * Widens pre-0.45 image|file columns from BIGINT to JSON.
 *
 * Media fields used to store a bare media id; 0.45.0 turned them into JSON documents
 * (rotation, positions, variants) but shipped no data migration. On an older table
 * MySQL coerces the JSON payload into the integer column — silently 0 outside strict
 * mode — so every saved entry lost its image. Re-publishing the schema would fix it,
 * but that path is gated behind a destructive-migration confirmation nobody hits.
 *
 * Widening is lossless: stored ids stay readable as JSON numbers, which MediaValue
 * already normalizes back into a media item.
 */
final class MediaColumnRepair
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<string> repaired `table.column`
     */
    public function run(): array
    {
        $repaired = [];
        foreach ($this->legacyColumns() as [$table, $column]) {
            try {
                $this->db->execRaw(
                    'ALTER TABLE `' . $table . '` MODIFY COLUMN `' . $column . '` JSON NULL',
                );
                $repaired[] = $table . '.' . $column;
            } catch (Throwable) {
                // A column holding something other than ids or JSON cannot be cast;
                // leave it for the schema screen instead of blocking every request.
                continue;
            }
        }

        return $repaired;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function legacyColumns(): array
    {
        $rows = $this->db->select(
            'SELECT c.TABLE_NAME AS table_name, c.COLUMN_NAME AS column_name
               FROM information_schema.COLUMNS c
               INNER JOIN cms_fields f ON f.name = c.COLUMN_NAME
               INNER JOIN cms_content_types t ON t.id = f.content_type_id
              WHERE c.TABLE_SCHEMA = DATABASE()
                AND c.TABLE_NAME = CONCAT(\'res_\', t.slug)
                AND c.DATA_TYPE <> \'json\'
                AND f.type IN (\'image\', \'file\')',
        );

        $out = [];
        foreach ($rows as $row) {
            $table = (string) $row['table_name'];
            $column = (string) $row['column_name'];
            if (!Slug::isValid(substr($table, 4)) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $column)) {
                continue;
            }
            $out[] = [$table, $column];
        }

        return $out;
    }
}
