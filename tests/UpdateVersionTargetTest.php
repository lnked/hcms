<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Version;
use Cms\System\ReleaseNotes;
use PHPUnit\Framework\TestCase;

final class UpdateVersionTargetTest extends TestCase
{
    public function testUpgradeDeltaIncludesIntermediateReleases(): void
    {
        $delta = ReleaseNotes::delta($this->releases(), '0.62.4', '0.62.6');

        $this->assertSame(
            ['0.62.6', '0.62.5'],
            array_values(array_unique(array_column($delta['changes'], 'version'))),
        );
    }

    public function testDowngradeDeltaShowsWhatIsUndone(): void
    {
        // Downgrade 0.62.6 → 0.62.4: preview uses delta(to, from) = delta(0.62.4, 0.62.6).
        $delta = ReleaseNotes::delta($this->releases(), '0.62.4', '0.62.6');

        $this->assertNotSame([], $delta['changes']);
        $this->assertContains('0.62.5', array_column($delta['changes'], 'version'));
        $this->assertContains('0.62.6', array_column($delta['changes'], 'version'));
    }

    public function testUpgradeOnlyAllowsGreaterVersions(): void
    {
        $current = '0.62.4';
        $available = ['0.62.6', '0.62.5', '0.62.3', '0.62.4'];
        $upgrades = array_values(array_filter(
            $available,
            static fn (string $v): bool => Version::isGreater($v, $current),
        ));

        $this->assertSame(['0.62.6', '0.62.5'], $upgrades);
    }

    public function testDowngradeOnlyAllowsOlderVersions(): void
    {
        $current = '0.62.6';
        $available = ['0.62.6', '0.62.5', '0.62.4', '0.62.7'];
        $downgrades = array_values(array_filter(
            $available,
            static fn (string $v): bool => Version::compare($v, $current) < 0,
        ));

        $this->assertSame(['0.62.5', '0.62.4'], $downgrades);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function releases(): array
    {
        return [
            ['version' => '0.62.6', 'changes' => [
                ['type' => 'fixed', 'text' => 'Patch two'],
            ]],
            ['version' => '0.62.5', 'changes' => [
                ['type' => 'fixed', 'text' => 'Patch one'],
            ]],
            ['version' => '0.62.4', 'changes' => [
                ['type' => 'changed', 'text' => 'Current'],
            ]],
        ];
    }
}
