<?php

declare(strict_types=1);

namespace Cms\Audit;

use Cms\Database\Connection;

final class ApiLogRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function write(
        string $method,
        string $path,
        int $status,
        int $durationMs,
        ?int $apiKeyId,
        string $ip,
    ): void {
        // Never store secrets/tokens in path beyond sanitizing query
        $safePath = strtok($path, '?') ?: $path;
        $this->db->execute(
            'INSERT INTO cms_api_logs (method, path, status, duration_ms, api_key_id, ip, created_at)
             VALUES (:method, :path, :status, :duration_ms, :api_key_id, :ip, :created_at)',
            [
                'method' => substr($method, 0, 16),
                'path' => substr($safePath, 0, 255),
                'status' => $status,
                'duration_ms' => max(0, $durationMs),
                'api_key_id' => $apiKeyId,
                'ip' => substr($ip, 0, 64),
                'created_at' => date('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function page(
        int $page = 1,
        int $limit = 50,
        ?string $path = null,
        ?int $minStatus = null,
        ?int $days = null,
    ): array {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        if ($path !== null && $path !== '') {
            $where[] = 'path = :path';
            $params['path'] = substr($path, 0, 255);
        }
        if ($minStatus !== null && $minStatus > 0) {
            $where[] = 'status >= :min_status';
            $params['min_status'] = $minStatus;
        }
        if ($days !== null && $days > 0) {
            $days = min(90, $days);
            $where[] = 'created_at >= :since';
            $params['since'] = date('Y-m-d 00:00:00', (int) strtotime('-' . ($days - 1) . ' days'));
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $count = $this->db->selectOne('SELECT COUNT(*) AS c FROM cms_api_logs' . $whereSql, $params);
        $total = $count === null ? 0 : (int) $count['c'];
        $rows = $this->db->select(
            'SELECT * FROM cms_api_logs' . $whereSql . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );

        return [
            'data' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'method' => $row['method'],
                'path' => $row['path'],
                'status' => (int) $row['status'],
                'durationMs' => (int) $row['duration_ms'],
                'apiKeyId' => $row['api_key_id'] === null ? null : (int) $row['api_key_id'],
                'ip' => $row['ip'],
                'createdAt' => $row['created_at'],
            ], $rows),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) max(1, (int) ceil($total / $limit)),
            ],
        ];
    }
}
