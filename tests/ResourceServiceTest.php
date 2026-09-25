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
        $this->assertSame(0, $settings['cache']['maxAge']);
        $this->assertSame('', $settings['preview']['url']);
        $this->assertFalse($settings['localization']['enabled']);
        $this->assertFalse($settings['workflow']['enabled']);
    }

    public function testCacheAndPreviewNormalization(): void
    {
        $settings = ResourceService::normalizeSettings([
            'cache' => ['maxAge' => 999999],
            'preview' => ['url' => ' https://x.test/{token} '],
            'localization' => ['enabled' => 1],
            'workflow' => ['enabled' => true],
        ]);
        $this->assertSame(86400, $settings['cache']['maxAge']);
        $this->assertSame('https://x.test/{token}', $settings['preview']['url']);
        $this->assertTrue($settings['localization']['enabled']);
        $this->assertTrue($settings['workflow']['enabled']);
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

    public function testListColumnsNormalization(): void
    {
        $settings = ResourceService::normalizeSettings([
            'list' => [
                'columns' => [
                    ['field' => ' title ', 'visible' => true, 'label' => ' Headline ', 'width' => '240'],
                    ['field' => 'title', 'visible' => false],
                    ['field' => '', 'visible' => true],
                    ['field' => 'views', 'width' => 9000],
                    'garbage',
                ],
            ],
        ]);

        $this->assertSame([
            ['field' => 'title', 'visible' => true, 'label' => 'Headline', 'width' => 240],
            ['field' => 'views', 'visible' => true, 'label' => null, 'width' => 2000],
        ], $settings['list']['columns']);
    }

    public function testListColumnsDefaultToEmpty(): void
    {
        $this->assertSame([], ResourceService::defaultSettings()['list']['columns']);
        $this->assertSame([], ResourceService::normalizeSettings(['list' => 'nope'])['list']['columns']);
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
