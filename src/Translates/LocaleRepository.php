<?php

declare(strict_types=1);

namespace Cms\Translates;

use Cms\Database\Connection;
use RuntimeException;

final class LocaleRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->select(
            'SELECT * FROM cms_locales ORDER BY sort_order ASC, code ASC',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabled(): array
    {
        return $this->db->select(
            'SELECT * FROM cms_locales WHERE enabled = 1 ORDER BY sort_order ASC, code ASC',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $code): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_locales WHERE code = :code',
            ['code' => $code],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function defaultLocale(): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_locales WHERE is_default = 1 LIMIT 1',
        );
    }

    /**
     * @param array{code: string, label: string, enabled: int, is_default: int, sort_order: int} $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $this->db->execute(
            'INSERT INTO cms_locales (code, label, enabled, is_default, sort_order)
             VALUES (:code, :label, :enabled, :is_default, :sort_order)',
            $data,
        );
        $row = $this->find($data['code']);
        if ($row === null) {
            throw new RuntimeException('Failed to create locale');
        }

        return $row;
    }

    /**
     * @param array{label?: string, enabled?: int, is_default?: int, sort_order?: int} $data
     * @return array<string, mixed>
     */
    public function update(string $code, array $data): array
    {
        $sets = [];
        $params = ['code' => $code];
        foreach (['label', 'enabled', 'is_default', 'sort_order'] as $col) {
            if (\array_key_exists($col, $data)) {
                $sets[] = $col . ' = :' . $col;
                $params[$col] = $data[$col];
            }
        }
        if ($sets !== []) {
            $this->db->execute(
                'UPDATE cms_locales SET ' . implode(', ', $sets) . ' WHERE code = :code',
                $params,
            );
        }
        $row = $this->find($code);
        if ($row === null) {
            throw new RuntimeException('Locale not found');
        }

        return $row;
    }

    public function clearDefault(): void
    {
        $this->db->execute('UPDATE cms_locales SET is_default = 0');
    }

    public function delete(string $code): void
    {
        $this->db->execute('DELETE FROM cms_locales WHERE code = :code', ['code' => $code]);
    }
}
