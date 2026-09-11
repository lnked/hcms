<?php

declare(strict_types=1);

namespace Cms\Hooks;

use Cms\Core\Exception\ValidationFailedException;
use Cms\Resources\ResourceRepository;
use RuntimeException;

final class ResourceHookService
{
    public const PHASES = ['before_create', 'after_create'];

    public function __construct(
        private readonly ResourceHookRepository $hooks,
        private readonly HookDeliveryRepository $deliveries,
        private readonly ResourceRepository $resources,
        private readonly HookClient $client,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $resourceId): array
    {
        $this->assertResource($resourceId);

        return array_map(fn (array $row): array => $this->serialize($row), $this->hooks->forResource($resourceId));
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $resourceId, int $id): array
    {
        $row = $this->requireOwned($resourceId, $id);

        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(int $resourceId, array $payload): array
    {
        $this->assertResource($resourceId);
        $data = $this->normalizeWrite($payload, true);
        $data['resource_id'] = $resourceId;

        return $this->serialize($this->hooks->create($data));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $resourceId, int $id, array $payload): array
    {
        $this->requireOwned($resourceId, $id);
        $data = $this->normalizeWrite($payload, false);

        return $this->serialize($this->hooks->update($id, $data));
    }

    public function delete(int $resourceId, int $id): void
    {
        $this->requireOwned($resourceId, $id);
        $this->hooks->delete($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deliveries(int $resourceId, int $id, int $limit = 50): array
    {
        $this->requireOwned($resourceId, $id);

        return array_map(
            fn (array $row): array => $this->serializeDelivery($row),
            $this->deliveries->forHook('resource', $id, $limit),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function test(int $resourceId, int $id): array
    {
        $row = $this->requireOwned($resourceId, $id);
        $payload = [
            'test' => true,
            'hookId' => (int) $row['id'],
            'resourceId' => $resourceId,
            'phase' => $row['phase'],
            'payload' => ['sample' => true],
            'meta' => [
                'ip' => '127.0.0.1',
                'userAgent' => 'HCMS-Hooks-Test/1.0',
                'origin' => null,
                'source' => 'admin',
            ],
            'sentAt' => date('c'),
        ];

        $result = $this->client->post(
            (string) $row['url'],
            (string) $row['secret'],
            $payload,
            (int) $row['timeout_ms'],
            (string) $row['phase'],
            ['X-HCMS-Delivery-Id' => 'test'],
        );

        $delivery = $this->deliveries->create([
            'kind' => 'resource',
            'hook_id' => (int) $row['id'],
            'phase' => (string) $row['phase'],
            'payload' => $payload,
            'response_code' => $result['status'],
            'response_body' => $result['body'],
            'duration_ms' => $result['durationMs'],
            'status' => $result['ok'] ? 'success' : 'failed',
            'error_message' => $result['error'],
        ]);

        return $this->serializeDelivery($delivery);
    }

    /**
     * Run active before_create hooks. Mutates payload in place.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public function runBeforeCreate(int $resourceId, string $slug, array &$payload, array $meta): void
    {
        foreach ($this->hooks->findActiveForPhase($resourceId, 'before_create') as $hook) {
            $this->invokeCreateHook($hook, $resourceId, $slug, 'before_create', $payload, $meta, null);
        }
    }

    /**
     * Run active after_create hooks. Returns merged handler `response` objects.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function runAfterCreate(int $resourceId, string $slug, array $entry, array $meta): array
    {
        $merged = [];
        foreach ($this->hooks->findActiveForPhase($resourceId, 'after_create') as $hook) {
            $payload = ['entry' => $entry];
            $response = $this->invokeCreateHook($hook, $resourceId, $slug, 'after_create', $payload, $meta, $entry);
            if (\is_array($response) && $response !== []) {
                $merged = array_merge($merged, $response);
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $hook
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     * @param array<string, mixed>|null $entry
     * @return array<string, mixed>|null handler response bag for after_create
     */
    private function invokeCreateHook(
        array $hook,
        int $resourceId,
        string $slug,
        string $phase,
        array &$payload,
        array $meta,
        ?array $entry,
    ): ?array {
        $requestPayload = [
            'resourceId' => $resourceId,
            'slug' => $slug,
            'phase' => $phase,
            'payload' => $phase === 'before_create' ? $payload : ($entry ?? $payload),
            'meta' => $meta,
        ];
        if ($phase === 'after_create' && $entry !== null) {
            $requestPayload['entry'] = $entry;
            $requestPayload['payload'] = $entry;
        }

        $result = $this->client->post(
            (string) $hook['url'],
            (string) $hook['secret'],
            $requestPayload,
            (int) $hook['timeout_ms'],
            $phase,
        );

        $decoded = $result['decoded'];
        $accept = \array_key_exists('accept', $decoded) ? (bool) $decoded['accept'] : $result['ok'];
        $onFailure = (string) $hook['on_failure'];

        if (!$result['ok']) {
            $this->deliveries->create([
                'kind' => 'resource',
                'hook_id' => (int) $hook['id'],
                'phase' => $phase,
                'payload' => $requestPayload,
                'response_code' => $result['status'],
                'response_body' => $result['body'],
                'duration_ms' => $result['durationMs'],
                'status' => 'failed',
                'error_message' => $result['error'],
            ]);
            if ($onFailure === 'reject') {
                throw new HookRejectedException('Hook request failed', 'HOOK_FAILED');
            }

            return null;
        }

        if (!$accept) {
            $error = \is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $code = isset($error['code']) && \is_string($error['code']) ? $error['code'] : 'HOOK_REJECTED';
            $message = isset($error['message']) && \is_string($error['message'])
                ? $error['message']
                : 'Rejected by hook';
            $this->deliveries->create([
                'kind' => 'resource',
                'hook_id' => (int) $hook['id'],
                'phase' => $phase,
                'payload' => $requestPayload,
                'response_code' => $result['status'],
                'response_body' => $result['body'],
                'duration_ms' => $result['durationMs'],
                'status' => 'rejected',
                'error_message' => $message,
            ]);

            throw new HookRejectedException($message, $code);
        }

        $this->deliveries->create([
            'kind' => 'resource',
            'hook_id' => (int) $hook['id'],
            'phase' => $phase,
            'payload' => $requestPayload,
            'response_code' => $result['status'],
            'response_body' => $result['body'],
            'duration_ms' => $result['durationMs'],
            'status' => 'success',
            'error_message' => null,
        ]);

        if ($phase === 'before_create' && isset($decoded['payload']) && \is_array($decoded['payload'])) {
            /** @var array<string, mixed> $mutated */
            $mutated = $decoded['payload'];
            $payload = $mutated;
        }

        if ($phase === 'after_create' && isset($decoded['response']) && \is_array($decoded['response'])) {
            return $decoded['response'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return ($creating is true ? array{
     *   name: string,
     *   phase: string,
     *   url: string,
     *   secret: string,
     *   timeout_ms: int,
     *   on_failure: string,
     *   status: string
     * } : array{
     *   name?: string,
     *   phase?: string,
     *   url?: string,
     *   secret?: string,
     *   timeout_ms?: int,
     *   on_failure?: string,
     *   status?: string
     * })
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

        if ($creating || \array_key_exists('phase', $payload)) {
            $phase = isset($payload['phase']) && \is_string($payload['phase']) ? trim($payload['phase']) : '';
            if (!\in_array($phase, self::PHASES, true)) {
                throw ValidationFailedException::field('phase', 'phase must be before_create or after_create');
            }
            $out['phase'] = $phase;
        }

        if ($creating || \array_key_exists('url', $payload)) {
            $out['url'] = $this->normalizeUrl($payload['url'] ?? null);
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

        if ($creating || \array_key_exists('timeoutMs', $payload)) {
            $timeout = isset($payload['timeoutMs']) ? (int) $payload['timeoutMs'] : 3000;
            if ($timeout < 100 || $timeout > 30000) {
                throw ValidationFailedException::field('timeoutMs', 'timeoutMs must be between 100 and 30000');
            }
            $out['timeout_ms'] = $timeout;
        }

        if ($creating || \array_key_exists('onFailure', $payload)) {
            $onFailure = isset($payload['onFailure']) && \is_string($payload['onFailure'])
                ? trim($payload['onFailure'])
                : 'reject';
            if (!\in_array($onFailure, ['reject', 'continue'], true)) {
                throw ValidationFailedException::field('onFailure', 'onFailure must be reject or continue');
            }
            $out['on_failure'] = $onFailure;
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

    private function normalizeUrl(mixed $url): string
    {
        $value = \is_string($url) ? trim($url) : '';
        if ($value === '' || mb_strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL)) {
            throw ValidationFailedException::field('url', 'url must be a valid URL');
        }
        $scheme = strtolower((string) (parse_url($value, PHP_URL_SCHEME) ?? ''));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw ValidationFailedException::field('url', 'url must be http or https');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOwned(int $resourceId, int $id): array
    {
        $this->assertResource($resourceId);
        $row = $this->hooks->find($id);
        if ($row === null || (int) $row['resource_id'] !== $resourceId) {
            throw new RuntimeException('Resource hook not found', 404);
        }

        return $row;
    }

    private function assertResource(int $resourceId): void
    {
        if ($this->resources->find($resourceId) === null) {
            throw new RuntimeException('Resource not found', 404);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'resourceId' => (int) $row['resource_id'],
            'name' => $row['name'],
            'phase' => $row['phase'],
            'url' => $row['url'],
            'secret' => $row['secret'],
            'timeoutMs' => (int) $row['timeout_ms'],
            'onFailure' => $row['on_failure'],
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
            'kind' => $row['kind'],
            'hookId' => (int) $row['hook_id'],
            'phase' => $row['phase'],
            'payload' => $payload,
            'responseCode' => $row['response_code'] === null ? null : (int) $row['response_code'],
            'responseBody' => $row['response_body'],
            'durationMs' => $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
            'status' => $row['status'],
            'errorMessage' => $row['error_message'],
            'createdAt' => $row['created_at'],
        ];
    }
}
