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
}
