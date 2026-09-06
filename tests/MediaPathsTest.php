<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Paths;
use PHPUnit\Framework\TestCase;

final class MediaPathsTest extends TestCase
{
    public function testMediaPathUsesUploads(): void
    {
        $paths = new Paths('/tmp/hcms');
        $this->assertSame('/tmp/hcms/storage/uploads', $paths->media());
        $this->assertSame('public', $paths->publicDir);
        $this->assertSame('/tmp/hcms/public', $paths->public());
    }

    public function testCustomPublicDir(): void
    {
        $paths = new Paths('/tmp/hcms', 'public_html');
        $this->assertSame('public_html', $paths->publicDir);
        $this->assertSame('/tmp/hcms/public_html', $paths->public());
        $this->assertSame('/tmp/hcms/public_html/admin/index.html', $paths->adminIndex());
    }

    public function testNormalizeRejectsTraversal(): void
    {
        $this->assertSame('public', Paths::normalizePublicDir('../etc'));
        $this->assertSame('public_html', Paths::normalizePublicDir('public_html'));
    }

    public function testKnownWebRootNames(): void
    {
        $this->assertTrue(Paths::isKnownWebRootName('public_html'));
        $this->assertTrue(Paths::isKnownWebRootName('public'));
        $this->assertFalse(Paths::isKnownWebRootName('celebro.ru'));
    }
}
