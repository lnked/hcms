<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Settings;
use Cms\Database\Connection;
use Cms\Uptime\UptimeIncidentRepository;
use Cms\Uptime\UptimeSettings;
use Cms\Uptime\UptimeStatusService;
use Cms\Uptime\UptimeTargetRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class UptimeStatusServiceTest extends TestCase
{
    private UptimeStatusService $service;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE cms_settings (
            `key` TEXT PRIMARY KEY,
            value_json TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE cms_uptime_targets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            kind TEXT NOT NULL DEFAULT \'external\',
            method TEXT NOT NULL DEFAULT \'GET\',
            expected_status INTEGER NOT NULL DEFAULT 200,
            timeout_ms INTEGER NOT NULL DEFAULT 5000,
            interval_seconds INTEGER NOT NULL DEFAULT 60,
            enabled INTEGER NOT NULL DEFAULT 1,
            last_check_at TEXT NULL,
            last_ok INTEGER NULL,
            last_status_code INTEGER NULL,
            last_latency_ms INTEGER NULL,
            last_error TEXT NULL,
            last_heartbeat_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE cms_uptime_incidents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_id INTEGER NOT NULL,
            started_at TEXT NOT NULL,
            ended_at TEXT NULL,
            duration_seconds INTEGER NULL,
            reason TEXT NULL
        )');

        $db = new Connection($pdo);
        $this->service = new UptimeStatusService(
            new UptimeTargetRepository($db),
            new UptimeIncidentRepository($db),
            new UptimeSettings(new Settings($db)),
            'http://localhost/admin/api/health',
        );
    }

    public function testSchedulerIdleWhenNoEnabledTargets(): void
    {
        $health = $this->service->schedulerHealth([
            $this->target(['enabled' => 0, 'last_check_at' => null]),
        ]);

        self::assertSame('idle', $health['state']);
        self::assertSame(0, $health['enabledCount']);
        self::assertTrue($health['softCronEnabled']);
    }

    public function testSchedulerNeverWhenEnabledButUnchecked(): void
    {
        $health = $this->service->schedulerHealth([
            $this->target(['enabled' => 1, 'last_check_at' => null, 'interval_seconds' => 60]),
        ]);

        self::assertSame('never', $health['state']);
        self::assertSame(1, $health['overdueCount']);
        self::assertNull($health['lastCheckAt']);
    }

    public function testSchedulerOkWhenFresh(): void
    {
        $now = date('Y-m-d H:i:s');
        $health = $this->service->schedulerHealth([
            $this->target(['enabled' => 1, 'last_check_at' => $now, 'interval_seconds' => 60]),
        ]);

        self::assertSame('ok', $health['state']);
        self::assertSame(0, $health['overdueCount']);
        self::assertSame($now, $health['lastCheckAt']);
    }

    public function testSchedulerStaleWhenPastDoubleInterval(): void
    {
        $old = date('Y-m-d H:i:s', time() - 121);
        $health = $this->service->schedulerHealth([
            $this->target(['enabled' => 1, 'last_check_at' => $old, 'interval_seconds' => 60]),
        ]);

        self::assertSame('stale', $health['state']);
        self::assertSame(1, $health['overdueCount']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function target(array $overrides): array
    {
        return array_merge([
            'id' => 1,
            'enabled' => 1,
            'interval_seconds' => 60,
            'last_check_at' => null,
        ], $overrides);
    }
}
