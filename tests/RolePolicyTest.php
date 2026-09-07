<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Auth\AuthContext;
use Cms\Auth\RolePolicy;
use PHPUnit\Framework\TestCase;

final class RolePolicyTest extends TestCase
{
    public function testNormalizeDefaultsToAdmin(): void
    {
        self::assertSame('admin', RolePolicy::normalize(null));
        self::assertSame('admin', RolePolicy::normalize('nope'));
        self::assertSame('viewer', RolePolicy::normalize('VIEWER'));
    }

    public function testViewerCannotWrite(): void
    {
        self::assertTrue(RolePolicy::can('viewer', 'read'));
        self::assertFalse(RolePolicy::can('viewer', 'entries.write'));
        self::assertFalse(RolePolicy::can('viewer', 'schema.write'));
        self::assertFalse(RolePolicy::can('viewer', 'system.write'));
    }

    public function testEditorCanEntriesNotSchema(): void
    {
        self::assertTrue(RolePolicy::can('editor', 'entries.write'));
        self::assertFalse(RolePolicy::can('editor', 'schema.write'));
        self::assertFalse(RolePolicy::can('editor', 'users.write'));
    }

    public function testOnlyOwnerCanUpdate(): void
    {
        self::assertFalse(RolePolicy::can('admin', 'system.write'));
        self::assertTrue(RolePolicy::can('owner', 'system.write'));
    }

    public function testCapabilityMapping(): void
    {
        self::assertNull(RolePolicy::capabilityFor('GET', '/admin/api/resources'));
        self::assertSame('entries.write', RolePolicy::capabilityFor('POST', '/admin/api/resources/1/entries'));
        self::assertSame('schema.write', RolePolicy::capabilityFor('POST', '/admin/api/resources'));
        self::assertSame('system.write', RolePolicy::capabilityFor('POST', '/admin/api/system/update/run'));
        self::assertSame('users.write', RolePolicy::capabilityFor('POST', '/admin/api/users'));
    }

    public function testEnforceViewerDenied(): void
    {
        $auth = new AuthContext(
            ['id' => 1, 'type' => 'admin'],
            ['id' => 2, 'role' => 'viewer'],
        );
        $denied = RolePolicy::enforce($auth, 'POST', '/admin/api/resources/1/entries');
        self::assertNotNull($denied);
        self::assertSame(403, $denied->status);
    }
}
