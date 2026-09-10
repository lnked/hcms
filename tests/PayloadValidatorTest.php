<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Api\PayloadValidator;
use Cms\Core\Exception\ValidationFailedException;
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

        try {
            $validator->validate([], $fieldMap, false);
            self::fail('Expected ValidationFailedException');
        } catch (ValidationFailedException $e) {
            self::assertSame('Validation failed', $e->getMessage());
            self::assertSame(['title' => ['Field required: title']], $e->fields());
        }
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
