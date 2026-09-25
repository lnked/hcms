<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Extension\Color\ColorFieldType;
use Cms\Fields\FieldTypeDiscovery;
use Cms\Fields\FieldTypeRegistry;
use Cms\Fields\SqlTypeMapper;
use Cms\Api\PayloadValidator;
use Cms\Core\Exception\ValidationFailedException;
use PHPUnit\Framework\TestCase;

final class FieldTypeDiscoveryTest extends TestCase
{
    public function testExtensionsColorIsRegisteredFromProjectRoot(): void
    {
        $registry = FieldTypeRegistry::createWithDiscovery(dirname(__DIR__));
        $this->assertTrue($registry->has('color'));
        $this->assertInstanceOf(ColorFieldType::class, $registry->get('color'));
        $this->assertSame('VARCHAR(7)', $registry->get('color')->sqlType([]));
    }

    public function testDescriptorsIncludeColor(): void
    {
        $registry = FieldTypeRegistry::createWithDiscovery(dirname(__DIR__));
        $names = array_column($registry->descriptors(), 'name');
        $this->assertContains('string', $names);
        $this->assertContains('color', $names);
    }

    public function testColorSqlAndCast(): void
    {
        $registry = new FieldTypeRegistry();
        require_once dirname(__DIR__) . '/extensions/color/ColorFieldType.php';
        $registry->register(new ColorFieldType());
        $mapper = new SqlTypeMapper($registry);
        $col = $mapper->columnFor([
            'name' => 'accent',
            'type' => 'color',
            'nullable' => false,
            'unique' => false,
            'indexed' => false,
            'config' => [],
        ]);
        $this->assertNotNull($col);
        $this->assertSame('VARCHAR(7)', $col->sqlType);

        $validator = new PayloadValidator($registry);
        $out = $validator->validate(
            ['accent' => '#a1b2c3'],
            [
                'accent' => [
                    'type' => 'color',
                    'spec' => ['required' => true, 'nullable' => false, 'config' => []],
                ],
            ],
            false,
        );
        $this->assertSame('#A1B2C3', $out['accent']);

        $this->expectException(ValidationFailedException::class);
        $validator->validate(
            ['accent' => 'red'],
            [
                'accent' => [
                    'type' => 'color',
                    'spec' => ['required' => true, 'nullable' => false, 'config' => []],
                ],
            ],
            false,
        );
    }

    public function testDiscoveryNoopOnMissingExtensions(): void
    {
        $registry = new FieldTypeRegistry();
        $before = $registry->names();
        FieldTypeDiscovery::registerInto($registry, sys_get_temp_dir() . '/hcms-no-ext-' . uniqid());
        $this->assertSame($before, $registry->names());
    }
}
