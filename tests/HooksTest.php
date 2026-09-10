<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Database\Connection;
use Cms\Hooks\HookClient;
use Cms\Hooks\HookDeliveryRepository;
use Cms\Hooks\HookRejectedException;
use Cms\Hooks\InboundEndpointService;
use Cms\Hooks\ResourceHookRepository;
use Cms\Hooks\ResourceHookService;
use Cms\Resources\ResourceRepository;
use Cms\Security\HmacSignature;
use PDO;
use PHPUnit\Framework\TestCase;

final class HooksTest extends TestCase
{
    private Connection $db;
    private ResourceHookService $hooks;
    private HookClient $client;

    /** @var list<array{url: string, body: string, headers: array<string, string>}> */
    private array $captured = [];

    /** @var list<array{status: ?int, body: string, error: ?string, durationMs: int}> */
    private array $responses = [];

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE cms_resources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            content_type_id INTEGER NOT NULL DEFAULT 1,
            slug TEXT NOT NULL,
            label TEXT NOT NULL,
            endpoint TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'published\',
            settings_json TEXT NOT NULL DEFAULT \'{}\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE cms_content_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL,
            slug TEXT NOT NULL,
            is_system INTEGER NOT NULL DEFAULT 0
        )');
        $pdo->exec("INSERT INTO cms_content_types (id, label, slug, is_system) VALUES (1, 'Leads', 'leads', 0)");
        $pdo->exec("INSERT INTO cms_resources (id, content_type_id, slug, label, endpoint, status, settings_json, created_at, updated_at)
            VALUES (1, 1, 'leads', 'Leads', '/api/leads', 'published', '{}', datetime('now'), datetime('now'))");
        $pdo->exec('CREATE TABLE cms_resource_hooks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            resource_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            phase TEXT NOT NULL,
            url TEXT NOT NULL,
            secret TEXT NOT NULL,
            timeout_ms INTEGER NOT NULL DEFAULT 3000,
            on_failure TEXT NOT NULL DEFAULT \'reject\',
            status TEXT NOT NULL DEFAULT \'active\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE cms_hook_deliveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL,
            hook_id INTEGER NOT NULL,
            phase TEXT NOT NULL,
            payload TEXT NOT NULL,
            response_code INTEGER NULL,
            response_body TEXT NULL,
            duration_ms INTEGER NULL,
            status TEXT NOT NULL DEFAULT \'pending\',
            error_message TEXT NULL,
            created_at TEXT NOT NULL
        )');

        $this->db = new Connection($pdo);
        $this->captured = [];
        $this->responses = [];
        $this->client = new HookClient(function (string $url, string $body, array $headers, int $timeoutMs) {
            unset($timeoutMs);
            $this->captured[] = ['url' => $url, 'body' => $body, 'headers' => $headers];
            $next = array_shift($this->responses);

            return $next ?? [
                'status' => 200,
                'body' => '{"accept":true}',
                'error' => null,
                'durationMs' => 5,
            ];
        });
        $this->hooks = new ResourceHookService(
            new ResourceHookRepository($this->db),
            new HookDeliveryRepository($this->db),
            new ResourceRepository($this->db),
            $this->client,
        );
    }

    public function testHmacSignatureSharedWithWebhooks(): void
    {
        $body = '{"a":1}';
        $secret = 's3cret';
        $this->assertSame(
            'sha256=' . hash_hmac('sha256', $body, $secret),
            HmacSignature::header($body, $secret),
        );
        $this->assertTrue(HmacSignature::verify($body, $secret, HmacSignature::header($body, $secret)));
    }

    public function testBeforeCreateMutatesPayloadAndSignsRequest(): void
    {
        $this->hooks->create(1, [
            'name' => 'enrich',
            'phase' => 'before_create',
            'url' => 'https://hooks.example.test/before',
            'secret' => 'hooksec',
            'timeoutMs' => 2000,
            'onFailure' => 'reject',
            'status' => 'active',
        ]);

        $this->responses[] = [
            'status' => 200,
            'body' => json_encode(['accept' => true, 'payload' => ['email' => 'a@x.com', 'score' => 9]]),
            'error' => null,
            'durationMs' => 3,
        ];

        $payload = ['email' => 'a@x.com'];
        $this->hooks->runBeforeCreate(1, 'leads', $payload, [
            'ip' => '1.2.3.4',
            'userAgent' => 'test',
            'origin' => null,
            'source' => 'public',
        ]);

        $this->assertSame(['email' => 'a@x.com', 'score' => 9], $payload);
        $this->assertCount(1, $this->captured);
        $sig = $this->captured[0]['headers']['X-HCMS-Signature'] ?? '';
        $this->assertSame(HmacSignature::header($this->captured[0]['body'], 'hooksec'), $sig);
    }

    public function testBeforeCreateReject(): void
    {
        $this->hooks->create(1, [
            'name' => 'reject',
            'phase' => 'before_create',
            'url' => 'https://hooks.example.test/before',
            'secret' => 'hooksec',
            'timeoutMs' => 2000,
            'onFailure' => 'reject',
            'status' => 'active',
        ]);

        $this->responses[] = [
            'status' => 200,
            'body' => json_encode([
                'accept' => false,
                'error' => ['code' => 'DUPLICATE', 'message' => 'Already submitted'],
            ]),
            'error' => null,
            'durationMs' => 3,
        ];

        $payload = ['email' => 'a@x.com'];
        $this->expectException(HookRejectedException::class);
        $this->hooks->runBeforeCreate(1, 'leads', $payload, [
            'ip' => '1.2.3.4',
            'userAgent' => 'test',
            'origin' => null,
            'source' => 'public',
        ]);
    }

    public function testApplyFieldMap(): void
    {
        $mapped = InboundEndpointService::applyFieldMap(
            ['name' => 'Ann', 'email' => 'a@x.com', 'extra' => 1],
            ['name' => 'full_name', 'email' => 'email'],
        );
        $this->assertSame(['full_name' => 'Ann', 'email' => 'a@x.com'], $mapped);
        $this->assertSame(
            ['a' => 1],
            InboundEndpointService::applyFieldMap(['a' => 1], null),
        );
    }
}
