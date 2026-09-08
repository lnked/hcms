<?php

declare(strict_types=1);

namespace Cms\Auth;

use Closure;
use DateTimeImmutable;

final class RateLimiter
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock unix seconds, injectable for tests
     */
    public function __construct(
        private readonly RateLimitStore $store,
        private readonly int $windowSeconds,
        private readonly int $maxHits,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function allow(string $bucket): bool
    {
        $now = ($this->clock)();
        $current = $this->store->hits($bucket, $this->window($this->windowStart($now)));

        return $this->estimate($bucket, $now, $current) < $this->maxHits;
    }

    public function hit(string $bucket): bool
    {
        $now = ($this->clock)();
        $current = $this->store->increment($bucket, $this->window($this->windowStart($now)));

        return $this->estimate($bucket, $now, $current) <= $this->maxHits;
    }

    /**
     * Seconds until the bucket has room again. Without a bucket the answer degrades
     * to the end of the current window, which is never too early.
     */
    public function retryAfter(?string $bucket = null): int
    {
        $now = ($this->clock)();
        $start = $this->windowStart($now);
        $untilNextWindow = max(1, $start + $this->windowSeconds - $now);
        if ($bucket === null) {
            return $untilNextWindow;
        }

        $current = $this->store->hits($bucket, $this->window($start));
        $previous = $this->store->hits($bucket, $this->window($start - $this->windowSeconds));
        // One seat has to be free for the retry itself.
        $room = $this->maxHits - $current - 1;
        if ($previous <= 0 || $room < 0) {
            return $untilNextWindow;
        }

        // Wait until the previous window weighs no more than the room left:
        // previous * (windowSeconds - elapsed) / windowSeconds <= room
        $elapsedNeeded = (int) ceil($this->windowSeconds * (1 - $room / $previous));

        return max(1, min($untilNextWindow, $elapsedNeeded - ($now - $start)));
    }

    public function limit(): int
    {
        return $this->maxHits;
    }

    public function hits(string $bucket): int
    {
        $now = ($this->clock)();
        $current = $this->store->hits($bucket, $this->window($this->windowStart($now)));

        return (int) ceil($this->estimate($bucket, $now, $current));
    }

    /**
     * Sliding window: the previous window keeps counting, weighted by how much of it
     * still falls inside the last `windowSeconds`. A fixed window instead lets a client
     * spend the whole budget at the end of one window and the whole next one a second
     * later — 2x the limit in a burst.
     */
    private function estimate(string $bucket, int $now, int $current): float
    {
        $start = $this->windowStart($now);
        $previous = $this->store->hits($bucket, $this->window($start - $this->windowSeconds));
        if ($previous <= 0) {
            return (float) $current;
        }
        $weight = ($this->windowSeconds - ($now - $start)) / $this->windowSeconds;

        return $current + $previous * $weight;
    }

    private function windowStart(int $now): int
    {
        return (int) (floor($now / $this->windowSeconds) * $this->windowSeconds);
    }

    private function window(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable())->setTimestamp($timestamp);
    }
}
