<?php

declare(strict_types=1);

namespace Cms\Core;

use Cms\Database\Connection;

final class Settings
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function int(string $key, int $default): int
    {
        $row = $this->db->selectOne(
            'SELECT value_json FROM cms_settings WHERE `key` = :key',
            ['key' => $key],
        );
        if ($row === null) {
            return $default;
        }

        $decoded = json_decode((string) $row['value_json'], true);

        return is_int($decoded) ? $decoded : $default;
    }

    public function string(string $key, string $default): string
    {
        $row = $this->db->selectOne(
            'SELECT value_json FROM cms_settings WHERE `key` = :key',
            ['key' => $key],
        );
        if ($row === null) {
            return $default;
        }

        $decoded = json_decode((string) $row['value_json'], true);

        return is_string($decoded) ? $decoded : $default;
    }
}
