<?php

declare(strict_types=1);

namespace Cms\Uptime;

use Cms\Database\Connection;
use RuntimeException;

final class UptimeIncidentRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOpen(int $targetId): ?array
    {
        return $this->db->selectOne(
            'SELECT id, target_id, started_at, ended_at, duration_seconds, reason
             FROM cms_uptime_incidents
             WHERE target_id = :target_id AND ended_at IS NULL
             ORDER BY started_at DESC, id DESC
             LIMIT 1',
            ['target_id' => $targetId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forTarget(int $targetId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return $this->db->select(
            'SELECT id, target_id, started_at, ended_at, duration_seconds, reason
             FROM cms_uptime_incidents
             WHERE target_id = :target_id
             ORDER BY started_at DESC, id DESC
             LIMIT ' . $limit,
            ['target_id' => $targetId],
        );
    }

    /**
     * Incidents overlapping [from, to] for uptime % calculation.
     *
     * @return list<array<string, mixed>>
     */
    public function overlapping(string $from, string $to): array
    {
        return $this->db->select(
            'SELECT id, target_id, started_at, ended_at, duration_seconds, reason
             FROM cms_uptime_incidents
             WHERE started_at < :to
               AND (ended_at IS NULL OR ended_at > :from)',
            ['from' => $from, 'to' => $to],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function open(int $targetId, string $startedAt, ?string $reason = null): array
    {
        $existing = $this->findOpen($targetId);
        if ($existing !== null) {
            return $existing;
        }

        $this->db->execute(
            'INSERT INTO cms_uptime_incidents
             (target_id, started_at, ended_at, duration_seconds, reason)
             VALUES (:target_id, :started_at, NULL, NULL, :reason)',
            [
                'target_id' => $targetId,
                'started_at' => $startedAt,
                'reason' => $reason,
            ],
        );

        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to open uptime incident');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function close(int $incidentId, string $endedAt): array
    {
        $row = $this->find($incidentId);
        if ($row === null) {
            throw new RuntimeException('Uptime incident not found', 404);
        }
        if ($row['ended_at'] !== null) {
            return $row;
        }

        $started = strtotime((string) $row['started_at']);
        $ended = strtotime($endedAt);
        $duration = ($started !== false && $ended !== false) ? max(0, $ended - $started) : 0;

        $this->db->execute(
            'UPDATE cms_uptime_incidents
             SET ended_at = :ended_at, duration_seconds = :duration_seconds
             WHERE id = :id',
            [
                'id' => $incidentId,
                'ended_at' => $endedAt,
                'duration_seconds' => $duration,
            ],
        );

        $updated = $this->find($incidentId);
        if ($updated === null) {
            throw new RuntimeException('Uptime incident not found after close', 404);
        }

        return $updated;
    }

    /**
     * Create an already-closed retrospective incident (heartbeat gap).
     *
     * @return array<string, mixed>
     */
    public function createClosed(int $targetId, string $startedAt, string $endedAt, ?string $reason = null): array
    {
        $started = strtotime($startedAt);
        $ended = strtotime($endedAt);
        $duration = ($started !== false && $ended !== false) ? max(0, $ended - $started) : 0;

        $this->db->execute(
            'INSERT INTO cms_uptime_incidents
             (target_id, started_at, ended_at, duration_seconds, reason)
             VALUES (:target_id, :started_at, :ended_at, :duration_seconds, :reason)',
            [
                'target_id' => $targetId,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'duration_seconds' => $duration,
                'reason' => $reason,
            ],
        );

        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create closed uptime incident');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, target_id, started_at, ended_at, duration_seconds, reason
             FROM cms_uptime_incidents WHERE id = :id',
            ['id' => $id],
        );
    }
}
