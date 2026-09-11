<?php

declare(strict_types=1);

namespace Cms\Uptime;

use Cms\Core\Exception\ValidationFailedException;
use InvalidArgumentException;
use RuntimeException;

/**
 * CRUD + serialization for uptime targets.
 */
final class UptimeService
{
    public function __construct(
        private readonly UptimeTargetRepository $targets,
        private readonly UptimeCheckRepository $checks,
        private readonly UptimeIncidentRepository $incidents,
        private readonly UptimeProbeService $probes,
        private readonly UptimeStatusService $status,
        private readonly UptimeSettings $settings,
        private readonly string $healthUrl,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return $this->status->summary();
    }

    /**
     * @return array<string, mixed>
     */
    public function statusPayload(): array
    {
        return $this->status->status();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTargets(): array
    {
        $this->targets->ensureSelf($this->healthUrl);

        return array_map(
            static fn (array $row): array => self::serializeTarget($row),
            $this->targets->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getTarget(int $id): array
    {
        $row = $this->targets->find($id);
        if ($row === null) {
            throw new RuntimeException('Uptime target not found', 404);
        }

        return self::serializeTarget($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createTarget(array $payload): array
    {
        if ($this->targets->count() >= UptimeSettings::MAX_TARGETS) {
            throw new InvalidArgumentException('Maximum of ' . UptimeSettings::MAX_TARGETS . ' uptime targets reached');
        }

        $data = $this->normalizeCreate($payload);

        return self::serializeTarget($this->targets->create($data));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateTarget(int $id, array $payload): array
    {
        $existing = $this->targets->find($id);
        if ($existing === null) {
            throw new RuntimeException('Uptime target not found', 404);
        }

        $data = $this->normalizeUpdate($payload);
        // Self URL is managed via ensureSelf; allow rename/interval/enabled only.
        if (($existing['kind'] ?? '') === 'self') {
            unset($data['url'], $data['method'], $data['expected_status']);
        }

        return self::serializeTarget($this->targets->update($id, $data));
    }

    public function deleteTarget(int $id): void
    {
        $existing = $this->targets->find($id);
        if ($existing === null) {
            throw new RuntimeException('Uptime target not found', 404);
        }
        if (($existing['kind'] ?? '') === 'self') {
            throw new InvalidArgumentException('Cannot delete the self uptime target');
        }

        $this->targets->delete($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function incidents(int $targetId, int $limit = 50): array
    {
        if ($this->targets->find($targetId) === null) {
            throw new RuntimeException('Uptime target not found', 404);
        }

        return array_map(
            static fn (array $row): array => self::serializeIncident($row),
            $this->incidents->forTarget($targetId, $limit),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function checks(int $targetId, int $limit = 50): array
    {
        if ($this->targets->find($targetId) === null) {
            throw new RuntimeException('Uptime target not found', 404);
        }

        return array_map(
            static fn (array $row): array => self::serializeCheck($row),
            $this->checks->forTarget($targetId, $limit),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function checkNow(int $id): array
    {
        return $this->probes->probeById($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function runDue(): array
    {
        $this->targets->ensureSelf($this->healthUrl);

        return $this->probes->runDue();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function serializeTarget(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'url' => (string) $row['url'],
            'kind' => (string) $row['kind'],
            'method' => (string) $row['method'],
            'expectedStatus' => (int) $row['expected_status'],
            'timeoutMs' => (int) $row['timeout_ms'],
            'intervalSeconds' => (int) $row['interval_seconds'],
            'enabled' => (bool) (int) $row['enabled'],
            'lastCheckAt' => $row['last_check_at'] !== null ? (string) $row['last_check_at'] : null,
            'lastOk' => $row['last_ok'] === null ? null : (bool) (int) $row['last_ok'],
            'lastStatusCode' => $row['last_status_code'] !== null ? (int) $row['last_status_code'] : null,
            'lastLatencyMs' => $row['last_latency_ms'] !== null ? (int) $row['last_latency_ms'] : null,
            'lastError' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
            'lastHeartbeatAt' => $row['last_heartbeat_at'] !== null ? (string) $row['last_heartbeat_at'] : null,
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function serializeIncident(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'targetId' => (int) $row['target_id'],
            'startedAt' => (string) $row['started_at'],
            'endedAt' => $row['ended_at'] !== null ? (string) $row['ended_at'] : null,
            'durationSeconds' => $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null,
            'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function serializeCheck(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'targetId' => (int) $row['target_id'],
            'checkedAt' => (string) $row['checked_at'],
            'ok' => (bool) (int) $row['ok'],
            'statusCode' => $row['status_code'] !== null ? (int) $row['status_code'] : null,
            'latencyMs' => $row['latency_ms'] !== null ? (int) $row['latency_ms'] : null,
            'error' => $row['error'] !== null ? (string) $row['error'] : null,
            'source' => (string) $row['source'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   name: string,
     *   url: string,
     *   kind: string,
     *   method: string,
     *   expected_status: int,
     *   timeout_ms: int,
     *   interval_seconds: int,
     *   enabled: bool
     * }
     */
    private function normalizeCreate(array $payload): array
    {
        $parsed = $this->parseWriteFields($payload, true);

        return [
            'name' => $parsed['name'],
            'url' => $parsed['url'],
            'kind' => 'external',
            'method' => $parsed['method'],
            'expected_status' => $parsed['expected_status'],
            'timeout_ms' => $parsed['timeout_ms'],
            'interval_seconds' => $parsed['interval_seconds'],
            'enabled' => $parsed['enabled'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   name?: string,
     *   url?: string,
     *   method?: string,
     *   expected_status?: int,
     *   timeout_ms?: int,
     *   interval_seconds?: int,
     *   enabled?: bool
     * }
     */
    private function normalizeUpdate(array $payload): array
    {
        $parsed = $this->parseWriteFields($payload, false);
        $out = [];
        if (\array_key_exists('name', $payload)) {
            $out['name'] = $parsed['name'];
        }
        if (\array_key_exists('url', $payload)) {
            $out['url'] = $parsed['url'];
        }
        if (\array_key_exists('method', $payload)) {
            $out['method'] = $parsed['method'];
        }
        if (\array_key_exists('expectedStatus', $payload)) {
            $out['expected_status'] = $parsed['expected_status'];
        }
        if (\array_key_exists('timeoutMs', $payload)) {
            $out['timeout_ms'] = $parsed['timeout_ms'];
        }
        if (\array_key_exists('intervalSeconds', $payload)) {
            $out['interval_seconds'] = $parsed['interval_seconds'];
        }
        if (\array_key_exists('enabled', $payload)) {
            $out['enabled'] = $parsed['enabled'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   name: string,
     *   url: string,
     *   method: string,
     *   expected_status: int,
     *   timeout_ms: int,
     *   interval_seconds: int,
     *   enabled: bool
     * }
     */
    private function parseWriteFields(array $payload, bool $creating): array
    {
        $name = isset($payload['name']) && \is_string($payload['name']) ? trim($payload['name']) : '';
        $url = isset($payload['url']) && \is_string($payload['url']) ? trim($payload['url']) : '';
        $methodRaw = isset($payload['method']) && \is_string($payload['method'])
            ? strtoupper(trim($payload['method']))
            : 'GET';
        $method = $methodRaw === 'HEAD' ? 'HEAD' : 'GET';
        $expected = isset($payload['expectedStatus']) && is_numeric($payload['expectedStatus'])
            ? (int) $payload['expectedStatus']
            : 200;
        $timeout = isset($payload['timeoutMs']) && is_numeric($payload['timeoutMs'])
            ? (int) $payload['timeoutMs']
            : UptimeSettings::DEFAULT_TIMEOUT_MS;
        $interval = isset($payload['intervalSeconds']) && is_numeric($payload['intervalSeconds'])
            ? (int) $payload['intervalSeconds']
            : $this->settings->defaultIntervalSeconds();
        $enabled = \array_key_exists('enabled', $payload) ? (bool) $payload['enabled'] : true;

        if ($creating || \array_key_exists('name', $payload)) {
            if ($name === '' || \strlen($name) > 191) {
                throw ValidationFailedException::field('name', 'name is required (1–191 chars)');
            }
        }
        if ($creating || \array_key_exists('url', $payload)) {
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                throw ValidationFailedException::field('url', 'url must be a valid http(s) URL');
            }
            if (\strlen($url) > 2048) {
                throw ValidationFailedException::field('url', 'url is too long');
            }
        }
        if (\array_key_exists('method', $payload) && !\in_array($methodRaw, ['GET', 'HEAD'], true)) {
            throw ValidationFailedException::field('method', 'method must be GET or HEAD');
        }
        if ($expected < 100 || $expected > 599) {
            throw ValidationFailedException::field('expectedStatus', 'expectedStatus must be 100–599');
        }
        if ($timeout < 500 || $timeout > UptimeSettings::MAX_TIMEOUT_MS) {
            throw ValidationFailedException::field(
                'timeoutMs',
                'timeoutMs must be 500–' . UptimeSettings::MAX_TIMEOUT_MS,
            );
        }
        if ($interval < UptimeSettings::MIN_INTERVAL_SECONDS || $interval > 86400) {
            throw ValidationFailedException::field(
                'intervalSeconds',
                'intervalSeconds must be ' . UptimeSettings::MIN_INTERVAL_SECONDS . '–86400',
            );
        }

        return [
            'name' => $name,
            'url' => $url,
            'method' => $method,
            'expected_status' => $expected,
            'timeout_ms' => $timeout,
            'interval_seconds' => $interval,
            'enabled' => $enabled,
        ];
    }
}
