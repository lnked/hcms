<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Webhooks\WebhookPresets;
use PHPUnit\Framework\TestCase;

final class WebhookPresetsTest extends TestCase
{
    public function testEmptyPayloadMode(): void
    {
        $enc = WebhookPresets::encodeBody(WebhookPresets::PAYLOAD_EMPTY, ['slug' => 'articles']);
        self::assertSame('{}', $enc['body']);
    }

    public function testSurrogateKeysPayload(): void
    {
        $enc = WebhookPresets::encodeBody(WebhookPresets::PAYLOAD_SURROGATE_KEYS, ['slug' => 'articles']);
        $decoded = json_decode($enc['body'], true);
        self::assertSame(['articles'], $decoded['surrogate_keys']);
        self::assertSame(['articles'], $decoded['tags']);
    }
}
