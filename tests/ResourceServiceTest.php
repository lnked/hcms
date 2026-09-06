<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Resources\ResourceService;
use PHPUnit\Framework\TestCase;

final class ResourceServiceTest extends TestCase
{
    public function testDefaultSettingsMerge(): void
    {
        $settings = ResourceService::defaultSettings([
            'public' => ['read' => true],
            'pagination' => false,
        ]);

        $this->assertTrue($settings['public']['read']);
        $this->assertFalse($settings['public']['create']);
        $this->assertFalse($settings['pagination']);
        $this->assertTrue($settings['apiEnabled']);
        $this->assertFalse($settings['softDelete']);
        $this->assertSame('hard', $settings['deleteStrategy']);
        $this->assertSame('', $settings['spam']['honeypotField']);
        $this->assertFalse($settings['spam']['requireCaptcha']);
    }

    public function testSoftDeleteStrategyNormalization(): void
    {
        $byStrategy = ResourceService::normalizeSettings(['deleteStrategy' => 'soft']);
        $this->assertTrue($byStrategy['softDelete']);
        $this->assertSame('soft', $byStrategy['deleteStrategy']);

        $byFlag = ResourceService::normalizeSettings(['softDelete' => true]);
        $this->assertTrue($byFlag['softDelete']);
        $this->assertSame('soft', $byFlag['deleteStrategy']);
    }

    public function testEndpointHelpers(): void
    {
        $this->assertSame('/api/posts', ResourceService::normalizeEndpoint('api/posts/'));
        $this->assertTrue(ResourceService::isValidEndpoint('/api/posts'));
        $this->assertTrue(ResourceService::isValidEndpoint('/api/v1/my-posts'));
        $this->assertFalse(ResourceService::isValidEndpoint('/api/blog/posts'));
        $this->assertSame('posts', ResourceService::publicKeyFromEndpoint('/api/posts'));
        $this->assertSame('my-posts', ResourceService::publicKeyFromEndpoint('/api/v1/my-posts'));
    }
}
