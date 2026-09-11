<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\AdminBase;
use PHPUnit\Framework\TestCase;

final class AdminBaseTest extends TestCase
{
    public function testNormalizeDefaultAndAliases(): void
    {
        $this->assertSame('/admin', AdminBase::fromRaw('admin')->ui());
        $this->assertSame('/admin', AdminBase::fromRaw('/admin/')->ui());
        $this->assertSame('/panel', AdminBase::fromRaw('Panel')->ui());
        $this->assertSame('', AdminBase::fromRaw('')->ui());
        $this->assertSame('', AdminBase::fromRaw('/')->ui());
    }

    public function testApiPrefixVariantA(): void
    {
        $this->assertSame('/admin/api', AdminBase::default()->apiPrefix());
        $this->assertSame('/panel/api', AdminBase::fromRaw('panel')->apiPrefix());
        $this->assertSame('/admin/api', AdminBase::fromRaw('')->apiPrefix());
    }

    public function testCanonicalizeMapsCustomApiOntoInternal(): void
    {
        $base = AdminBase::fromRaw('panel');
        $this->assertSame('/admin/api/health', $base->canonicalize('/panel/api/health'));
        $this->assertSame('/admin/api', $base->canonicalize('/panel/api'));
        $this->assertSame('/admin/api/health', $base->canonicalize('/admin/api/health'));
    }

    public function testSpaPaths(): void
    {
        $panel = AdminBase::fromRaw('panel');
        $this->assertTrue($panel->isSpaPath('/panel'));
        $this->assertTrue($panel->isSpaPath('/panel/login'));
        $this->assertFalse($panel->isSpaPath('/panel/api/health'));
        $this->assertFalse($panel->isSpaPath('/admin'));

        $root = AdminBase::fromRaw('');
        $this->assertTrue($root->isSpaPath('/'));
        $this->assertTrue($root->isSpaPath('/login'));
        $this->assertFalse($root->isSpaPath('/api/posts'));
        $this->assertFalse($root->isSpaPath('/admin/api/health'));
        $this->assertFalse($root->isSpaPath('/admin/assets/app.js'));
    }

    public function testLegacyRedirectFromAdminUi(): void
    {
        $panel = AdminBase::fromRaw('panel');
        $this->assertSame('/panel', $panel->legacyRedirect('/admin'));
        $this->assertSame('/panel/login', $panel->legacyRedirect('/admin/login'));
        $this->assertNull($panel->legacyRedirect('/admin/api/health'));
        $this->assertNull($panel->legacyRedirect('/admin/assets/x.js'));
        $this->assertNull($panel->legacyRedirect('/admin/favicon.svg'));

        $root = AdminBase::fromRaw('');
        $this->assertSame('/', $root->legacyRedirect('/admin'));
        $this->assertSame('/login', $root->legacyRedirect('/admin/login'));
    }

    public function testRejectsReservedSegments(): void
    {
        $parsed = AdminBase::tryNormalize('api');
        $this->assertFalse($parsed['ok']);
        $fallback = AdminBase::fromRaw('api');
        $this->assertSame('/admin', $fallback->ui());
    }
}
