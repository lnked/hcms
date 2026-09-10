<?php

declare(strict_types=1);

namespace Cms\Hooks;

use Cms\Core\Exception\ValidationFailedException;
use Cms\Resources\ResourceRepository;
use RuntimeException;

final class InboundEndpointService
{
    public const SLUG_PATTERN = '/^[a-z][a-z0-9_-]{0,62}$/';

    public function __construct(
        private readonly InboundEndpointRepository $endpoints,
        private readonly HookDeliveryRepository $deliveries,
        private readonly ResourceRepository $resources,
        private readonly HookClient $client,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_map(fn (array $row): array => $this->serialize($row), $this->endpoints->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $row = $this->endpoints->find($id);
        if ($row === null) {
            throw new RuntimeException('Inbound endpoint not found', 404);
        }

        return $this->serialize($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findEnabledBySlug(string $slug): ?array
    {
        $row = $this->endpoints->findBySlug($slug);
        if ($row === null || !(bool) (int) ($row['enabled'] ?? 0)) {
            return null;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $data = $this->normalizeWrite($payload, true);
        $this->assertUniqueSlug($data['slug']);

        return $this->serialize($this->endpoints->create($data));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        if ($this->endpoints->find($id) === null) {
            throw new RuntimeException('Inbound endpoint not found', 404);
        }
        $data = $this->normalizeWrite($payload, false);
        if (isset($data['slug'])) {
            $this->assertUniqueSlug($data['slug'], $id);
        }

        return $this->serialize($this->endpoints->update($id, $data));
    }

    public function delete(int $id): void
    {
        $this->endpoints->delete($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deliveries(int $id, int $limit = 50): array
    {
        if ($this->endpoints->find($id) === null) {
            throw new RuntimeException('Inbound endpoint not found', 404);
        }

        return array_map(
            fn (array $row): array => $this->serializeDelivery($row),
            $this->deliveries->forHook('inbound', $id, $limit),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function test(int $id): array
    {
        $row = $this->endpoints->find($id);
        if ($row === null) {
            throw new RuntimeException('Inbound endpoint not found', 404);
        }

        $payload = [
            'test' => true,
            'endpointId' => (int) $row['id'],
            'slug' => $row['slug'],
            'phase' => 'inbound',
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
            (string) $row['target_url'],
            (string) $row['secret'],
            $payload,
            (int) $row['timeout_ms'],
            'inbound',
            ['X-HCMS-Delivery-Id' => 'test'],
        );

        $delivery = $this->deliveries->create([
            'kind' => 'inbound',
            'hook_id' => (int) $row['id'],
            'phase' => 'inbound',
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
     * Forward payload to target URL. Returns mutated payload + optional response bag.
     *
     * @param array<string, mixed> $endpoint raw DB row
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     * @return array{payload: array<string, mixed>, response: array<string, mixed>}
     */
    public function forward(array $endpoint, array $payload, array $meta): array
    {
        $requestPayload = [
            'endpointId' => (int) $endpoint['id'],
            'slug' => $endpoint['slug'],
            'phase' => 'inbound',
            'payload' => $payload,
            'meta' => $meta,
        ];

        $result = $this->client->post(
            (string) $endpoint['target_url'],
            (string) $endpoint['secret'],
            $requestPayload,
            (int) $endpoint['timeout_ms'],
            'inbound',
        );

        $decoded = $result['decoded'];
        $accept = array_key_exists('accept', $decoded) ? (bool) $decoded['accept'] : $result['ok'];
        $onFailure = (string) $endpoint['on_failure'];

        if (!$result['ok']) {
            $this->deliveries->create([
                'kind' => 'inbound',
                'hook_id' => (int) $endpoint['id'],
                'phase' => 'inbound',
                'payload' => $requestPayload,
                'response_code' => $result['status'],
                'response_body' => $result['body'],
                'duration_ms' => $result['durationMs'],
                'status' => 'failed',
                'error_message' => $result['error'],
            ]);
            if ($onFailure === 'reject') {
                throw new HookRejectedException('Inbound handler failed', 'HOOK_FAILED');
            }

            return ['payload' => $payload, 'response' => []];
        }

        if (!$accept) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $code = isset($error['code']) && is_string($error['code']) ? $error['code'] : 'HOOK_REJECTED';
            $message = isset($error['message']) && is_string($error['message'])
                ? $error['message']
                : 'Rejected by inbound handler';
            $this->deliveries->create([
                'kind' => 'inbound',
                'hook_id' => (int) $endpoint['id'],
                'phase' => 'inbound',
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
            'kind' => 'inbound',
            'hook_id' => (int) $endpoint['id'],
            'phase' => 'inbound',
            'payload' => $requestPayload,
            'response_code' => $result['status'],
            'response_body' => $result['body'],
            'duration_ms' => $result['durationMs'],
            'status' => 'success',
            'error_message' => null,
        ]);

        $nextPayload = $payload;
        if (isset($decoded['payload']) && is_array($decoded['payload'])) {
            $nextPayload = $decoded['payload'];
        }

        $response = [];
        if (isset($decoded['response']) && is_array($decoded['response'])) {
            $response = $decoded['response'];
        }

        return ['payload' => $nextPayload, 'response' => $response];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string>|null $fieldMap
     * @return array<string, mixed>
     */
    public static function applyFieldMap(array $payload, ?array $fieldMap): array
    {
        if ($fieldMap === null || $fieldMap === []) {
            return $payload;
        }

        $out = [];
        foreach ($fieldMap as $from => $to) {
            if (!is_string($from) || !is_string($to) || $from === '' || $to === '') {
                continue;
            }
            if (array_key_exists($from, $payload)) {
                $out[$to] = $payload[$from];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @return ($creating is true ? array{
     *   slug: string,
     *   label: string,
     *   target_url: string,
     *   secret: string,
     *   persist_resource_id: int|null,
     *   field_map: array<string, string>|null,
     *   enabled: bool,
     *   timeout_ms: int,
     *   on_failure: string
     * } : array{
     *   slug?: string,
     *   label?: string,
     *   target_url?: string,
     *   secret?: string,
     *   persist_resource_id?: int|null,
     *   field_map?: array<string, string>|null,
     *   enabled?: bool,
     *   timeout_ms?: int,
     *   on_failure?: string
     * })
     */
    private function normalizeWrite(array $payload, bool $creating): array
    {
        $out = [];

        if ($creating || array_key_exists('slug', $payload)) {
            $slug = isset($payload['slug']) && is_string($payload['slug']) ? trim($payload['slug']) : '';
            if ($slug === '' || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
                throw ValidationFailedException::field('slug', 'slug must match ^[a-z][a-z0-9_-]{0,62}$');
            }
            $out['slug'] = $slug;
        }

        if ($creating || array_key_exists('label', $payload)) {
            $label = isset($payload['label']) && is_string($payload['label']) ? trim($payload['label']) : '';
            if ($label === '' || mb_strlen($label) > 120) {
                throw ValidationFailedException::field('label', 'label is required (max 120)');
            }
            $out['label'] = $label;
        }

        if ($creating || array_key_exists('targetUrl', $payload)) {
            $out['target_url'] = $this->normalizeUrl($payload['targetUrl'] ?? null);
        }

        if ($creating || array_key_exists('secret', $payload)) {
            $secret = isset($payload['secret']) && is_string($payload['secret']) ? trim($payload['secret']) : '';
            if ($secret === '') {
                $secret = bin2hex(random_bytes(32));
            }
            if (strlen($secret) > 128) {
                throw ValidationFailedException::field('secret', 'secret max length is 128');
            }
            $out['secret'] = $secret;
        }

        if ($creating || array_key_exists('persistResourceId', $payload)) {
            $persistId = null;
            if ($payload['persistResourceId'] !== null && $payload['persistResourceId'] !== '') {
                $persistId = (int) $payload['persistResourceId'];
                if ($this->resources->find($persistId) === null) {
                    throw ValidationFailedException::field(
                        'persistResourceId',
                        'Unknown persistResourceId: ' . $persistId,
                    );
                }
            }
            $out['persist_resource_id'] = $persistId;
        }

        if ($creating || array_key_exists('fieldMap', $payload)) {
            $out['field_map'] = $this->normalizeFieldMap($payload['fieldMap'] ?? null);
        }

        if ($creating || array_key_exists('enabled', $payload)) {
            $out['enabled'] = !array_key_exists('enabled', $payload) || (bool) $payload['enabled'];
        }

        if ($creating || array_key_exists('timeoutMs', $payload)) {
            $timeout = isset($payload['timeoutMs']) ? (int) $payload['timeoutMs'] : 5000;
            if ($timeout < 100 || $timeout > 30000) {
                throw ValidationFailedException::field('timeoutMs', 'timeoutMs must be between 100 and 30000');
            }
            $out['timeout_ms'] = $timeout;
        }

        if ($creating || array_key_exists('onFailure', $payload)) {
            $onFailure = isset($payload['onFailure']) && is_string($payload['onFailure'])
                ? trim($payload['onFailure'])
                : 'reject';
            if (!in_array($onFailure, ['reject', 'continue'], true)) {
                throw ValidationFailedException::field('onFailure', 'onFailure must be reject or continue');
            }
            $out['on_failure'] = $onFailure;
        }

        return $out;
    }

    private function assertUniqueSlug(string $slug, ?int $exceptId = null): void
    {
        $existing = $this->endpoints->findBySlug($slug);
        if ($existing !== null && ($exceptId === null || (int) $existing['id'] !== $exceptId)) {
            throw ValidationFailedException::field('slug', 'slug already taken');
        }
    }

    private function normalizeUrl(mixed $url): string
    {
        $value = is_string($url) ? trim($url) : '';
        if ($value === '' || mb_strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL)) {
            throw ValidationFailedException::field('targetUrl', 'targetUrl must be a valid URL');
        }
        $scheme = strtolower((string) (parse_url($value, PHP_URL_SCHEME) ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw ValidationFailedException::field('targetUrl', 'targetUrl must be http or https');
        }

        return $value;
    }

    /**
     * @return array<string, string>|null
     */
    private function normalizeFieldMap(mixed $input): ?array
    {
        if ($input === null) {
            return null;
        }
        if (!is_array($input)) {
            throw ValidationFailedException::field('fieldMap', 'fieldMap must be an object of string→string');
        }
        $out = [];
        foreach ($input as $from => $to) {
            if (!is_string($from) || !is_string($to) || $from === '' || $to === '') {
                throw ValidationFailedException::field(
                    'fieldMap',
                    'fieldMap keys and values must be non-empty strings',
                );
            }
            $out[$from] = $to;
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        $fieldMap = $row['field_map'];
        if (is_string($fieldMap)) {
            $decoded = json_decode($fieldMap, true);
            $fieldMap = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($fieldMap)) {
            $fieldMap = null;
        }

        return [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'label' => $row['label'],
            'targetUrl' => $row['target_url'],
            'secret' => $row['secret'],
            'persistResourceId' => $row['persist_resource_id'] === null ? null : (int) $row['persist_resource_id'],
            'fieldMap' => $fieldMap,
            'enabled' => (bool) (int) $row['enabled'],
            'timeoutMs' => (int) $row['timeout_ms'],
            'onFailure' => $row['on_failure'],
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
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($payload)) {
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
