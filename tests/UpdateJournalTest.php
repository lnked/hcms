<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\System\UpdateJournal;
use PHPUnit\Framework\TestCase;

final class UpdateJournalTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hcms-journal-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function testRevertPutsSwappedPathsBack(): void
    {
        $this->stageInterruptedSwap();

        $reverted = (new UpdateJournal($this->root . '/storage'))->revert();

        $this->assertSame([$this->root . '/src'], $reverted);
        $this->assertSame('old', file_get_contents($this->root . '/src/marker.txt'));
        $this->assertDirectoryDoesNotExist($this->root . '/src.old');
        $this->assertFileDoesNotExist($this->root . '/storage/update-journal.json');
    }

    public function testRevertIsANoOpWithoutAJournal(): void
    {
        $this->assertSame([], (new UpdateJournal($this->root . '/storage'))->revert());
    }

    public function testInterruptedSwapIsRolledBackWhenTheUpdaterIsGone(): void
    {
        $this->stageInterruptedSwap();

        $this->assertTrue(UpdateJournal::revertInterrupted($this->root));
        $this->assertSame('old', file_get_contents($this->root . '/src/marker.txt'));

        $status = json_decode((string) file_get_contents($this->root . '/storage/update-status.json'), true);
        $this->assertIsArray($status);
        $this->assertSame('failed', $status['state']);
        $this->assertSame('rolled_back', $status['step']);
    }

    public function testRunningUpdateIsLeftAlone(): void
    {
        $this->stageInterruptedSwap();
        file_put_contents($this->root . '/storage/update.lock', (string) getmypid());

        $this->assertFalse(UpdateJournal::revertInterrupted($this->root));
        $this->assertSame('new', file_get_contents($this->root . '/src/marker.txt'));
        $this->assertFileExists($this->root . '/storage/update-journal.json');
    }

    /**
     * Mimics a worker killed right after renaming src/ into place: the new tree
     * is live, the previous one waits in src.old and the journal knows about it.
     */
    private function stageInterruptedSwap(): void
    {
        mkdir($this->root . '/src', 0775, true);
        mkdir($this->root . '/src.old', 0775, true);
        file_put_contents($this->root . '/src/marker.txt', 'new');
        file_put_contents($this->root . '/src.old/marker.txt', 'old');

        file_put_contents($this->root . '/storage/update-journal.json', json_encode([
            'state' => 'swapping',
            'from' => '1.0.0',
            'to' => '1.1.0',
            'backup' => null,
            'applied' => [$this->root . '/src'],
            'startedAt' => date('c'),
        ]));
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeDir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
