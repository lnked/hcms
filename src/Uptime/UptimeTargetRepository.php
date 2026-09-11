<?php

declare(strict_types=1);

namespace Cms\Uptime;

use Cms\Database\Connection;
use RuntimeException;

final class UptimeTargetRepository
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
            'SELECT id, name, url, kind, method, expected_status, timeout_ms, interval_seconds, enabled,
                    last_check_at, last_ok, last_status_code, last_latency_ms, last_error, last_heartbeat_at,
                    created_at, updated_at
             FROM cms_uptime_targets
             ORDER BY kind ASC, id ASC',
        );
    }

    public function count(): int
    {
        $row = $this->db->selectOne('SELECT COUNT(*) AS c FROM cms_uptime_targets');

        return $row === null ? 0 : (int) $row['c'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, name, url, kind, method, expected_status, timeout_ms, interval_seconds, enabled,
                    last_check_at, last_ok, last_status_code, last_latency_ms, last_error, last_heartbeat_at,
                    created_at, updated_at
             FROM cms_uptime_targets WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSelf(): ?array
    {
        return $this->db->selectOne(
            "SELECT id, name, url, kind, method, expected_status, timeout_ms, interval_seconds, enabled,
                    last_check_at, last_ok, last_status_code, last_latency_ms, last_error, last_heartbeat_at,
                    created_at, updated_at
             FROM cms_uptime_targets WHERE kind = 'self' LIMIT 1",
        );
    }

    /**
     * Ensure a single self target exists for the given health URL.
     *
     * @return array<string, mixed>
     */
    public function ensureSelf(string $healthUrl): array
    {
        $existing = $this->findSelf();
        if ($existing !== null) {
            if ((string) $existing['url'] !== $healthUrl) {
                $this->db->execute(
                    'UPDATE cms_uptime_targets SET url = :url, updated_at = :updated_at WHERE id = :id',
                    [
                        'id' => (int) $existing['id'],
                        'url' => $healthUrl,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ],
                );
                $existing = $this->find((int) $existing['id']);
            }

            if ($existing === null) {
                throw new RuntimeException('Failed to load self uptime target');
            }

            return $existing;
        }

        return $this->create([
            'name' => 'HCMS',
            'url' => $healthUrl,
            'kind' => 'self',
            'method' => 'GET',
            'expected_status' => 200,
            'timeout_ms' => UptimeSettings::DEFAULT_TIMEOUT_MS,
            'interval_seconds' => UptimeSettings::DEFAULT_INTERVAL_SECONDS,
            'enabled' => true,
        ]);
    }

    /**
     * @param array{
     *   name: string,
     *   url: string,
     *   kind: string,
     *   method: string,
     *   expected_status: int,
     *   timeout_ms: int,
     *   interval_seconds: int,
     *   enabled: bool
     * } $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_uptime_targets
             (name, url, kind, method, expected_status, timeout_ms, interval_seconds, enabled,
              last_check_at, last_ok, last_status_code, last_latency_ms, last_error, last_heartbeat_at,
              created_at, updated_at)
             VALUES
             (:name, :url, :kind, :method, :expected_status, :timeout_ms, :interval_seconds, :enabled,
              NULL, NULL, NULL, NULL, NULL, NULL, :created_at, :updated_at)',
            [
                'name' => $data['name'],
                'url' => $data['url'],
                'kind' => $data['kind'],
                'method' => $data['method'],
                'expected_status' => $data['expected_status'],
                'timeout_ms' => $data['timeout_ms'],
                'interval_seconds' => $data['interval_seconds'],
                'enabled' => $data['enabled'] ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create uptime target');
        }

        return $row;
    }

    /**
     * @param array{
     *   name?: string,
     *   url?: string,
     *   method?: string,
     *   expected_status?: int,
     *   timeout_ms?: int,
     *   interval_seconds?: int,
     *   enabled?: bool
     * } $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Uptime target not found', 404);
        }

        $this->db->execute(
            'UPDATE cms_uptime_targets
             SET name = :name,
                 url = :url,
                 method = :method,
                 expected_status = :expected_status,
                 timeout_ms = :timeout_ms,
                 interval_seconds = :interval_seconds,
                 enabled = :enabled,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'name' => $data['name'] ?? $existing['name'],
                'url' => $data['url'] ?? $existing['url'],
                'method' => $data['method'] ?? $existing['method'],
                'expected_status' => $data['expected_status'] ?? (int) $existing['expected_status'],
                'timeout_ms' => $data['timeout_ms'] ?? (int) $existing['timeout_ms'],
                'interval_seconds' => $data['interval_seconds'] ?? (int) $existing['interval_seconds'],
                'enabled' => array_key_exists('enabled', $data)
                    ? ($data['enabled'] ? 1 : 0)
                    : (int) $existing['enabled'],
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );

        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Uptime target not found after update', 404);
        }

        return $row;
    }

    /**
     * @param array{
     *   last_check_at: string,
     *   last_ok: bool,
     *   last_status_code: int|null,
     *   last_latency_ms: int|null,
     *   last_error: string|null
     * } $snapshot
     */
    public function updateProbeSnapshot(int $id, array $snapshot): void
    {
        $this->db->execute(
            'UPDATE cms_uptime_targets
             SET last_check_at = :last_check_at,
                 last_ok = :last_ok,
                 last_status_code = :last_status_code,
                 last_latency_ms = :last_latency_ms,
                 last_error = :last_error,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'last_check_at' => $snapshot['last_check_at'],
                'last_ok' => $snapshot['last_ok'] ? 1 : 0,
                'last_status_code' => $snapshot['last_status_code'],
                'last_latency_ms' => $snapshot['last_latency_ms'],
                'last_error' => $snapshot['last_error'],
                'updated_at' => $snapshot['last_check_at'],
            ],
        );
    }

    public function updateHeartbeat(int $id, string $at): void
    {
        $this->db->execute(
            'UPDATE cms_uptime_targets
             SET last_heartbeat_at = :last_heartbeat_at, updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'last_heartbeat_at' => $at,
                'updated_at' => $at,
            ],
        );
    }

    public function delete(int $id): void
    {
        $affected = $this->db->execute('DELETE FROM cms_uptime_targets WHERE id = :id', ['id' => $id]);
        if ($affected === 0) {
            throw new RuntimeException('Uptime target not found', 404);
        }
    }
}
