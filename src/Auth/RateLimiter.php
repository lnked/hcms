<?php

declare(strict_types=1);

namespace Cms\Auth;

use DateTimeImmutable;

final class RateLimiter
{
    public function __construct(
        private readonly RateLimitStore $store,
        private readonly int $windowSeconds,
        private readonly int $maxHits,
    ) {
    }

    public function allow(string $bucket): bool
    {
        return $this->store->hits($bucket, $this->windowStart()) < $this->maxHits;
    }

    public function hit(string $bucket): bool
    {
        return $this->store->increment($bucket, $this->windowStart()) <= $this->maxHits;
    }

    public function retryAfter(): int
    {
        $start = $this->windowStart()->getTimestamp();

        return max(1, $start + $this->windowSeconds - time());
    }

    public function limit(): int
    {
        return $this->maxHits;
    }

    private function windowStart(): DateTimeImmutable
    {
        $ts = (int) (floor(time() / $this->windowSeconds) * $this->windowSeconds);

        return (new DateTimeImmutable())->setTimestamp($ts);
    }
}
