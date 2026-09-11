<?php

declare(strict_types=1);

namespace Cms\Hooks;

use Cms\Database\Connection;
use RuntimeException;

final class HookDeliveryRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{
     *   kind: string,
     *   hook_id: int,
     *   phase: string,
     *   payload: array<string, mixed>,
     *   response_code: int|null,
     *   response_body: string|null,
     *   duration_ms: int|null,
     *   status: string,
     *   error_message: string|null
     * } $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $responseBody = $data['response_body'];
        if (\is_string($responseBody) && \strlen($responseBody) > 4000) {
            $responseBody = substr($responseBody, 0, 3997) . '...';
        }

        $this->db->execute(
            'INSERT INTO cms_hook_deliveries
             (kind, hook_id, phase, payload, response_code, response_body, duration_ms, status, error_message, created_at)
             VALUES
             (:kind, :hook_id, :phase, :payload, :response_code, :response_body, :duration_ms, :status, :error_message, :created_at)',
            [
                'kind' => $data['kind'],
                'hook_id' => $data['hook_id'],
                'phase' => $data['phase'],
                'payload' => json_encode($data['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'response_code' => $data['response_code'],
                'response_body' => $responseBody,
                'duration_ms' => $data['duration_ms'],
                'status' => $data['status'],
                'error_message' => $data['error_message'],
                'created_at' => $now,
            ],
        );

        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create hook delivery');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, kind, hook_id, phase, payload, response_code, response_body, duration_ms, status, error_message, created_at
             FROM cms_hook_deliveries WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forHook(string $kind, int $hookId, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT id, kind, hook_id, phase, payload, response_code, response_body, duration_ms, status, error_message, created_at
             FROM cms_hook_deliveries
             WHERE kind = :kind AND hook_id = :hook_id
             ORDER BY id DESC
             LIMIT ' . max(1, min(200, $limit)),
            ['kind' => $kind, 'hook_id' => $hookId],
        );
    }
}
