<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Auth\MemoryRateLimitStore;
use Cms\Http\Request;
use Cms\Security\RateLimitExceeded;
use Cms\Security\SpamGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SpamGuardTest extends TestCase
{
    public function testHoneypotRejects(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        $this->expectException(InvalidArgumentException::class);
        $guard->assertCreateAllowed(
            $this->request(),
            'leads',
            ['spam' => ['honeypotField' => 'website', 'rejectDuplicates' => false]],
            ['name' => 'Ada', 'website' => 'http://spam'],
        );
    }

    public function testAllowsCleanPayload(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        $guard->assertCreateAllowed(
            $this->request(),
            'leads',
            ['spam' => ['honeypotField' => 'website', 'rejectDuplicates' => false]],
            ['name' => 'Ada', 'website' => ''],
        );
        $this->assertTrue(true);
    }

    public function testRejectsDuplicate(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        $settings = ['spam' => ['rejectDuplicates' => true]];
        $payload = ['name' => 'Ada'];
        $guard->assertCreateAllowed($this->request(), 'leads', $settings, $payload);
        $this->expectException(InvalidArgumentException::class);
        $guard->assertCreateAllowed($this->request(), 'leads', $settings, $payload);
    }

    public function testDuplicateBucketIsPerResource(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        $settings = ['spam' => ['rejectDuplicates' => true]];
        $payload = ['name' => 'Ada'];
        $guard->assertCreateAllowed($this->request(), 'leads', $settings, $payload);
        $guard->assertCreateAllowed($this->request(), 'comments', $settings, $payload);
        $this->assertTrue(true);
    }

    public function testRateLimitIsPerResource(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        $settings = ['spam' => ['rateLimitPerMinute' => 1, 'rejectDuplicates' => false]];
        $guard->assertCreateAllowed($this->request(), 'leads', $settings, ['name' => 'Ada']);
        $guard->assertCreateAllowed($this->request(), 'comments', $settings, ['name' => 'Ada']);

        $this->expectException(RateLimitExceeded::class);
        $guard->assertCreateAllowed($this->request(), 'leads', $settings, ['name' => 'Grace']);
    }

    public function testRateLimitCarriesRetryAfterAndLimit(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        $settings = ['spam' => ['rateLimitPerMinute' => 1, 'rejectDuplicates' => false]];
        $guard->assertCreateAllowed($this->request(), 'leads', $settings, ['name' => 'Ada']);

        try {
            $guard->assertCreateAllowed($this->request(), 'leads', $settings, ['name' => 'Grace']);
            $this->fail('Expected RateLimitExceeded');
        } catch (RateLimitExceeded $e) {
            $this->assertSame(429, $e->getCode());
            $this->assertSame(1, $e->limit);
            $this->assertGreaterThan(0, $e->retryAfter);
        }
    }

    private function request(): Request
    {
        return new Request('POST', '/api/leads', [], [], null, '', '127.0.0.1', 'test');
    }
}
