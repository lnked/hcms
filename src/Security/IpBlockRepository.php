<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Database\Connection;

final class IpBlockRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function isBlocked(string $ip, ?string $at = null): bool
    {
        $at ??= date('Y-m-d H:i:s');
        $row = $this->db->selectOne(
            'SELECT id FROM cms_ip_blocks
             WHERE ip = :ip AND (expires_at IS NULL OR expires_at > :at)
             LIMIT 1',
            ['ip' => $ip, 'at' => $at],
        );

        return $row !== null;
    }

    public function block(string $ip, string $reason, ?string $expiresAt, ?int $createdBy = null): int
    {
        $existing = $this->db->selectOne(
            'SELECT id FROM cms_ip_blocks WHERE ip = :ip LIMIT 1',
            ['ip' => $ip],
        );
        $now = date('Y-m-d H:i:s');
        if ($existing !== null) {
            $this->db->execute(
                'UPDATE cms_ip_blocks
                 SET reason = :reason, expires_at = :expires_at, created_by = :created_by, created_at = :created_at
                 WHERE id = :id',
                [
                    'id' => $existing['id'],
                    'reason' => $reason,
                    'expires_at' => $expiresAt,
                    'created_by' => $createdBy,
                    'created_at' => $now,
                ],
            );

            return (int) $existing['id'];
        }

        $this->db->execute(
            'INSERT INTO cms_ip_blocks (ip, reason, expires_at, created_by, created_at)
             VALUES (:ip, :reason, :expires_at, :created_by, :created_at)',
            [
                'ip' => $ip,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'created_by' => $createdBy,
                'created_at' => $now,
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM cms_ip_blocks WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function page(int $page = 1, int $limit = 50): array
    {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $offset = ($page - 1) * $limit;
        $count = $this->db->selectOne('SELECT COUNT(*) AS c FROM cms_ip_blocks');
        $total = $count === null ? 0 : (int) $count['c'];
        $rows = $this->db->select(
            'SELECT * FROM cms_ip_blocks ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
        );

        return [
            'data' => array_map(fn (array $row): array => $this->serialize($row), $rows),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) max(1, (int) ceil($total / $limit)),
            ],
        ];
    }

    public function countAuditActions(string $ip, string $action, int $withinSeconds): int
    {
        $since = date('Y-m-d H:i:s', time() - max(1, $withinSeconds));
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM cms_audit_logs
             WHERE ip = :ip AND action = :action AND created_at >= :since',
            ['ip' => $ip, 'action' => $action, 'since' => $since],
        );

        return $row === null ? 0 : (int) $row['c'];
    }

    /**
     * @param list<string> $actions
     * @return list<array{ip: string, count: int}>
     */
    public function topAuditIps(array $actions, int $withinHours = 24, int $limit = 10): array
    {
        if ($actions === []) {
            return [];
        }
        $since = date('Y-m-d H:i:s', time() - max(1, $withinHours) * 3600);
        $placeholders = [];
        $params = ['since' => $since];
        foreach ($actions as $i => $action) {
            $key = 'a' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $action;
        }
        $limit = max(1, min(50, $limit));
        $rows = $this->db->select(
            'SELECT ip, COUNT(*) AS c FROM cms_audit_logs
             WHERE created_at >= :since AND ip IS NOT NULL AND ip != \'\'
               AND action IN (' . implode(',', $placeholders) . ')
             GROUP BY ip
             ORDER BY c DESC
             LIMIT ' . $limit,
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'ip' => (string) $row['ip'],
                'count' => (int) $row['c'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{ip: string, count: int}>
     */
    public function topApiIps(int $withinHours = 24, int $limit = 10, ?int $status = null): array
    {
        $since = date('Y-m-d H:i:s', time() - max(1, $withinHours) * 3600);
        $limit = max(1, min(50, $limit));
        $params = ['since' => $since];
        $statusSql = '';
        if ($status !== null) {
            $statusSql = ' AND status = :status';
            $params['status'] = $status;
        }
        $rows = $this->db->select(
            'SELECT ip, COUNT(*) AS c FROM cms_api_logs
             WHERE created_at >= :since AND ip IS NOT NULL AND ip != \'\'' . $statusSql . '
             GROUP BY ip
             ORDER BY c DESC
             LIMIT ' . $limit,
            $params,
        );

        return array_map(static fn (array $row): array => [
            'ip' => (string) $row['ip'],
            'count' => (int) $row['c'],
        ], $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'ip' => $row['ip'],
            'reason' => $row['reason'],
            'expiresAt' => $row['expires_at'],
            'createdBy' => $row['created_by'] === null ? null : (int) $row['created_by'],
            'createdAt' => $row['created_at'],
        ];
    }
}
