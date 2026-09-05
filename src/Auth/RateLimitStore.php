<?php

declare(strict_types=1);

namespace Cms\Auth;

use DateTimeImmutable;

interface RateLimitStore
{
    public function increment(string $bucket, DateTimeImmutable $windowStart): int;

    public function hits(string $bucket, DateTimeImmutable $windowStart): int;
}
