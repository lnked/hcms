<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Paths;
use Cms\System\ChangelogRepository;
use PHPUnit\Framework\TestCase;

final class ChangelogRepositoryTest extends TestCase
{
    public function testReadsFoundationRelease(): void
    {
        $releases = (new ChangelogRepository(new Paths(dirname(__DIR__))))->all();
        $this->assertNotSame([], $releases);
        $this->assertSame('0.12.0', $releases[0]['version']);
    }
}
