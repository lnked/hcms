<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Http\AdminUiSections;
use PHPUnit\Framework\TestCase;

final class AdminUiSectionsTest extends TestCase
{
    public function testDefaultsAllEnabled(): void
    {
        $sections = AdminUiSections::defaults();
        self::assertTrue($sections->isEnabled('webhooks'));
        self::assertSame([], $sections->hidden());
    }

    public function testFromMapHidesAndLocks(): void
    {
        $sections = AdminUiSections::fromMap([
            'webhooks' => false,
            'system' => false,
            'account' => false,
        ]);
        self::assertFalse($sections->isEnabled('webhooks'));
        self::assertTrue($sections->isEnabled('system'));
        self::assertTrue($sections->isEnabled('account'));
        self::assertSame(['webhooks'], $sections->hidden());
    }

    public function testResolveHomeFallsBackWhenHidden(): void
    {
        $sections = AdminUiSections::fromMap(['dashboard' => false]);
        self::assertSame('resources', $sections->resolveHome('dashboard'));
        self::assertSame('resources', $sections->toPublicArray('dashboard')['homeSection']);
    }

    public function testValidateHomeSection(): void
    {
        $ui = AdminUiSections::fromMap(['uptime' => false]);
        $ok = AdminUiSections::validateHomeSection('resources', $ui);
        self::assertTrue($ok['ok']);
        $bad = AdminUiSections::validateHomeSection('uptime', $ui);
        self::assertFalse($bad['ok']);
    }

    public function testValidatePayload(): void
    {
        $ok = AdminUiSections::validatePayload(['uptime' => false, 'system' => false]);
        self::assertTrue($ok['ok']);
        self::assertFalse($ok['value']['uptime']);
        self::assertTrue($ok['value']['system']);

        $bad = AdminUiSections::validatePayload(['nope' => true]);
        self::assertFalse($bad['ok']);
    }
}
