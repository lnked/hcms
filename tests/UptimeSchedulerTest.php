<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Paths;
use Cms\Core\Settings;
use Cms\Database\Connection;
use Cms\Uptime\UptimeCheckRepository;
use Cms\Uptime\UptimeIncidentRepository;
use Cms\Uptime\UptimeProbeService;
use Cms\Uptime\UptimeScheduler;
use Cms\Uptime\UptimeSettings;
use Cms\Uptime\UptimeTargetRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class UptimeSchedulerTest extends TestCase
{
    private string $root;
    private UptimeTargetRepository $targets;
    private UptimeProbeService $probes;
    private UptimeScheduler $scheduler;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hcms-uptime-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/cache', 0777, true);

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

        $db = new Connection($pdo);
        $this->targets = new UptimeTargetRepository($db);
        $checks = new UptimeCheckRepository($db);
        $incidents = new UptimeIncidentRepository($db);
        $settings = new UptimeSettings(new Settings($db));
        $this->probes = new UptimeProbeService(
            $this->targets,
            $checks,
            $incidents,
            $settings,
            static fn (): array => [
                'status' => 200,
                'error' => null,
                'durationMs' => 5,
            ],
        );
        $this->scheduler = new UptimeScheduler(
            new Paths($this->root),
            $this->targets,
            $this->probes,
            $settings,
        );
    }

    protected function tearDown(): void
    {
        $cache = $this->root . '/storage/cache';
        foreach (glob($cache . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($cache);
        @rmdir($this->root . '/storage');
        @rmdir($this->root);
    }

    public function testRunNowProbesDueExternalAndSkipsSelfWhenAsked(): void
    {
        $this->targets->create([
            'name' => 'Ext',
            'url' => 'https://example.test/ok',
            'kind' => 'external',
            'method' => 'GET',
            'expected_status' => 200,
            'timeout_ms' => 5000,
            'interval_seconds' => 60,
            'enabled' => true,
        ]);
        $self = $this->targets->ensureSelf('http://127.0.0.1/admin/api/health');

        $results = $this->scheduler->runNow(true);
        $this->assertNotNull($results);
        $this->assertCount(1, $results);
        $this->assertSame('Ext', $results[0]['name']);

        $selfFresh = $this->targets->find((int) $self['id']);
        $this->assertNull($selfFresh['last_check_at'] ?? null);
    }

    public function testThrottleSkipsSecondImmediateRun(): void
    {
        $this->targets->create([
            'name' => 'Ext',
            'url' => 'https://example.test/ok',
            'kind' => 'external',
            'method' => 'GET',
            'expected_status' => 200,
            'timeout_ms' => 5000,
            'interval_seconds' => 60,
            'enabled' => true,
        ]);

        $this->assertNotNull($this->scheduler->runNow(false));
        // Target no longer due (just checked), and throttle file exists — second call skips.
        $this->assertNull($this->scheduler->runNow(false));
    }
}
