<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Http\ApiAccess;
use PHPUnit\Framework\TestCase;

final class ApiAccessTest extends TestCase
{
    public function testDefaultsAreUnrestricted(): void
    {
        $access = ApiAccess::defaults();
        $this->assertTrue($access->unrestricted);
        $this->assertTrue($access->allows('https://evil.example'));
        $this->assertTrue($access->allows(null));
    }

    public function testWhitelistBlocksUnknownOrigin(): void
    {
        $access = new ApiAccess(false, ['https://app.example.com', 'localhost']);
        $this->assertTrue($access->allows('https://app.example.com'));
        $this->assertTrue($access->allows('http://localhost:5173'));
        $this->assertFalse($access->allows('https://other.example.com'));
        $this->assertTrue($access->allows(null));
    }

    public function testWildcardHost(): void
    {
        $access = new ApiAccess(false, ['*.example.com']);
        $this->assertTrue($access->allows('https://www.example.com'));
        $this->assertTrue($access->allows('https://api.example.com:443'));
        $this->assertFalse($access->allows('https://example.com'));
        $this->assertFalse($access->allows('https://evil.com'));
    }

    public function testCorsHeadersOnlyWhenAllowed(): void
    {
        $access = new ApiAccess(false, ['https://app.example.com']);
        $ok = $access->corsHeaders('https://app.example.com');
        $this->assertSame('https://app.example.com', $ok['Access-Control-Allow-Origin'] ?? null);
        $this->assertSame([], $access->corsHeaders('https://nope.example'));
    }

    public function testValidatePayloadRequiresOriginsWhenRestricted(): void
    {
        $invalid = ApiAccess::validatePayload(['unrestricted' => false, 'allowedOrigins' => []]);
        $this->assertFalse($invalid['ok']);

        $valid = ApiAccess::validatePayload([
            'unrestricted' => false,
            'allowedOrigins' => ['  https://App.Example.com/path  ', 'demo.test'],
        ]);
        $this->assertTrue($valid['ok']);
        $this->assertSame([
            'unrestricted' => false,
            'allowedOrigins' => ['https://app.example.com', 'demo.test'],
        ], $valid['value']);
    }
}
