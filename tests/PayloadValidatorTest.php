<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Api\PayloadValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PayloadValidatorTest extends TestCase
{
    public function testRequiredFieldMissing(): void
    {
        $validator = new PayloadValidator();
        $fieldMap = [
            'title' => [
                'type' => 'string',
                'spec' => ['writable' => true, 'required' => true, 'nullable' => false, 'config' => []],
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field required: title');
        $validator->validate([], $fieldMap, false);
    }

    public function testCastsIntegerAndSkipsReadonly(): void
    {
        $validator = new PayloadValidator();
        $fieldMap = [
            'views' => [
                'type' => 'integer',
                'spec' => ['writable' => true, 'required' => false, 'nullable' => true, 'config' => []],
            ],
            'secret' => [
                'type' => 'string',
                'spec' => ['writable' => false, 'required' => false, 'nullable' => true, 'config' => []],
            ],
        ];

        $out = $validator->validate(['views' => '42', 'secret' => 'x'], $fieldMap, true);
        self::assertSame(42, $out['views']);
        self::assertArrayNotHasKey('secret', $out);
    }

    public function testSlugFromAssociatedField(): void
    {
        $validator = new PayloadValidator();
        $fieldMap = [
            'title' => [
                'type' => 'string',
                'spec' => ['writable' => true, 'required' => true, 'nullable' => false, 'config' => []],
            ],
            'slug' => [
                'type' => 'slug',
                'spec' => [
                    'writable' => true,
                    'required' => true,
                    'nullable' => false,
                    'config' => ['associatedWith' => 'title', 'maxLength' => 80],
                ],
            ],
        ];

        $out = $validator->validate(['title' => 'Hello World'], $fieldMap, false);
        self::assertSame('hello-world', $out['slug']);
    }
}
