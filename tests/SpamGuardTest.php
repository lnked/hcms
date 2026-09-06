<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Auth\MemoryRateLimitStore;
use Cms\Http\Request;
use Cms\Security\SpamGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SpamGuardTest extends TestCase
{
    public function testHoneypotRejects(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        $request = new Request('POST', '/api/leads', [], [], null, '', '127.0.0.1', 'test');
        $this->expectException(InvalidArgumentException::class);
        $guard->assertCreateAllowed(
            $request,
            ['spam' => ['honeypotField' => 'website', 'rejectDuplicates' => false]],
            ['name' => 'Ada', 'website' => 'http://spam'],
        );
    }

    public function testAllowsCleanPayload(): void
    {
        $guard = new SpamGuard(null, new MemoryRateLimitStore());
        $request = new Request('POST', '/api/leads', [], [], null, '', '127.0.0.1', 'test');
        $guard->assertCreateAllowed(
            $request,
            ['spam' => ['honeypotField' => 'website', 'rejectDuplicates' => false]],
            ['name' => 'Ada', 'website' => ''],
        );
        $this->assertTrue(true);
    }

    public function testRejectsDuplicate(): void
    {
        $store = new MemoryRateLimitStore();
        $guard = new SpamGuard(null, $store);
        $request = new Request('POST', '/api/leads', [], [], null, '', '127.0.0.1', 'test');
        $settings = ['spam' => ['rejectDuplicates' => true]];
        $payload = ['name' => 'Ada'];
        $guard->assertCreateAllowed($request, $settings, $payload);
        $this->expectException(InvalidArgumentException::class);
        $guard->assertCreateAllowed($request, $settings, $payload);
    }
}
