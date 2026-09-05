<?php

declare(strict_types=1);

namespace Cms\Auth;

use DateTimeImmutable;

final class MemoryRateLimitStore implements RateLimitStore
{
    /** @var array<string, int> */
    private array $hits = [];

    public function increment(string $bucket, DateTimeImmutable $windowStart): int
    {
        $key = $this->key($bucket, $windowStart);
        $this->hits[$key] = ($this->hits[$key] ?? 0) + 1;

        return $this->hits[$key];
    }

    public function hits(string $bucket, DateTimeImmutable $windowStart): int
    {
        return $this->hits[$this->key($bucket, $windowStart)] ?? 0;
    }

    private function key(string $bucket, DateTimeImmutable $windowStart): string
    {
        return $bucket . '|' . $windowStart->format('Y-m-d H:i:s');
    }
}
