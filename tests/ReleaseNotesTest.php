<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\System\ReleaseNotes;
use PHPUnit\Framework\TestCase;

final class ReleaseNotesTest extends TestCase
{
    public function testManifestEntriesComeBackNewestFirst(): void
    {
        $releases = ReleaseNotes::fromManifest(['changelog' => [
            ['version' => '1.0.9', 'changes' => []],
            ['version' => '1.0.10', 'changes' => []],
            ['version' => '1.1.0', 'changes' => []],
        ]]);

        $this->assertSame(['1.1.0', '1.0.10', '1.0.9'], array_column($releases, 'version'));
    }

    public function testManifestWithoutChangelogYieldsNothing(): void
    {
        $this->assertSame([], ReleaseNotes::fromManifest(null));
        $this->assertSame([], ReleaseNotes::fromManifest(['version' => '1.0.0']));
        $this->assertSame([], ReleaseNotes::fromManifest(['changelog' => ['nonsense', ['no-version' => true]]]));
    }

    public function testDeltaCollectsOnlyVersionsBetweenFromAndTo(): void
    {
        $delta = ReleaseNotes::delta($this->releases(), '1.0.0', '1.2.0');

        $this->assertSame(['1.2.0', '1.1.0'], array_values(array_unique(array_column($delta['changes'], 'version'))));
        $this->assertCount(3, $delta['changes']);
    }

    public function testDeltaIgnoresReleasesNewerThanTheTarget(): void
    {
        $delta = ReleaseNotes::delta($this->releases(), '1.0.0', '1.1.0');

        $this->assertSame(['1.1.0'], array_unique(array_column($delta['changes'], 'version')));
    }

    public function testBreakingChangeIsReportedWithItsMigrationNote(): void
    {
        $delta = ReleaseNotes::delta($this->releases(), '1.0.0', '1.3.0');

        $this->assertTrue($delta['hasBreaking']);
        $this->assertSame(['Re-run the importer'], $delta['migrationNotes']);
    }

    public function testNothingToReportWhenAlreadyOnTheTargetVersion(): void
    {
        $delta = ReleaseNotes::delta($this->releases(), '1.3.0', '1.3.0');

        $this->assertSame([], $delta['changes']);
        $this->assertFalse($delta['hasBreaking']);
    }

    public function testChangeEntriesFallBackToSaneDefaults(): void
    {
        $delta = ReleaseNotes::delta(
            [['version' => '1.1.0', 'changes' => [['text' => 'Something']]]],
            '1.0.0',
            '1.1.0',
        );

        $this->assertSame([[
            'version' => '1.1.0',
            'type' => 'changed',
            'area' => null,
            'text' => 'Something',
            'migration' => null,
        ]], $delta['changes']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function releases(): array
    {
        return [
            ['version' => '1.3.0', 'changes' => [
                ['type' => 'breaking', 'area' => 'api', 'text' => 'Dropped v0 endpoints', 'migration' => 'Re-run the importer'],
            ]],
            ['version' => '1.2.0', 'changes' => [
                ['type' => 'fixed', 'area' => 'admin', 'text' => 'Fixed a crash'],
                ['type' => 'added', 'area' => 'media', 'text' => 'Added webp'],
            ]],
            ['version' => '1.1.0', 'changes' => [
                ['type' => 'changed', 'area' => 'core', 'text' => 'Faster boot'],
            ]],
            ['version' => '1.0.0', 'changes' => [
                ['type' => 'added', 'area' => 'core', 'text' => 'First release'],
            ]],
        ];
    }
}
