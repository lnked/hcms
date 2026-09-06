<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Http\ClientIp;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    public function testIgnoresForwardedWhenRemoteNotTrusted(): void
    {
        $ip = ClientIp::resolve('203.0.113.10', '198.51.100.1', ['10.0.0.1']);
        $this->assertSame('203.0.113.10', $ip);
    }

    public function testUsesForwardedWhenRemoteTrusted(): void
    {
        $ip = ClientIp::resolve('10.0.0.1', '198.51.100.7, 10.0.0.1', ['10.0.0.1']);
        $this->assertSame('198.51.100.7', $ip);
    }

    public function testCidrMatch(): void
    {
        $this->assertTrue(ClientIp::matchesAny('10.0.5.9', ['10.0.0.0/16']));
        $this->assertFalse(ClientIp::matchesAny('11.0.5.9', ['10.0.0.0/16']));
    }

    public function testNormalizeTrustedList(): void
    {
        $this->assertSame(['10.0.0.1', '10.0.0.0/8'], ClientIp::normalizeTrustedList('10.0.0.1, 10.0.0.0/8'));
    }
}
