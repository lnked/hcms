<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Fields\FieldTypeRegistry;
use PHPUnit\Framework\TestCase;

final class FieldTypeRegistryTest extends TestCase
{
    public function testRegistersMvpTypes(): void
    {
        $registry = new FieldTypeRegistry();
        $this->assertContains('string', $registry->names());
        $this->assertContains('enum', $registry->names());
        $this->assertTrue($registry->has('email'));
        $this->assertSame('integer', $registry->get('integer')->name());
    }

    public function testEnumRequiresOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FieldTypeRegistry())->get('enum')->validateConfig(['options' => []]);
    }
}
