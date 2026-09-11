<?php

declare(strict_types=1);

namespace Cms\Uptime;

/**
 * Active HTTP probes for uptime targets.
 */
final class UptimeProbeService
{
    /** @var callable(string, string, int): array{status: ?int, error: ?string, durationMs: int} */
    private $httpClient;

    /**
     * @param (callable(string, string, int): array{status: ?int, error: ?string, durationMs: int})|null $httpClient
     */
    public function __construct(
        private readonly UptimeTargetRepository $targets,
        private readonly UptimeCheckRepository $checks,
        private readonly UptimeIncidentRepository $incidents,
        private readonly UptimeSettings $settings,
        ?callable $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? [$this, 'defaultHttpClient'];
    }

    /**
     * Probe all enabled targets that are due.
     *
     * @return list<array<string, mixed>>
     */
    public function runDue(?string $now = null, bool $skipSelf = false): array
    {
        $now ??= date('Y-m-d H:i:s');
        $results = [];
        foreach ($this->targets->all() as $target) {
            if (!(int) ($target['enabled'] ?? 0)) {
                continue;
            }
            if ($skipSelf && ($target['kind'] ?? '') === 'self') {
                continue;
            }
            if (!$this->isDue($target, $now)) {
                continue;
            }
            $results[] = $this->probeTarget($target, $now);
        }
        $this->prune();

        return $results;
    }

    /**
     * @param array<string, mixed> $target
     */
    public function isTargetDue(array $target, ?string $now = null): bool
    {
        return $this->isDue($target, $now ?? date('Y-m-d H:i:s'));
    }

    /**
     * Force-probe a single target by id (ignores interval).
     *
     * @return array<string, mixed>
     */
    public function probeById(int $id, ?string $now = null): array
    {
        $target = $this->targets->find($id);
        if ($target === null) {
            throw new \RuntimeException('Uptime target not found', 404);
        }

        $result = $this->probeTarget($target, $now ?? date('Y-m-d H:i:s'));
        $this->prune();

        return $result;
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    public function probeTarget(array $target, string $now): array
    {
        $id = (int) $target['id'];
        $method = strtoupper((string) ($target['method'] ?? 'GET'));
        $timeoutMs = (int) ($target['timeout_ms'] ?? UptimeSettings::DEFAULT_TIMEOUT_MS);
        $expected = (int) ($target['expected_status'] ?? 200);
        $url = (string) $target['url'];

        $response = ($this->httpClient)($method, $url, $timeoutMs);
        $status = $response['status'];
        $error = $response['error'];
        $latency = $response['durationMs'];
        $ok = $status !== null && $status === $expected && $error === null;

        if (!$ok && $error === null && $status !== null) {
            $error = 'HTTP ' . $status . ' (expected ' . $expected . ')';
        }

        $wasOk = $target['last_ok'] === null ? null : (bool) (int) $target['last_ok'];

        $this->checks->create([
            'target_id' => $id,
            'checked_at' => $now,
            'ok' => $ok,
            'status_code' => $status,
            'latency_ms' => $latency,
            'error' => $error,
            'source' => 'probe',
        ]);

        $this->targets->updateProbeSnapshot($id, [
            'last_check_at' => $now,
            'last_ok' => $ok,
            'last_status_code' => $status,
            'last_latency_ms' => $latency,
            'last_error' => $error,
        ]);

        if ($ok) {
            if ($wasOk === false) {
                $open = $this->incidents->findOpen($id);
                if ($open !== null) {
                    $this->incidents->close((int) $open['id'], $now);
                }
            }
        } else {
            if ($wasOk !== false) {
                $this->incidents->open($id, $now, $error);
            }
        }

        $fresh = $this->targets->find($id);

        return [
            'targetId' => $id,
            'name' => (string) ($target['name'] ?? ''),
            'ok' => $ok,
            'statusCode' => $status,
            'latencyMs' => $latency,
            'error' => $error,
            'checkedAt' => $now,
            'target' => $fresh !== null ? UptimeService::serializeTarget($fresh) : null,
        ];
    }

    /**
     * @param array<string, mixed> $target
     */
    private function isDue(array $target, string $now): bool
    {
        $last = $target['last_check_at'] ?? null;
        if ($last === null || $last === '') {
            return true;
        }
        $lastTs = strtotime((string) $last);
        $nowTs = strtotime($now);
        if ($lastTs === false || $nowTs === false) {
            return true;
        }
        $interval = (int) ($target['interval_seconds'] ?? UptimeSettings::DEFAULT_INTERVAL_SECONDS);

        return ($nowTs - $lastTs) >= $interval;
    }

    private function prune(): void
    {
        $days = $this->settings->retentionDays();
        $before = date('Y-m-d H:i:s', time() - ($days * 86400));
        $this->checks->pruneOlderThan($before);
    }

    /**
     * @return array{status: ?int, error: ?string, durationMs: int}
     */
    private function defaultHttpClient(string $method, string $url, int $timeoutMs): array
    {
        $started = (int) round(microtime(true) * 1000);
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'status' => null,
                'error' => 'Failed to init HTTP client',
                'durationMs' => 0,
            ];
        }

        $timeoutSec = max(1, (int) ceil($timeoutMs / 1000));
        $customMethod = strtoupper($method) === 'HEAD' ? 'HEAD' : 'GET';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_CUSTOMREQUEST => $customMethod,
            CURLOPT_NOBODY => $customMethod === 'HEAD',
            CURLOPT_USERAGENT => 'HCMS-Uptime/1.0',
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $duration = max(0, (int) round(microtime(true) * 1000) - $started);

        if ($raw === false) {
            return [
                'status' => $status > 0 ? $status : null,
                'error' => $error !== '' ? $error : 'HTTP request failed',
                'durationMs' => $duration,
            ];
        }

        return [
            'status' => $status > 0 ? $status : null,
            'error' => null,
            'durationMs' => $duration,
        ];
    }
}
