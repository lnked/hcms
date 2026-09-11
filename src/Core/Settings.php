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

        return \is_int($decoded) ? $decoded : $default;
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

        return \is_string($decoded) ? $decoded : $default;
    }

    public function get(string $key): mixed
    {
        $row = $this->db->selectOne(
            'SELECT value_json FROM cms_settings WHERE `key` = :key',
            ['key' => $key],
        );
        if ($row === null) {
            return null;
        }

        return json_decode((string) $row['value_json'], true);
    }

    public function set(string $key, mixed $value): void
    {
        $now = date('Y-m-d H:i:s');
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        $existing = $this->db->selectOne(
            'SELECT `key` FROM cms_settings WHERE `key` = :key',
            ['key' => $key],
        );
        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO cms_settings (`key`, value_json, updated_at) VALUES (?, ?, ?)',
                [$key, $encoded, $now],
            );

            return;
        }
        $this->db->execute(
            'UPDATE cms_settings SET value_json = ?, updated_at = ? WHERE `key` = ?',
            [$encoded, $now, $key],
        );
    }
}
