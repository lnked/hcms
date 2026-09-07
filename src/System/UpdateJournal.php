<?php

declare(strict_types=1);

namespace Cms\System;

use RuntimeException;

/**
 * Crash safety for the file swap at the end of an update.
 *
 * Each rename is recorded before the next one starts, so a worker that is
 * killed mid-swap (PHP-FPM request_terminate_timeout does not care about
 * set_time_limit(0)) leaves behind enough information to put the previous tree
 * back — either from the updater's own error path or from the next request via
 * {@see revertInterrupted()}.
 */
final class UpdateJournal
{
    /** An update that has not progressed for this long is considered dead. */
    private const STALE_AFTER_SECONDS = 600;

    public function __construct(private readonly string $storageDir)
    {
    }

    public function path(): string
    {
        return $this->storageDir . '/update-journal.json';
    }

    /**
     * @param array<string, mixed> $journal
     */
    public function write(array $journal): void
    {
        if (@file_put_contents($this->path(), json_encode($journal, JSON_UNESCAPED_SLASHES)) === false) {
            throw new RuntimeException('Unable to write the update journal');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        if (!is_file($this->path())) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->path()), true);

        return is_array($data) ? $data : null;
    }

    public function clear(): void
    {
        @unlink($this->path());
    }

    /**
     * @return list<string> Paths put back; empty when there was nothing to undo.
     */
    public function revert(): array
    {
        $journal = $this->read();
        if ($journal === null) {
            return [];
        }

        $reverted = [];
        $applied = is_array($journal['applied'] ?? null) ? $journal['applied'] : [];
        foreach (array_reverse($applied) as $dest) {
            if (!is_string($dest) || !file_exists($dest . '.old')) {
                continue;
            }
            self::remove($dest);
            if (@rename($dest . '.old', $dest)) {
                $reverted[] = $dest;
            }
        }

        $this->clear();

        return $reverted;
    }

    /**
     * Undo a swap whose updater is gone. Called on every boot, so it must stay
     * cheap and must never touch a swap that is still in progress.
     */
    public static function revertInterrupted(string $root): bool
    {
        $storage = $root . '/storage';
        $journal = new self($storage);
        if (!is_file($journal->path())) {
            return false;
        }
        if (!self::updaterIsDead($storage, $journal)) {
            return false;
        }

        $reverted = $journal->revert();
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        @unlink($storage . '/update.lock');
        @unlink($storage . '/update.job.json');
        @file_put_contents($storage . '/update-status.json', json_encode([
            'state' => 'failed',
            'step' => $reverted === [] ? 'rollback_failed' : 'rolled_back',
            'progress' => 100,
            'error' => 'The update was interrupted; the previous version was restored',
            'updatedAt' => date('c'),
        ], JSON_UNESCAPED_SLASHES));

        return true;
    }

    private static function updaterIsDead(string $storage, self $journal): bool
    {
        $lock = $storage . '/update.lock';
        if (!is_file($lock)) {
            return true;
        }

        $pid = (int) trim((string) @file_get_contents($lock));
        if ($pid > 0 && function_exists('posix_kill') && posix_kill($pid, 0)) {
            return false;
        }
        if ($pid > 0 && !function_exists('posix_kill')) {
            $age = time() - (int) @filemtime($journal->path());

            return $age > self::STALE_AFTER_SECONDS;
        }

        return true;
    }

    private static function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = @scandir($path);
        foreach ($items === false ? [] : $items as $item) {
            if ($item !== '.' && $item !== '..') {
                self::remove($path . '/' . $item);
            }
        }
        @rmdir($path);
    }
}
