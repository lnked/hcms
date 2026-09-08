<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Security\IpMatcher;
use PHPUnit\Framework\TestCase;

final class IpMatcherTest extends TestCase
{
    public function testNormalizePattern(): void
    {
        $this->assertSame('10.0.0.1', IpMatcher::normalizePattern(' 10.0.0.1 '));
        $this->assertSame('10.0.0.0/8', IpMatcher::normalizePattern('10.0.0.0/8'));
        $this->assertSame('2001:db8::/32', IpMatcher::normalizePattern('2001:DB8::/32'));
        $this->assertNull(IpMatcher::normalizePattern('10.0.0.256'));
        $this->assertNull(IpMatcher::normalizePattern('10.0.0.0/33'));
        $this->assertNull(IpMatcher::normalizePattern('example.com'));
        $this->assertNull(IpMatcher::normalizePattern(''));
    }

    public function testExactMatch(): void
    {
        $this->assertTrue(IpMatcher::matches('203.0.113.7', '203.0.113.7'));
        $this->assertFalse(IpMatcher::matches('203.0.113.7', '203.0.113.8'));
        $this->assertTrue(IpMatcher::matches('2001:db8::1', '2001:0db8:0000::1'));
    }

    public function testCidrMatch(): void
    {
        $this->assertTrue(IpMatcher::matches('10.0.0.0/8', '10.255.255.255'));
        $this->assertFalse(IpMatcher::matches('10.0.0.0/8', '11.0.0.1'));
        $this->assertTrue(IpMatcher::matches('203.0.113.0/26', '203.0.113.63'));
        $this->assertFalse(IpMatcher::matches('203.0.113.0/26', '203.0.113.64'));
        $this->assertTrue(IpMatcher::matches('0.0.0.0/0', '8.8.8.8'));
        $this->assertTrue(IpMatcher::matches('2001:db8::/32', '2001:db8:dead:beef::1'));
        $this->assertFalse(IpMatcher::matches('2001:db8::/32', '2001:db9::1'));
    }

    public function testFamiliesDoNotCross(): void
    {
        $this->assertFalse(IpMatcher::matches('10.0.0.0/8', '2001:db8::1'));
        $this->assertFalse(IpMatcher::matches('2001:db8::/32', '10.0.0.1'));
    }

    public function testIpv4MappedIpv6IsUnwrapped(): void
    {
        $this->assertTrue(IpMatcher::matches('203.0.113.7', '::ffff:203.0.113.7'));
        $this->assertTrue(IpMatcher::matches('10.0.0.0/8', '::ffff:10.1.2.3'));
    }

    public function testMatchesAny(): void
    {
        $patterns = ['10.0.0.0/8', '203.0.113.7'];
        $this->assertTrue(IpMatcher::matchesAny($patterns, '203.0.113.7'));
        $this->assertFalse(IpMatcher::matchesAny($patterns, '198.51.100.1'));
        $this->assertFalse(IpMatcher::matchesAny([], '10.0.0.1'));
    }
}
