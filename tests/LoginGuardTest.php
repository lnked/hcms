<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Auth\LoginGuard;
use Cms\Auth\MemoryRateLimitStore;
use Cms\Auth\RateLimiter;
use PHPUnit\Framework\TestCase;

final class LoginGuardTest extends TestCase
{
    public function testBlocksAfterMaxFailuresPerIp(): void
    {
        $guard = new LoginGuard(new RateLimiter(new MemoryRateLimitStore(), 900, 2));

        $this->assertTrue($guard->canAttempt('10.0.0.1', 'a@example.com'));
        $guard->fail('10.0.0.1', 'a@example.com');
        $this->assertTrue($guard->canAttempt('10.0.0.1', 'a@example.com'));
        $guard->fail('10.0.0.1', 'a@example.com');
        $this->assertFalse($guard->canAttempt('10.0.0.1', 'a@example.com'));
        $this->assertTrue($guard->canAttempt('10.0.0.2', 'b@example.com'));
    }

    public function testEmailBucketIgnoresCase(): void
    {
        $guard = new LoginGuard(new RateLimiter(new MemoryRateLimitStore(), 900, 1));
        $guard->fail('1.1.1.1', 'Admin@Example.com');

        $this->assertFalse($guard->canAttempt('8.8.8.8', 'admin@example.com'));
    }
}
