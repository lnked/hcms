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
    }
}
