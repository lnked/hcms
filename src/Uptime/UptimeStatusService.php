<?php

declare(strict_types=1);

namespace Cms\Uptime;

/**
 * Aggregates for dashboard widget and status page.
 */
final class UptimeStatusService
{
    public function __construct(
        private readonly UptimeTargetRepository $targets,
        private readonly UptimeIncidentRepository $incidents,
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
        $targets = array_map(
            static fn (array $row): array => UptimeService::serializeTarget($row),
            $this->targets->all(),
        );

        return [
            'summary' => $this->summary(),
            'targets' => $targets,
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
