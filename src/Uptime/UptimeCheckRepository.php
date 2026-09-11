<?php

declare(strict_types=1);

namespace Cms\Uptime;

use Cms\Database\Connection;
use RuntimeException;

final class UptimeCheckRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{
     *   target_id: int,
     *   checked_at: string,
     *   ok: bool,
     *   status_code: int|null,
     *   latency_ms: int|null,
     *   error: string|null,
     *   source: string
     * } $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $this->db->execute(
            'INSERT INTO cms_uptime_checks
             (target_id, checked_at, ok, status_code, latency_ms, error, source)
             VALUES
             (:target_id, :checked_at, :ok, :status_code, :latency_ms, :error, :source)',
            [
                'target_id' => $data['target_id'],
                'checked_at' => $data['checked_at'],
                'ok' => $data['ok'] ? 1 : 0,
                'status_code' => $data['status_code'],
                'latency_ms' => $data['latency_ms'],
                'error' => $data['error'],
                'source' => $data['source'],
            ],
        );

        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create uptime check');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, target_id, checked_at, ok, status_code, latency_ms, error, source
             FROM cms_uptime_checks WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forTarget(int $targetId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return $this->db->select(
            'SELECT id, target_id, checked_at, ok, status_code, latency_ms, error, source
             FROM cms_uptime_checks
             WHERE target_id = :target_id
             ORDER BY checked_at DESC, id DESC
             LIMIT ' . $limit,
            ['target_id' => $targetId],
        );
    }

    public function pruneOlderThan(string $before): int
    {
        return $this->db->execute(
            'DELETE FROM cms_uptime_checks WHERE checked_at < :before',
            ['before' => $before],
        );
    }
}
