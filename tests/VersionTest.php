<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testCurrentReadsVersionFile(): void
    {
        $this->assertSame('0.9.0', Version::current());
    }

    public function testSemverCompare(): void
    {
        $this->assertTrue(Version::isGreater('1.10.0', '1.9.0'));
        $this->assertFalse(Version::isGreater('1.0.0', '1.0.0'));
        $this->assertSame(0, Version::compare('v1.2.0', '1.2.0'));
    }
}
