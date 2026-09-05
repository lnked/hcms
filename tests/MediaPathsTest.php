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
    }
}
