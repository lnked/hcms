<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Auth\TokenPolicy;
use Cms\Core\Exception\ValidationFailedException;
use PHPUnit\Framework\TestCase;

final class TokenPolicyTest extends TestCase
{
    public function testUnrestrictedAllowsEverything(): void
    {
        $policy = TokenPolicy::unrestricted();
        $this->assertFalse($policy->isRestricted());
        $this->assertTrue($policy->allowsOrigin('https://evil.example'));
        $this->assertTrue($policy->allowsOrigin(null));
        $this->assertTrue($policy->allowsIp('203.0.113.7'));
    }

    public function testOriginAllowlistBlocksOtherSites(): void
    {
        $policy = new TokenPolicy(['https://app.example.com', '*.staging.example.com']);
        $this->assertTrue($policy->isRestricted());
        $this->assertTrue($policy->allowsOrigin('https://app.example.com'));
        $this->assertTrue($policy->allowsOrigin('https://pr-12.staging.example.com'));
        $this->assertFalse($policy->allowsOrigin('https://evil.example'));
        $this->assertFalse($policy->allowsOrigin('http://app.example.com'));
    }

    public function testMissingOriginPassesUnlessRequired(): void
    {
        $lenient = new TokenPolicy(['https://app.example.com']);
        $this->assertTrue($lenient->allowsOrigin(null));
        $this->assertTrue($lenient->allowsOrigin(''));

        $strict = new TokenPolicy(['https://app.example.com'], true);
        $this->assertFalse($strict->allowsOrigin(null));
        $this->assertFalse($strict->allowsOrigin('   '));
        $this->assertTrue($strict->allowsOrigin('https://app.example.com'));
    }

    public function testRequireOriginWithoutListAcceptsAnyBrowser(): void
    {
        $policy = new TokenPolicy([], true);
        $this->assertTrue($policy->isRestricted());
        $this->assertTrue($policy->allowsOrigin('https://anything.example'));
        $this->assertFalse($policy->allowsOrigin(null));
    }

    public function testIpAllowlistSupportsCidr(): void
    {
        $policy = new TokenPolicy([], false, ['10.0.0.0/8', '203.0.113.7']);
        $this->assertTrue($policy->isRestricted());
        $this->assertTrue($policy->allowsIp('10.4.5.6'));
        $this->assertTrue($policy->allowsIp('203.0.113.7'));
        $this->assertFalse($policy->allowsIp('203.0.113.8'));
        $this->assertFalse($policy->allowsIp('11.0.0.1'));
        $this->assertFalse($policy->allowsIp('not-an-ip'));
    }

    public function testFromInputNormalizesAndDeduplicates(): void
    {
        $policy = TokenPolicy::fromInput(
            ['  https://App.Example.com/path  ', 'app.example.com', 'https://app.example.com', ''],
            1,
            ['  10.0.0.0/8 ', '10.0.0.0/8'],
        );

        $this->assertSame(['https://app.example.com', 'app.example.com'], $policy->allowedOrigins);
        $this->assertTrue($policy->requireOrigin);
        $this->assertSame(['10.0.0.0/8'], $policy->allowedIps);
    }

    public function testFromInputRejectsInvalidEntries(): void
    {
        $this->expectException(ValidationFailedException::class);
        TokenPolicy::fromInput(['not a domain'], false, []);
    }

    public function testFromInputRejectsInvalidCidr(): void
    {
        $this->expectException(ValidationFailedException::class);
        TokenPolicy::fromInput([], false, ['10.0.0.0/64']);
    }

    public function testToArrayRoundTrip(): void
    {
        $policy = new TokenPolicy(['app.example.com'], true, ['10.0.0.1']);
        $this->assertSame([
            'allowedOrigins' => ['app.example.com'],
            'requireOrigin' => true,
            'allowedIps' => ['10.0.0.1'],
        ], $policy->toArray());
    }
}
