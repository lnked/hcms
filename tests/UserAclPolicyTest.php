<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Auth\RolePolicy;
use Cms\Auth\UserAclPolicy;
use PHPUnit\Framework\TestCase;

final class UserAclPolicyTest extends TestCase
{
    public function testOwnerBypassesAcl(): void
    {
        $this->assertTrue(UserAclPolicy::allowsSection(true, RolePolicy::OWNER, [], 'users'));
        $this->assertTrue(UserAclPolicy::allowsResourceAction(true, RolePolicy::OWNER, [], 1, 'delete'));
        $this->assertTrue(UserAclPolicy::allowsResourceTab(true, RolePolicy::OWNER, [], 1, 'schema'));
    }

    public function testDisabledAclAllowsEverything(): void
    {
        $this->assertTrue(UserAclPolicy::allowsSection(false, RolePolicy::EDITOR, [], 'media'));
        $this->assertTrue(UserAclPolicy::allowsResourceAction(false, RolePolicy::EDITOR, [], 9, 'create'));
    }

    public function testSectionAllowlist(): void
    {
        $this->assertTrue(UserAclPolicy::allowsSection(true, RolePolicy::EDITOR, ['resources', 'media'], 'media'));
        $this->assertFalse(UserAclPolicy::allowsSection(true, RolePolicy::EDITOR, ['resources'], 'users'));
        $this->assertTrue(UserAclPolicy::allowsSection(true, RolePolicy::EDITOR, [], 'account'));
    }

    public function testResourceCreateOnly(): void
    {
        $grants = [[
            'resourceId' => 2,
            'canRead' => true,
            'canCreate' => true,
            'canUpdate' => false,
            'canDelete' => false,
            'tabs' => ['data', 'overview'],
        ]];

        $this->assertTrue(UserAclPolicy::allowsResourceAction(true, RolePolicy::EDITOR, $grants, 2, 'create'));
        $this->assertFalse(UserAclPolicy::allowsResourceAction(true, RolePolicy::EDITOR, $grants, 2, 'delete'));
        $this->assertFalse(UserAclPolicy::allowsResourceAction(true, RolePolicy::EDITOR, $grants, 3, 'create'));
        $this->assertTrue(UserAclPolicy::allowsResourceTab(true, RolePolicy::EDITOR, $grants, 2, 'data'));
        $this->assertFalse(UserAclPolicy::allowsResourceTab(true, RolePolicy::EDITOR, $grants, 2, 'schema'));
    }

    public function testSectionForPaths(): void
    {
        $this->assertSame('users', UserAclPolicy::sectionFor('/admin/api/users'));
        $this->assertSame('resources', UserAclPolicy::sectionFor('/admin/api/resources/1/entries'));
        $this->assertSame('dashboard', UserAclPolicy::sectionFor('/admin/api/system/stats'));
        $this->assertNull(UserAclPolicy::sectionFor('/admin/api/auth/me'));
    }

    public function testResourceRequirement(): void
    {
        $create = UserAclPolicy::resourceRequirement('POST', '/admin/api/resources');
        $this->assertNotNull($create);
        $this->assertTrue($create['collectionWrite']);

        $entries = UserAclPolicy::resourceRequirement('POST', '/admin/api/resources/5/entries');
        $this->assertSame(5, $entries['resourceId'] ?? null);
        $this->assertSame('create', $entries['action'] ?? null);
        $this->assertSame('data', $entries['tab'] ?? null);

        $schema = UserAclPolicy::resourceRequirement('PUT', '/admin/api/resources/5/fields');
        $this->assertSame('schema', $schema['tab'] ?? null);
        $this->assertSame('update', $schema['action'] ?? null);
    }
}
