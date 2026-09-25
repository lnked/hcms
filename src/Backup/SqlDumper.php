<?php

declare(strict_types=1);

namespace Cms\Backup;

use Cms\Database\Connection;
use RuntimeException;

final class SqlDumper
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Dump cms_* and res_* tables to a SQL file.
     *
     * @return list<string> table names dumped
     */
    public function dumpToFile(string $path): array
    {
        $tables = $this->listTables();
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Cannot write SQL dump: ' . $path);
        }

        try {
            fwrite($fh, "-- HCMS data backup\n");
            fwrite($fh, 'SET NAMES utf8mb4;' . "\n");
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                $this->writeTable($fh, $table);
            }

            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($fh);
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    public function listTables(): array
    {
        $rows = $this->db->select(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND (TABLE_NAME LIKE 'cms_%' OR TABLE_NAME LIKE 'res_%')
             ORDER BY TABLE_NAME ASC",
        );
        $out = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name !== '' && preg_match('/^(cms|res)_[a-z][a-z0-9_]*$/', $name) === 1) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @param resource $fh
     */
    private function writeTable($fh, string $table): void
    {
        $create = $this->db->selectOne('SHOW CREATE TABLE `' . $table . '`');
        if ($create === null) {
            throw new RuntimeException('SHOW CREATE TABLE failed: ' . $table);
        }
        $ddl = '';
        foreach ($create as $key => $value) {
            if (stripos((string) $key, 'Create Table') !== false && \is_string($value)) {
                $ddl = $value;
                break;
            }
        }
        if ($ddl === '') {
            throw new RuntimeException('Empty CREATE TABLE for ' . $table);
        }

        fwrite($fh, 'DROP TABLE IF EXISTS `' . $table . '`;' . "\n");
        fwrite($fh, $ddl . ";\n\n");

        $pdo = $this->db->pdo();
        $stmt = $pdo->query('SELECT * FROM `' . $table . '`');
        if ($stmt === false) {
            throw new RuntimeException('SELECT failed: ' . $table);
        }

        $batch = [];
        $columns = null;
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if ($columns === null) {
                $columns = array_keys($row);
            }
            $batch[] = $row;
            if (\count($batch) >= 100) {
                $this->writeInsert($fh, $table, $columns, $batch);
                $batch = [];
            }
        }
        if ($columns !== null && $batch !== []) {
            $this->writeInsert($fh, $table, $columns, $batch);
        }
        fwrite($fh, "\n");
    }

    /**
     * @param resource $fh
     * @param list<string|int> $columns
     * @param list<array<string, mixed>> $rows
     */
    private function writeInsert($fh, string $table, array $columns, array $rows): void
    {
        $colSql = implode(', ', array_map(
            static fn (string|int $c): string => '`' . str_replace('`', '``', (string) $c) . '`',
            $columns,
        ));
        $valueGroups = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($columns as $col) {
                $vals[] = $this->sqlValue($row[(string) $col] ?? null);
            }
            $valueGroups[] = '(' . implode(', ', $vals) . ')';
        }
        fwrite(
            $fh,
            'INSERT INTO `' . $table . '` (' . $colSql . ') VALUES' . "\n"
            . implode(",\n", $valueGroups) . ";\n",
        );
    }

    private function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_resource($value)) {
            $value = stream_get_contents($value) ?: '';
        }
        $str = (string) $value;

        return $this->db->pdo()->quote($str);
    }
}
