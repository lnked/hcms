<?php

declare(strict_types=1);

namespace Cms\Webhooks;

use Cms\Core\Exception\ValidationFailedException;
use Cms\Resources\ResourceRepository;
use RuntimeException;

final class WebhookService
{
    public const EVENTS = [
        'entry.created',
        'entry.updated',
        'entry.deleted',
        'resource.published',
    ];

    public function __construct(
        private readonly WebhookRepository $webhooks,
        private readonly ResourceRepository $resources,
        private readonly WebhookDispatcher $dispatcher,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_map(fn (array $row): array => $this->serialize($row), $this->webhooks->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $row = $this->webhooks->find($id);
        if ($row === null) {
            throw new RuntimeException('Webhook not found', 404);
        }

        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $data = $this->normalizeWrite($payload, true);
        /** @var array{name: string, url: string, secret: string, events: list<string>, resource_id: int|null, status: string} $data */

        return $this->serialize($this->webhooks->create($data));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        if ($this->webhooks->find($id) === null) {
            throw new RuntimeException('Webhook not found', 404);
        }

        $data = $this->normalizeWrite($payload, false);

        return $this->serialize($this->webhooks->update($id, $data));
    }

    public function delete(int $id): void
    {
        $this->webhooks->delete($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deliveries(int $id, int $limit = 50): array
    {
        if ($this->webhooks->find($id) === null) {
            throw new RuntimeException('Webhook not found', 404);
        }

        return array_map(
            fn (array $row): array => $this->serializeDelivery($row),
            $this->webhooks->deliveriesForWebhook($id, $limit),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function test(int $id): array
    {
        $row = $this->webhooks->find($id);
        if ($row === null) {
            throw new RuntimeException('Webhook not found', 404);
        }

        $resourceId = $row['resource_id'] === null ? null : (int) $row['resource_id'];
        $payload = [
            'test' => true,
            'webhookId' => (int) $row['id'],
            'resourceId' => $resourceId,
            'sentAt' => date('c'),
        ];

        $delivery = $this->dispatcher->deliverOnce(
            $row,
            'webhook.test',
            $payload,
            1,
        );

        return $this->serializeDelivery($delivery);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   name?: string,
     *   url?: string,
     *   secret?: string,
     *   events?: list<string>,
     *   resource_id?: int|null,
     *   status?: string
     * }|array{
     *   name: string,
     *   url: string,
     *   secret: string,
     *   events: list<string>,
     *   resource_id: int|null,
     *   status: string
     * }
     */
    private function normalizeWrite(array $payload, bool $creating): array
    {
        $out = [];

        if ($creating || \array_key_exists('name', $payload)) {
            $name = isset($payload['name']) && \is_string($payload['name']) ? trim($payload['name']) : '';
            if ($name === '' || mb_strlen($name) > 120) {
                throw ValidationFailedException::field('name', 'name is required (max 120)');
            }
            $out['name'] = $name;
        }

        if ($creating || \array_key_exists('url', $payload)) {
            $url = isset($payload['url']) && \is_string($payload['url']) ? trim($payload['url']) : '';
            if ($url === '' || mb_strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
                throw ValidationFailedException::field('url', 'url must be a valid URL');
            }
            $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
            if (!\in_array($scheme, ['http', 'https'], true)) {
                throw ValidationFailedException::field('url', 'url must be http or https');
            }
            $out['url'] = $url;
        }

        if ($creating || \array_key_exists('secret', $payload)) {
            $secret = isset($payload['secret']) && \is_string($payload['secret']) ? trim($payload['secret']) : '';
            if ($secret === '') {
                $secret = bin2hex(random_bytes(32));
            }
            if (\strlen($secret) > 128) {
                throw ValidationFailedException::field('secret', 'secret max length is 128');
            }
            $out['secret'] = $secret;
        }

        if ($creating || \array_key_exists('events', $payload)) {
            $out['events'] = $this->normalizeEvents($payload['events'] ?? null);
        }

        if ($creating || \array_key_exists('resourceId', $payload)) {
            $resourceId = null;
            if (\array_key_exists('resourceId', $payload) && $payload['resourceId'] !== null && $payload['resourceId'] !== '') {
                $resourceId = (int) $payload['resourceId'];
                if ($this->resources->find($resourceId) === null) {
                    throw ValidationFailedException::field('resourceId', 'Unknown resourceId: ' . $resourceId);
                }
            }
            $out['resource_id'] = $resourceId;
        }

        if ($creating || \array_key_exists('status', $payload)) {
            $status = isset($payload['status']) && \is_string($payload['status'])
                ? trim($payload['status'])
                : 'active';
            if (!\in_array($status, ['active', 'disabled'], true)) {
                throw ValidationFailedException::field('status', 'status must be active or disabled');
            }
            $out['status'] = $status;
        }

        return $out;
    }

    /**
     * @param mixed $input
     * @return list<string>
     */
    private function normalizeEvents(mixed $input): array
    {
        if (!\is_array($input) || $input === []) {
            throw ValidationFailedException::field('events', 'events must be a non-empty array');
        }
        $out = [];
        foreach ($input as $event) {
            if (!\is_string($event) || !\in_array($event, self::EVENTS, true)) {
                throw ValidationFailedException::field('events', 'Invalid event: ' . (\is_string($event) ? $event : \gettype($event)));
            }
            $out[] = $event;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        $events = $row['events'];
        if (\is_string($events)) {
            $decoded = json_decode($events, true);
            $events = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($events)) {
            $events = [];
        }

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'url' => $row['url'],
            'secret' => $row['secret'],
            'events' => array_values(array_filter($events, static fn (mixed $e): bool => \is_string($e))),
            'resourceId' => $row['resource_id'] === null ? null : (int) $row['resource_id'],
            'status' => $row['status'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serializeDelivery(array $row): array
    {
        $payload = $row['payload'];
        if (\is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($payload)) {
            $payload = [];
        }

        return [
            'id' => (int) $row['id'],
            'webhookId' => (int) $row['webhook_id'],
            'event' => $row['event'],
            'payload' => $payload,
            'responseCode' => $row['response_code'] === null ? null : (int) $row['response_code'],
            'durationMs' => $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
            'attempt' => (int) $row['attempt'],
            'status' => $row['status'],
            'errorMessage' => $row['error_message'],
            'createdAt' => $row['created_at'],
        ];
    }
}
