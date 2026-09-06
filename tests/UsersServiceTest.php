<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Auth\UsersService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UsersServiceTest extends TestCase
{
    public function testValidateCreateRequiresEmailAndPassword(): void
    {
        $ok = UsersService::validateCreate([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'password1',
        ]);
        $this->assertSame('ada@example.com', $ok['email']);
        $this->assertSame('active', $ok['status']);

        $this->expectException(InvalidArgumentException::class);
        UsersService::validateCreate([
            'name' => 'Ada',
            'email' => 'not-an-email',
            'password' => 'password1',
        ]);
    }

    public function testValidateUpdateStatusAndSelfFields(): void
    {
        $partial = UsersService::validateUpdate(['status' => 'disabled']);
        $this->assertSame(['status' => 'disabled'], $partial);

        $this->expectException(InvalidArgumentException::class);
        UsersService::validateUpdate(['status' => 'banned']);
    }

    public function testValidateCreateRejectsShortPassword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UsersService::validateCreate([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'short',
        ]);
    }
}
