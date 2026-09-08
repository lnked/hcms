<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Resources\ResourceApiService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ResourceApiServiceTest extends TestCase
{
    public function testMethodNormalization(): void
    {
        $this->assertSame(['GET', 'POST', 'PATCH', 'DELETE'], ResourceApiService::normalizeMethods([
            'get',
            ' post ',
            'PATCH',
            'delete',
        ]));
        $this->assertSame(['PATCH'], ResourceApiService::normalizeMethods(['PUT']));
        $this->assertSame(['PATCH'], ResourceApiService::normalizeMethods(['PUT', 'PATCH']));
        $this->assertSame(['GET'], ResourceApiService::normalizeMethods(['HEAD', 'OPTIONS', 42]));
        $this->assertSame(['GET'], ResourceApiService::normalizeMethods([]));
        $this->assertSame(['GET'], ResourceApiService::normalizeMethods('nonsense'));
    }

    public function testWriteMethodDetection(): void
    {
        $this->assertFalse(ResourceApiService::hasWriteMethod(['GET']));
        $this->assertTrue(ResourceApiService::hasWriteMethod(['GET', 'DELETE']));
    }

    public function testPublicSettingsAreTriState(): void
    {
        $defaults = ResourceApiService::normalizeSettings([]);
        $this->assertSame(
            ['read' => null, 'create' => null, 'update' => null, 'delete' => null],
            $defaults['public'],
        );

        $explicit = ResourceApiService::normalizeSettings([
            'public' => ['read' => true, 'create' => false, 'update' => null],
        ]);
        $this->assertTrue($explicit['public']['read']);
        $this->assertFalse($explicit['public']['create']);
        $this->assertNull($explicit['public']['update']);
        $this->assertNull($explicit['public']['delete']);
    }

    public function testWritableFieldsSkipReadonlyAndOneToMany(): void
    {
        $fieldMap = self::fieldMap();

        $this->assertSame(['title', 'slug', 'views'], ResourceApiService::writableFieldNames($fieldMap));
        $this->assertSame(['title'], ResourceApiService::requiredWritableFieldNames($fieldMap));
    }

    public function testReadOnlyApiIgnoresWriteInvariants(): void
    {
        ResourceApiService::assertWriteConfig(
            ['GET'],
            ['title'],
            [['as' => 'author', 'relatedSlug' => 'authors']],
            self::fieldMap(),
        );

        $this->addToAssertionCount(1);
    }

    public function testWritesRejectJoins(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Writes are not supported for APIs with joins');

        ResourceApiService::assertWriteConfig(
            ['GET', 'POST'],
            null,
            [['as' => 'author', 'relatedSlug' => 'authors']],
            self::fieldMap(),
        );
    }

    public function testCreateRequiresRequiredFieldsInProjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('POST requires these fields in the projection: title');

        ResourceApiService::assertWriteConfig(['POST'], ['views'], [], self::fieldMap());
    }

    public function testCreateAcceptsFullProjectionAndDerivedSlug(): void
    {
        ResourceApiService::assertWriteConfig(['POST'], ['title', 'views'], [], self::fieldMap());
        ResourceApiService::assertWriteConfig(['POST'], null, [], self::fieldMap());
        ResourceApiService::assertWriteConfig(['PATCH', 'DELETE'], ['views'], [], self::fieldMap());

        $this->addToAssertionCount(3);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function fieldMap(): array
    {
        return [
            'title' => ['type' => 'string', 'spec' => ['required' => true]],
            // Required but derived from `title`, so a POST body does not have to carry it.
            'slug' => [
                'type' => 'slug',
                'spec' => ['required' => true, 'config' => ['associatedWith' => 'title']],
            ],
            'views' => ['type' => 'integer', 'spec' => []],
            'computed' => ['type' => 'string', 'spec' => ['writable' => false]],
            'comments' => [
                'type' => 'relation',
                'spec' => ['config' => ['cardinality' => 'oneToMany']],
            ],
        ];
    }
}
