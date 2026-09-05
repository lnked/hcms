<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Auth\Password;
use PHPUnit\Framework\TestCase;

final class PasswordTest extends TestCase
{
    public function testHashAndVerify(): void
    {
        $hash = Password::hash('correct-horse');
        $this->assertTrue(Password::verify('correct-horse', $hash));
        $this->assertFalse(Password::verify('wrong', $hash));
    }
}
