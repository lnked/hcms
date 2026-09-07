<?php

declare(strict_types=1);

namespace Cms\Webhooks;

use Cms\Database\Connection;
use RuntimeException;

final class WebhookRepository
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
            'SELECT id, name, url, secret, events, resource_id, status, created_at, updated_at
             FROM cms_webhooks
             ORDER BY id DESC',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, name, url, secret, events, resource_id, status, created_at, updated_at
             FROM cms_webhooks WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * Active webhooks that may match an event (event filter applied in PHP after JSON decode).
     *
     * @return list<array<string, mixed>>
     */
    public function findActiveForResource(?int $resourceId): array
    {
        if ($resourceId === null) {
            return $this->db->select(
                "SELECT id, name, url, secret, events, resource_id, status, created_at, updated_at
                 FROM cms_webhooks
                 WHERE status = 'active' AND resource_id IS NULL
                 ORDER BY id ASC",
            );
        }

        return $this->db->select(
            "SELECT id, name, url, secret, events, resource_id, status, created_at, updated_at
             FROM cms_webhooks
             WHERE status = 'active'
               AND (resource_id IS NULL OR resource_id = :resource_id)
             ORDER BY id ASC",
            ['resource_id' => $resourceId],
        );
    }

    /**
     * @param array{
     *   name: string,
     *   url: string,
     *   secret: string,
     *   events: list<string>,
     *   resource_id: int|null,
     *   status: string
     * } $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_webhooks
             (name, url, secret, events, resource_id, status, created_at, updated_at)
             VALUES (:name, :url, :secret, :events, :resource_id, :status, :created_at, :updated_at)',
            [
                'name' => $data['name'],
                'url' => $data['url'],
                'secret' => $data['secret'],
                'events' => json_encode($data['events'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'resource_id' => $data['resource_id'],
                'status' => $data['status'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $row = $this->find((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create webhook');
        }

        return $row;
    }

    /**
     * @param array{
     *   name?: string,
     *   url?: string,
     *   secret?: string,
     *   events?: list<string>,
     *   resource_id?: int|null,
     *   status?: string
     * } $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Webhook not found', 404);
        }

        $events = $existing['events'];
        if (array_key_exists('events', $data)) {
            $events = json_encode($data['events'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (!is_string($events)) {
            $events = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $this->db->execute(
            'UPDATE cms_webhooks
             SET name = :name,
                 url = :url,
                 secret = :secret,
                 events = :events,
                 resource_id = :resource_id,
                 status = :status,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'name' => $data['name'] ?? $existing['name'],
                'url' => $data['url'] ?? $existing['url'],
                'secret' => $data['secret'] ?? $existing['secret'],
                'events' => $events,
                'resource_id' => array_key_exists('resource_id', $data)
                    ? $data['resource_id']
                    : $existing['resource_id'],
                'status' => $data['status'] ?? $existing['status'],
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );

        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Webhook not found after update', 404);
        }

        return $row;
    }

    public function delete(int $id): void
    {
        $affected = $this->db->execute('DELETE FROM cms_webhooks WHERE id = :id', ['id' => $id]);
        if ($affected === 0) {
            throw new RuntimeException('Webhook not found', 404);
        }
    }

    /**
     * @param array{
     *   webhook_id: int,
     *   event: string,
     *   payload: array<string, mixed>,
     *   response_code: int|null,
     *   duration_ms: int|null,
     *   attempt: int,
     *   status: string,
     *   error_message: string|null
     * } $data
     * @return array<string, mixed>
     */
    public function createDelivery(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_webhook_deliveries
             (webhook_id, event, payload, response_code, duration_ms, attempt, status, error_message, created_at)
             VALUES
             (:webhook_id, :event, :payload, :response_code, :duration_ms, :attempt, :status, :error_message, :created_at)',
            [
                'webhook_id' => $data['webhook_id'],
                'event' => $data['event'],
                'payload' => json_encode($data['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'response_code' => $data['response_code'],
                'duration_ms' => $data['duration_ms'],
                'attempt' => $data['attempt'],
                'status' => $data['status'],
                'error_message' => $data['error_message'],
                'created_at' => $now,
            ],
        );

        $row = $this->findDelivery((int) $this->db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Failed to create webhook delivery');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDelivery(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, webhook_id, event, payload, response_code, duration_ms, attempt, status, error_message, created_at
             FROM cms_webhook_deliveries WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * @param array{
     *   response_code: int|null,
     *   duration_ms: int|null,
     *   status: string,
     *   error_message: string|null
     * } $data
     * @return array<string, mixed>
     */
    public function updateDelivery(int $id, array $data): array
    {
        $this->db->execute(
            'UPDATE cms_webhook_deliveries
             SET response_code = :response_code,
                 duration_ms = :duration_ms,
                 status = :status,
                 error_message = :error_message
             WHERE id = :id',
            [
                'id' => $id,
                'response_code' => $data['response_code'],
                'duration_ms' => $data['duration_ms'],
                'status' => $data['status'],
                'error_message' => $data['error_message'],
            ],
        );

        $row = $this->findDelivery($id);
        if ($row === null) {
            throw new RuntimeException('Webhook delivery not found after update', 404);
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deliveriesForWebhook(int $webhookId, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT id, webhook_id, event, payload, response_code, duration_ms, attempt, status, error_message, created_at
             FROM cms_webhook_deliveries
             WHERE webhook_id = :webhook_id
             ORDER BY id DESC
             LIMIT ' . max(1, min(200, $limit)),
            ['webhook_id' => $webhookId],
        );
    }
}
