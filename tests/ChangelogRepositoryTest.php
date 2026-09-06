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
        $versions = array_map(static fn (array $r): string => (string) $r['version'], $releases);
        $this->assertContains('0.12.0', $versions);
        $this->assertSame(trim((string) file_get_contents(dirname(__DIR__) . '/VERSION')), $releases[0]['version']);
    }
}
