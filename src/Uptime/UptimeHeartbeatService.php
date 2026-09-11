<?php

declare(strict_types=1);

namespace Cms\Uptime;

/**
 * Passive heartbeat for the self HCMS target via /admin/api/health.
 */
final class UptimeHeartbeatService
{
    public function __construct(
        private readonly UptimeTargetRepository $targets,
        private readonly UptimeCheckRepository $checks,
        private readonly UptimeIncidentRepository $incidents,
        private readonly UptimeSettings $settings,
        private readonly string $appUrl,
    ) {
    }

    /**
     * Record a heartbeat. Safe to call on every health hit.
     * If the gap since last heartbeat exceeds threshold, opens a retrospective closed incident.
     */
    public function touch(?string $now = null): void
    {
        $now ??= date('Y-m-d H:i:s');
        $healthUrl = rtrim($this->appUrl, '/') . '/admin/api/health';
        $self = $this->targets->ensureSelf($healthUrl);

        $last = $self['last_heartbeat_at'] ?? null;
        $stale = $this->settings->heartbeatStaleSeconds();

        if (\is_string($last) && $last !== '') {
            $lastTs = strtotime($last);
            $nowTs = strtotime($now);
            if ($lastTs !== false && $nowTs !== false && ($nowTs - $lastTs) > $stale) {
                $this->incidents->createClosed(
                    (int) $self['id'],
                    $last,
                    $now,
                    'heartbeat gap > ' . $stale . 's',
                );
                $this->checks->create([
                    'target_id' => (int) $self['id'],
                    'checked_at' => $now,
                    'ok' => true,
                    'status_code' => 200,
                    'latency_ms' => null,
                    'error' => null,
                    'source' => 'heartbeat',
                ]);
            }
        }

        $this->targets->updateHeartbeat((int) $self['id'], $now);

        // If self had an open probe incident and we're alive again, close it.
        $open = $this->incidents->findOpen((int) $self['id']);
        if ($open !== null) {
            $this->incidents->close((int) $open['id'], $now);
            $this->targets->updateProbeSnapshot((int) $self['id'], [
                'last_check_at' => (string) ($self['last_check_at'] ?? $now),
                'last_ok' => true,
                'last_status_code' => 200,
                'last_latency_ms' => $self['last_latency_ms'] !== null ? (int) $self['last_latency_ms'] : null,
                'last_error' => null,
            ]);
        }
    }
}
