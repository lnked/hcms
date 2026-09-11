<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Settings;
use Cms\Database\Connection;
use Cms\Uptime\UptimeCheckRepository;
use Cms\Uptime\UptimeHeartbeatService;
use Cms\Uptime\UptimeIncidentRepository;
use Cms\Uptime\UptimeProbeService;
use Cms\Uptime\UptimeSettings;
use Cms\Uptime\UptimeTargetRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class UptimeProbeServiceTest extends TestCase
{
    private Connection $db;
    private UptimeTargetRepository $targets;
    private UptimeCheckRepository $checks;
    private UptimeIncidentRepository $incidents;
    private UptimeSettings $settings;

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
        $pdo->exec('CREATE TABLE cms_uptime_checks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_id INTEGER NOT NULL,
            checked_at TEXT NOT NULL,
            ok INTEGER NOT NULL,
            status_code INTEGER NULL,
            latency_ms INTEGER NULL,
            error TEXT NULL,
            source TEXT NOT NULL DEFAULT \'probe\'
        )');
        $pdo->exec('CREATE TABLE cms_uptime_incidents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_id INTEGER NOT NULL,
            started_at TEXT NOT NULL,
            ended_at TEXT NULL,
            duration_seconds INTEGER NULL,
            reason TEXT NULL
        )');

        $this->db = new Connection($pdo);
        $this->targets = new UptimeTargetRepository($this->db);
        $this->checks = new UptimeCheckRepository($this->db);
        $this->incidents = new UptimeIncidentRepository($this->db);
        $this->settings = new UptimeSettings(new Settings($this->db));
    }

    public function testProbeFailureOpensIncidentAndRecoveryClosesIt(): void
    {
        $target = $this->targets->create([
            'name' => 'Example',
            'url' => 'https://example.test/health',
            'kind' => 'external',
            'method' => 'GET',
            'expected_status' => 200,
            'timeout_ms' => 5000,
            'interval_seconds' => 60,
            'enabled' => true,
        ]);

        $failing = new UptimeProbeService(
            $this->targets,
            $this->checks,
            $this->incidents,
            $this->settings,
            static fn (): array => [
                'status' => 503,
                'error' => null,
                'durationMs' => 40,
            ],
        );

        $fail = $failing->probeById((int) $target['id'], '2026-01-01 10:00:00');
        $this->assertFalse($fail['ok']);
        $open = $this->incidents->findOpen((int) $target['id']);
        $this->assertNotNull($open);
        $this->assertSame('2026-01-01 10:00:00', $open['started_at']);

        $okProbe = new UptimeProbeService(
            $this->targets,
            $this->checks,
            $this->incidents,
            $this->settings,
            static fn (): array => [
                'status' => 200,
                'error' => null,
                'durationMs' => 12,
            ],
        );
        $ok = $okProbe->probeById((int) $target['id'], '2026-01-01 10:05:00');
        $this->assertTrue($ok['ok']);
        $this->assertNull($this->incidents->findOpen((int) $target['id']));

        $closed = $this->incidents->forTarget((int) $target['id'], 10);
        $this->assertCount(1, $closed);
        $this->assertSame('2026-01-01 10:05:00', $closed[0]['ended_at']);
        $this->assertSame(300, (int) $closed[0]['duration_seconds']);
    }

    public function testHeartbeatGapCreatesClosedIncident(): void
    {
        $self = $this->targets->ensureSelf('http://127.0.0.1:8080/admin/api/health');
        $this->targets->updateHeartbeat((int) $self['id'], '2026-01-01 09:00:00');

        $settings = new Settings($this->db);
        $settings->set('uptime', [
            'retentionDays' => 30,
            'heartbeatStaleSeconds' => 120,
            'defaultIntervalSeconds' => 60,
        ]);
        $uptimeSettings = new UptimeSettings($settings);

        $heartbeat = new UptimeHeartbeatService(
            $this->targets,
            $this->checks,
            $this->incidents,
            $uptimeSettings,
            'http://127.0.0.1:8080',
        );
        $heartbeat->touch('2026-01-01 09:10:00');

        $incidents = $this->incidents->forTarget((int) $self['id'], 10);
        $this->assertCount(1, $incidents);
        $this->assertSame('2026-01-01 09:00:00', $incidents[0]['started_at']);
        $this->assertSame('2026-01-01 09:10:00', $incidents[0]['ended_at']);
        $this->assertSame(600, (int) $incidents[0]['duration_seconds']);
        $this->assertStringContainsString('heartbeat gap', (string) $incidents[0]['reason']);
    }

    public function testPruneRemovesOldChecks(): void
    {
        $target = $this->targets->create([
            'name' => 'Example',
            'url' => 'https://example.test/ok',
            'kind' => 'external',
            'method' => 'GET',
            'expected_status' => 200,
            'timeout_ms' => 5000,
            'interval_seconds' => 60,
            'enabled' => true,
        ]);
        $this->checks->create([
            'target_id' => (int) $target['id'],
            'checked_at' => '2020-01-01 00:00:00',
            'ok' => true,
            'status_code' => 200,
            'latency_ms' => 1,
            'error' => null,
            'source' => 'probe',
        ]);
        $this->checks->create([
            'target_id' => (int) $target['id'],
            'checked_at' => date('Y-m-d H:i:s'),
            'ok' => true,
            'status_code' => 200,
            'latency_ms' => 1,
            'error' => null,
            'source' => 'probe',
        ]);

        $deleted = $this->checks->pruneOlderThan(date('Y-m-d H:i:s', time() - 86400));
        $this->assertSame(1, $deleted);
        $this->assertCount(1, $this->checks->forTarget((int) $target['id'], 10));
    }
}
