<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Fields\Types\BlocksType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlocksTypeTest extends TestCase
{
    public function testFieldsForComponentLegacyArray(): void
    {
        $fields = BlocksType::fieldsForComponent([
            'hero' => [
                ['name' => 'title', 'type' => 'string'],
            ],
        ], 'hero');
        self::assertNotNull($fields);
        self::assertSame('title', $fields[0]['name'] ?? null);
    }

    public function testFieldsForComponentObjectForm(): void
    {
        $fields = BlocksType::fieldsForComponent([
            'hero' => [
                'label' => 'Hero',
                'fields' => [
                    ['name' => 'title', 'type' => 'string'],
                ],
            ],
        ], 'hero');
        self::assertNotNull($fields);
        self::assertCount(1, $fields);
    }

    public function testValidateConfigRequiresEnumOptions(): void
    {
        $type = new BlocksType();
        $this->expectException(InvalidArgumentException::class);
        $type->validateConfig([
            'components' => [
                'cta' => [
                    'fields' => [
                        ['name' => 'kind', 'type' => 'enum', 'config' => []],
                    ],
                ],
            ],
        ]);
    }

    public function testValidateConfigAcceptsObjectComponents(): void
    {
        $type = new BlocksType();
        $type->validateConfig([
            'components' => [
                'hero' => [
                    'label' => 'Hero',
                    'fields' => [
                        ['name' => 'title', 'type' => 'string', 'config' => ['maxLength' => 80]],
                    ],
                ],
            ],
        ]);
        self::assertTrue(true);
    }
}
