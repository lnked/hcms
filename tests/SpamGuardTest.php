<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Auth\MemoryRateLimitStore;
use Cms\Http\Request;
use Cms\Security\RateLimitExceeded;
use Cms\Security\SpamGuard;
use Cms\Security\SpamRejected;
use PHPUnit\Framework\TestCase;

final class SpamGuardTest extends TestCase
{
    public function testHoneypotRejects(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        try {
            $payload = ['name' => 'Ada', 'website' => 'http://spam'];
            $guard->assertWriteAllowed(
                $this->request(),
                'leads',
                ['spam' => ['honeypotField' => 'website', 'rejectDuplicates' => false]],
                $payload,
                'create',
            );
            $this->fail('Expected SpamRejected');
        } catch (SpamRejected $e) {
            $this->assertSame('honeypot', $e->reason);
            $this->assertSame('Submission rejected', $e->publicMessage());
        }
    }

    public function testAllowsCleanPayload(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        $payload = ['name' => 'Ada', 'website' => ''];
        $guard->assertWriteAllowed(
            $this->request(),
            'leads',
            ['spam' => ['honeypotField' => 'website', 'rejectDuplicates' => false]],
            $payload,
            'create',
        );
        $this->assertTrue(true);
    }

    public function testRejectsDuplicate(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        $settings = ['spam' => ['rejectDuplicates' => true]];
        $payload = ['name' => 'Ada'];
        $guard->assertWriteAllowed($this->request(), 'leads', $settings, $payload, 'create');
        $this->expectException(SpamRejected::class);
        $guard->assertWriteAllowed($this->request(), 'leads', $settings, $payload, 'create');
    }

    public function testDeleteSkipsDuplicateCheck(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        $settings = ['spam' => ['rejectDuplicates' => true]];
        $empty = [];
        $guard->assertWriteAllowed($this->request('DELETE'), 'leads', $settings, $empty, 'delete');
        $guard->assertWriteAllowed($this->request('DELETE'), 'leads', $settings, $empty, 'delete');
        $this->assertTrue(true);
    }

    public function testDuplicateBucketIsPerResource(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        $settings = ['spam' => ['rejectDuplicates' => true]];
        $payload = ['name' => 'Ada'];
        $guard->assertWriteAllowed($this->request(), 'leads', $settings, $payload, 'create');
        $guard->assertWriteAllowed($this->request(), 'comments', $settings, $payload, 'create');
        $this->assertTrue(true);
    }

    public function testRateLimitIsPerResource(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        $settings = ['spam' => ['rateLimitPerMinute' => 1, 'rejectDuplicates' => false]];
        $a = ['name' => 'Ada'];
        $b = ['name' => 'Grace'];
        $guard->assertWriteAllowed($this->request(), 'leads', $settings, $a, 'create');
        $guard->assertWriteAllowed($this->request(), 'comments', $settings, $a, 'create');

        $this->expectException(RateLimitExceeded::class);
        $guard->assertWriteAllowed($this->request(), 'leads', $settings, $b, 'create');
    }

    public function testRateLimitCarriesRetryAfterAndLimit(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        $settings = ['spam' => ['rateLimitPerMinute' => 1, 'rejectDuplicates' => false]];
        $a = ['name' => 'Ada'];
        $b = ['name' => 'Grace'];
        $guard->assertWriteAllowed($this->request(), 'leads', $settings, $a, 'create');

        try {
            $guard->assertWriteAllowed($this->request(), 'leads', $settings, $b, 'create');
            $this->fail('Expected RateLimitExceeded');
        } catch (RateLimitExceeded $e) {
            $this->assertSame(429, $e->getCode());
            $this->assertSame(1, $e->limit);
            $this->assertGreaterThan(0, $e->retryAfter);
        }
    }

    private function request(string $method = 'POST'): Request
    {
        return new Request($method, '/api/leads', [], [], null, '', '127.0.0.1', 'test');
    }
}
