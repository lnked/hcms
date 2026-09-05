<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Auth\GrantPolicy;
use PHPUnit\Framework\TestCase;

final class GrantPolicyTest extends TestCase
{
    public function testGlobalGrantAllowsAnyResource(): void
    {
        $grants = [
            ['resource_id' => null, 'can_read' => 1, 'can_create' => 0, 'can_update' => 0, 'can_delete' => 0],
        ];

        $this->assertTrue(GrantPolicy::allows($grants, 5, 'read'));
        $this->assertFalse(GrantPolicy::allows($grants, 5, 'create'));
    }

    public function testResourceScopedGrant(): void
    {
        $grants = [
            ['resourceId' => 2, 'canRead' => false, 'canCreate' => true, 'canUpdate' => false, 'canDelete' => false],
        ];

        $this->assertTrue(GrantPolicy::allows($grants, 2, 'create'));
        $this->assertFalse(GrantPolicy::allows($grants, 3, 'create'));
    }
}
