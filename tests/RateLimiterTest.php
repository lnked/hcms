<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Auth\MemoryRateLimitStore;
use Cms\Auth\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testAllowsUntilMaxThenBlocks(): void
    {
        $limiter = new RateLimiter(new MemoryRateLimitStore(), 60, 3);

        $this->assertTrue($limiter->hit('ip:1'));
        $this->assertTrue($limiter->hit('ip:1'));
        $this->assertTrue($limiter->hit('ip:1'));
        $this->assertFalse($limiter->hit('ip:1'));
        $this->assertFalse($limiter->allow('ip:1'));
        $this->assertGreaterThan(0, $limiter->retryAfter());
    }

    public function testBucketsAreIndependent(): void
    {
        $limiter = new RateLimiter(new MemoryRateLimitStore(), 60, 1);

        $this->assertTrue($limiter->hit('a'));
        $this->assertTrue($limiter->hit('b'));
        $this->assertFalse($limiter->hit('a'));
    }

    public function testWindowBoundaryDoesNotResetBudget(): void
    {
        $now = 1_000_000_020; // window start (divisible by 60)
        $limiter = new RateLimiter(new MemoryRateLimitStore(), 60, 4, function () use (&$now): int {
            return $now;
        });

        for ($i = 0; $i < 4; $i++) {
            $this->assertTrue($limiter->hit('ip:1'));
        }

        // A fixed window would hand out a fresh budget right here.
        $now += 60;
        $this->assertFalse($limiter->hit('ip:1'));

        // The previous window fades out and the budget comes back gradually.
        $now += 40;
        $this->assertTrue($limiter->hit('ip:1'));
    }

    public function testRetryAfterShrinksAsPreviousWindowFades(): void
    {
        $now = 1_000_000_020; // window start (divisible by 60)
        $limiter = new RateLimiter(new MemoryRateLimitStore(), 60, 2, function () use (&$now): int {
            return $now;
        });

        $limiter->hit('ip:1');
        $limiter->hit('ip:1');
        $now += 60;
        $this->assertFalse($limiter->hit('ip:1'));

        $early = $limiter->retryAfter('ip:1');
        $now += 20;
        $this->assertLessThan($early, $limiter->retryAfter('ip:1'));
        $this->assertGreaterThan(0, $limiter->retryAfter('ip:1'));
    }
}
