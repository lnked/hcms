<?php

declare(strict_types=1);

namespace Cms\Audit;

use Cms\Database\Connection;

final class AuditRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function page(int $page = 1, int $limit = 50, ?string $action = null): array
    {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $offset = ($page - 1) * $limit;
        $where = '';
        $params = [];
        if ($action !== null && $action !== '') {
            $where = ' WHERE action = :action';
            $params['action'] = $action;
        }

        $count = $this->db->selectOne('SELECT COUNT(*) AS c FROM cms_audit_logs' . $where, $params);
        $total = $count === null ? 0 : (int) $count['c'];
        $rows = $this->db->select(
            'SELECT * FROM cms_audit_logs' . $where . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        $meta = is_string($row['metadata_json'] ?? null)
            ? json_decode((string) $row['metadata_json'], true)
            : $row['metadata_json'];

        return [
            'id' => (int) $row['id'],
            'userId' => $row['user_id'] === null ? null : (int) $row['user_id'],
            'action' => $row['action'],
            'entityType' => $row['entity_type'],
            'entityId' => $row['entity_id'],
            'metadata' => is_array($meta) ? $meta : [],
            'ip' => $row['ip'],
            'userAgent' => $row['user_agent'],
            'createdAt' => $row['created_at'],
        ];
    }
}
