<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Auth\Password;
use PHPUnit\Framework\TestCase;

final class PasswordTest extends TestCase
{
    public function testHashAndVerify(): void
    {
        $hash = Password::hash('correct-horse1');
        $this->assertTrue(Password::verify('correct-horse1', $hash));
        $this->assertFalse(Password::verify('wrong', $hash));
    }

    public function testPolicyRequiresLetterAndDigit(): void
    {
        $this->assertTrue(Password::meetsPolicy('password1'));
        $this->assertFalse(Password::meetsPolicy('password'));
        $this->assertFalse(Password::meetsPolicy('12345678'));
        $this->assertFalse(Password::meetsPolicy('short1'));
    }
}
