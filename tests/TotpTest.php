<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Security\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    public function testGenerateAndVerify(): void
    {
        $secret = Totp::generateSecret();
        $code = Totp::codeAt($secret, intdiv(time(), 30));
        $this->assertTrue(Totp::verify($secret, $code));
        $this->assertFalse(Totp::verify($secret, '000000'));
    }

    public function testProvisioningUri(): void
    {
        $uri = Totp::provisioningUri('JBSWY3DPEHPK3PXP', 'ada@example.com', 'HCMS');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
    }
}
