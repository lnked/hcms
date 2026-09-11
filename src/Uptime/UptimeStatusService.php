<?php

declare(strict_types=1);

namespace Cms\Uptime;

/**
 * Aggregates for dashboard widget and status page.
 */
final class UptimeStatusService
{
    /** Stale if no check within interval × this factor (covers soft/external cron jitter). */
    private const STALE_INTERVAL_FACTOR = 2;

    public function __construct(
        private readonly UptimeTargetRepository $targets,
        private readonly UptimeIncidentRepository $incidents,
        private readonly UptimeSettings $settings,
        private readonly string $appUrl,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $this->targets->ensureSelf(rtrim($this->appUrl, '/') . '/admin/api/health');
        $all = $this->targets->all();
        $up = 0;
        $down = 0;
        $unknown = 0;
        foreach ($all as $target) {
            if (!(int) ($target['enabled'] ?? 0)) {
                continue;
            }
            if ($target['last_ok'] === null) {
                ++$unknown;
            } elseif ((int) $target['last_ok'] === 1) {
                ++$up;
            } else {
                ++$down;
            }
        }

        return [
            'up' => $up,
            'down' => $down,
            'unknown' => $unknown,
            'total' => $up + $down + $unknown,
            'uptimePercent24h' => $this->uptimePercent(24),
            'uptimePercent7d' => $this->uptimePercent(24 * 7),
            'openIncidents' => $this->countOpen($all),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $this->targets->ensureSelf(rtrim($this->appUrl, '/') . '/admin/api/health');
        $all = $this->targets->all();
        $targets = array_map(
            static fn (array $row): array => UptimeService::serializeTarget($row),
            $all,
        );

        return [
            'summary' => $this->summary(),
            'targets' => $targets,
            'scheduler' => $this->schedulerHealth($all),
        ];
    }

    /**
     * Infer whether automatic probes are keeping pace (soft cron and/or system cron).
     * Cannot detect crontab presence — only freshness of checks vs intervals.
     *
     * @param list<array<string, mixed>> $all
     * @return array{
     *     state: 'ok'|'stale'|'never'|'idle',
     *     softCronEnabled: bool,
     *     lastCheckAt: ?string,
     *     overdueCount: int,
     *     enabledCount: int
     * }
     */
    public function schedulerHealth(array $all): array
    {
        $enabled = array_values(array_filter(
            $all,
            static fn (array $t): bool => (int) ($t['enabled'] ?? 0) === 1,
        ));
        $softCronEnabled = $this->settings->softCronEnabled();
        if ($enabled === []) {
            return [
                'state' => 'idle',
                'softCronEnabled' => $softCronEnabled,
                'lastCheckAt' => null,
                'overdueCount' => 0,
                'enabledCount' => 0,
            ];
        }

        $now = time();
        $overdue = 0;
        $lastCheckTs = null;
        $checked = 0;
        foreach ($enabled as $target) {
            $interval = max(
                UptimeSettings::MIN_INTERVAL_SECONDS,
                (int) ($target['interval_seconds'] ?? UptimeSettings::DEFAULT_INTERVAL_SECONDS),
            );
            $last = $target['last_check_at'] ?? null;
            if ($last === null || $last === '') {
                ++$overdue;
                continue;
            }
            $ts = strtotime((string) $last);
            if ($ts === false) {
                ++$overdue;
                continue;
            }
            ++$checked;
            if ($lastCheckTs === null || $ts > $lastCheckTs) {
                $lastCheckTs = $ts;
            }
            if (($now - $ts) > ($interval * self::STALE_INTERVAL_FACTOR)) {
                ++$overdue;
            }
        }

        $state = $checked === 0 ? 'never' : ($overdue > 0 ? 'stale' : 'ok');

        return [
            'state' => $state,
            'softCronEnabled' => $softCronEnabled,
            'lastCheckAt' => $lastCheckTs !== null ? date('Y-m-d H:i:s', $lastCheckTs) : null,
            'overdueCount' => $overdue,
            'enabledCount' => \count($enabled),
        ];
    }

    /**
     * @param list<array<string, mixed>> $targets
     */
    private function countOpen(array $targets): int
    {
        $count = 0;
        foreach ($targets as $target) {
            if ($this->incidents->findOpen((int) $target['id']) !== null) {
                ++$count;
            }
        }

        return $count;
    }

    private function uptimePercent(int $hours): float
    {
        $toTs = time();
        $fromTs = $toTs - ($hours * 3600);
        $from = date('Y-m-d H:i:s', $fromTs);
        $to = date('Y-m-d H:i:s', $toTs);
        $window = $hours * 3600;
        if ($window <= 0) {
            return 100.0;
        }

        $targets = array_values(array_filter(
            $this->targets->all(),
            static fn (array $t): bool => (int) ($t['enabled'] ?? 0) === 1,
        ));
        if ($targets === []) {
            return 100.0;
        }

        $downSeconds = 0;
        foreach ($this->incidents->overlapping($from, $to) as $incident) {
            $start = strtotime((string) $incident['started_at']);
            $endRaw = $incident['ended_at'] ?? null;
            $end = \is_string($endRaw) && $endRaw !== '' ? strtotime($endRaw) : $toTs;
            if ($start === false || $end === false) {
                continue;
            }
            $overlapStart = max($start, $fromTs);
            $overlapEnd = min($end, $toTs);
            if ($overlapEnd > $overlapStart) {
                $downSeconds += ($overlapEnd - $overlapStart);
            }
        }

        // Average across targets: total possible = window * targetCount
        $capacity = $window * \count($targets);
        $up = max(0, $capacity - $downSeconds);
        $pct = ($up / $capacity) * 100;

        return round($pct, 2);
    }
}
