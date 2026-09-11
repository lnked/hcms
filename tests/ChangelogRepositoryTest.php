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
        $releases = (new ChangelogRepository(new Paths(\dirname(__DIR__))))->all();
        $this->assertNotSame([], $releases);
        $versions = array_map(static fn (array $r): string => (string) $r['version'], $releases);
        $this->assertContains('0.12.0', $versions);
        $this->assertSame(trim((string) file_get_contents(\dirname(__DIR__) . '/VERSION')), $releases[0]['version']);
    }

    public function testPageSlicesReleases(): void
    {
        $repo = new ChangelogRepository(new Paths(\dirname(__DIR__)));
        $all = $repo->all();
        $this->assertGreaterThan(1, \count($all));

        $page = $repo->page(null, null, 1, 1);
        $this->assertCount(1, $page['data']);
        $this->assertSame($all[0]['version'], $page['data'][0]['version']);
        $this->assertSame(1, $page['meta']['page']);
        $this->assertSame(1, $page['meta']['limit']);
        $this->assertSame(\count($all), $page['meta']['total']);
        $this->assertSame((int) ceil(\count($all) / 1), $page['meta']['totalPages']);

        $page2 = $repo->page(null, null, 2, 1);
        $this->assertSame($all[1]['version'], $page2['data'][0]['version']);
    }
}
