<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Database\Connection;
use Cms\Webhooks\WebhookDispatcher;
use Cms\Webhooks\WebhookRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class WebhookDispatcherTest extends TestCase
{
    private WebhookRepository $webhooks;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE cms_webhooks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            secret TEXT NOT NULL,
            events TEXT NOT NULL,
            resource_id INTEGER NULL,
            status TEXT NOT NULL DEFAULT \'active\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE cms_webhook_deliveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            webhook_id INTEGER NOT NULL,
            event TEXT NOT NULL,
            payload TEXT NOT NULL,
            response_code INTEGER NULL,
            duration_ms INTEGER NULL,
            attempt INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT \'pending\',
            error_message TEXT NULL,
            created_at TEXT NOT NULL
        )');
        $this->webhooks = new WebhookRepository(new Connection($pdo));
    }

    public function testSignatureHeaderFormat(): void
    {
        $header = WebhookDispatcher::signatureHeader('{"x":1}', 'secret');
        $this->assertSame('sha256=' . hash_hmac('sha256', '{"x":1}', 'secret'), $header);
        $this->assertMatchesRegularExpression('/^sha256=[a-f0-9]{64}$/', $header);
    }

    public function testDeliverOnceRecordsSuccessAndSendsSignature(): void
    {
        $webhook = $this->webhooks->create([
            'name' => 'hook',
            'url' => 'https://example.test/hook',
            'secret' => 'whsec',
            'events' => ['entry.created'],
            'resource_id' => null,
            'status' => 'active',
        ]);

        $captured = [];
        $dispatcher = new WebhookDispatcher(
            $this->webhooks,
            function (string $url, string $body, array $headers) use (&$captured): array {
                $captured = ['url' => $url, 'body' => $body, 'headers' => $headers];

                return [
                    'status' => 200,
                    'body' => 'ok',
                    'error' => null,
                    'durationMs' => 12,
                ];
            },
        );

        $payload = ['id' => 7, 'title' => 'Hi'];
        $delivery = $dispatcher->deliverOnce($webhook, 'entry.created', $payload);

        $this->assertSame('success', $delivery['status']);
        $this->assertSame(200, (int) $delivery['response_code']);
        $this->assertSame(12, (int) $delivery['duration_ms']);

        $expectedBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertSame('https://example.test/hook', $captured['url']);
        $this->assertSame($expectedBody, $captured['body']);
        $this->assertSame(
            WebhookDispatcher::signatureHeader((string) $expectedBody, 'whsec'),
            $captured['headers']['X-HCMS-Signature'],
        );
        $this->assertSame('entry.created', $captured['headers']['X-HCMS-Event']);
        $this->assertArrayHasKey('X-HCMS-Delivery-Id', $captured['headers']);
    }

    public function testDispatchSkipsNonMatchingEvents(): void
    {
        $this->webhooks->create([
            'name' => 'hook',
            'url' => 'https://example.test/hook',
            'secret' => 'whsec',
            'events' => ['entry.created'],
            'resource_id' => null,
            'status' => 'active',
        ]);

        $calls = 0;
        $dispatcher = new WebhookDispatcher(
            $this->webhooks,
            function () use (&$calls): array {
                $calls++;

                return [
                    'status' => 200,
                    'body' => '',
                    'error' => null,
                    'durationMs' => 1,
                ];
            },
        );

        $dispatcher->dispatch('entry.updated', ['id' => 1]);
        $this->assertSame(0, $calls);
        $this->assertSame([], $this->webhooks->deliveriesForWebhook(1));
    }
}
