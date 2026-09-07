<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Webhooks\WebhookDispatcher;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase
{
    public function testSignatureFormat(): void
    {
        $body = '{"hello":"world"}';
        $secret = 'test-secret';
        $header = WebhookDispatcher::signatureHeader($body, $secret);

        $this->assertSame('sha256=' . hash_hmac('sha256', $body, $secret), $header);
        $this->assertMatchesRegularExpression('/^sha256=[a-f0-9]{64}$/', $header);
    }

    public function testDifferentSecretsDiffer(): void
    {
        $body = '{"a":1}';
        $a = WebhookDispatcher::signatureHeader($body, 'a');
        $b = WebhookDispatcher::signatureHeader($body, 'b');
        $this->assertNotSame($a, $b);
    }
}
