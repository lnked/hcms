<?php

declare(strict_types=1);

namespace Cms\Uptime;

use Cms\Core\Paths;

/**
 * Opportunistic due probes after HTTP responses (no system cron required).
 * Uses a lock + min interval so health/admin traffic does not stampede.
 */
final class UptimeScheduler
{
    public function __construct(
        private readonly Paths $paths,
        private readonly UptimeTargetRepository $targets,
        private readonly UptimeProbeService $probes,
        private readonly UptimeSettings $settings,
    ) {
    }

    /**
     * Queue a background due-run after the current response is sent.
     *
     * @param bool $skipSelf Skip kind=self (use when called from /health — heartbeat already covers it,
     *                       and probing self would re-enter health while the lock is held).
     */
    public function scheduleAfterResponse(bool $skipSelf = false): void
    {
        if (!$this->settings->softCronEnabled()) {
            return;
        }
        if (!$this->throttleAllows()) {
            return;
        }
        if (!$this->hasDue($skipSelf)) {
            return;
        }

        register_shutdown_function(function () use ($skipSelf): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            $this->runNow($skipSelf);
        });
    }

    /**
     * Synchronously run due probes if lock + throttle allow (CLI / tests).
     *
     * @return list<array<string, mixed>>|null null when skipped
     */
    public function runNow(bool $skipSelf = false): ?array
    {
        if (!$this->settings->softCronEnabled()) {
            return null;
        }

        $lockPath = $this->lockPath();
        $dir = dirname($lockPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        $fh = @fopen($lockPath, 'c+');
        if ($fh === false) {
            return null;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return null;
        }

        try {
            if (!$this->throttleAllows()) {
                return null;
            }
            if (!$this->hasDue($skipSelf)) {
                return null;
            }
            $this->touchThrottle();

            return $this->probes->runDue(null, $skipSelf);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function hasDue(bool $skipSelf): bool
    {
        $now = date('Y-m-d H:i:s');
        foreach ($this->targets->all() as $target) {
            if (!(int) ($target['enabled'] ?? 0)) {
                continue;
            }
            if ($skipSelf && ($target['kind'] ?? '') === 'self') {
                continue;
            }
            if ($this->probes->isTargetDue($target, $now)) {
                return true;
            }
        }

        return false;
    }

    private function throttleAllows(): bool
    {
        $path = $this->throttlePath();
        if (!is_file($path)) {
            return true;
        }
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return true;
        }

        return (time() - $mtime) >= $this->settings->softCronMinIntervalSeconds();
    }

    private function touchThrottle(): void
    {
        $path = $this->throttlePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, (string) time());
    }

    private function lockPath(): string
    {
        return $this->paths->cache() . '/uptime-scheduler.lock';
    }

    private function throttlePath(): string
    {
        return $this->paths->cache() . '/uptime-scheduler.last';
    }
}
