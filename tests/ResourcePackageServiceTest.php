<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Resources\ResourcePackageService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ResourcePackageServiceTest extends TestCase
{
    public function testValidatePackageAcceptsMinimalSchema(): void
    {
        ResourcePackageService::validatePackage([
            'formatVersion' => 1,
            'kind' => 'cms.resource.package',
            'contentType' => [
                'name' => 'posts',
                'slug' => 'posts',
                'label' => 'Posts',
                'description' => null,
            ],
            'resource' => [
                'slug' => 'posts',
                'endpoint' => '/api/posts',
                'apiVersion' => 'v1',
                'status' => 'draft',
                'settings' => [],
            ],
            'fields' => [
                ['name' => 'title', 'type' => 'string', 'sortOrder' => 0, 'label' => 'Title'],
            ],
            'apis' => [],
        ]);
        $this->assertTrue(true);
    }

    public function testValidatePackageRejectsWrongKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ResourcePackageService::validatePackage([
            'formatVersion' => 1,
            'kind' => 'other',
            'contentType' => ['slug' => 'posts', 'label' => 'Posts'],
            'resource' => [],
            'fields' => [],
        ]);
    }

    public function testCollectMediaIdsFromEntries(): void
    {
        $ids = ResourcePackageService::collectMediaIds(
            [
                ['title' => 'A', 'cover' => 10, 'doc' => null],
                ['title' => 'B', 'cover' => 10, 'doc' => 22],
                ['title' => 'C', 'cover' => '', 'doc' => 22],
            ],
            ['cover', 'doc'],
        );

        $this->assertSame([10, 22], $ids);
    }

    public function testRemapMediaInEntries(): void
    {
        $rows = ResourcePackageService::remapMediaInEntries(
            [
                ['title' => 'A', 'cover' => 10],
                ['title' => 'B', 'cover' => 99],
            ],
            ['cover'],
            [10 => 501],
        );

        $this->assertSame(501, $rows[0]['cover']);
        $this->assertSame(99, $rows[1]['cover']);
    }

    public function testPrepareEntryPayloadStripsSystemKeys(): void
    {
        $payload = ResourcePackageService::prepareEntryPayload([
            'id' => 1,
            'createdAt' => '2020-01-01',
            'updated_at' => '2020-01-02',
            'title' => 'Hello',
            'cover' => 3,
        ]);

        $this->assertSame(['title' => 'Hello', 'cover' => 3], $payload);
    }

    public function testNextAvailableSlugAutoSuffixOnConflict(): void
    {
        $taken = ['posts' => true, 'posts_2' => true];
        $slug = ResourcePackageService::nextAvailableSlug(
            'posts',
            static fn (string $candidate): bool => isset($taken[$candidate]),
        );
        $this->assertSame('posts_3', $slug);

        $free = ResourcePackageService::nextAvailableSlug(
            'pages',
            static fn (string $candidate): bool => false,
        );
        $this->assertSame('pages', $free);
    }

    public function testSchemaOnlyPackageRoundTripShape(): void
    {
        $package = [
            'formatVersion' => ResourcePackageService::FORMAT_VERSION,
            'kind' => ResourcePackageService::KIND,
            'exportedAt' => '2026-01-01T00:00:00+00:00',
            'contentType' => [
                'name' => 'articles',
                'slug' => 'articles',
                'label' => 'Articles',
                'description' => 'Demo',
            ],
            'resource' => [
                'slug' => 'articles',
                'endpoint' => '/api/v1/articles',
                'apiVersion' => 'v1',
                'status' => 'published',
                'settings' => [
                    'apiEnabled' => true,
                    'public' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false],
                    'pagination' => true,
                    'search' => true,
                    'sorting' => true,
                    'filtering' => true,
                    'deleteStrategy' => 'hard',
                    'softDelete' => false,
                ],
            ],
            'fields' => [
                [
                    'name' => 'title',
                    'type' => 'string',
                    'sortOrder' => 0,
                    'label' => 'Title',
                    'required' => true,
                    'config' => ['maxLength' => 200],
                ],
                [
                    'name' => 'cover',
                    'type' => 'image',
                    'sortOrder' => 1,
                    'label' => 'Cover',
                    'config' => [],
                ],
            ],
            'apis' => [
                [
                    'slug' => 'list-lite',
                    'label' => 'Lite list',
                    'enabled' => true,
                    'methods' => ['GET'],
                    'fields' => ['title'],
                    'joins' => [],
                    'settings' => [],
                ],
            ],
            'entries' => [
                ['id' => 1, 'title' => 'One', 'cover' => 7],
            ],
            'media' => [
                [
                    'id' => 7,
                    'originalName' => 'cover.png',
                    'mime' => 'image/png',
                    'width' => 10,
                    'height' => 10,
                    'contentBase64' => base64_encode('fake-png'),
                ],
            ],
        ];

        ResourcePackageService::validatePackage($package);

        $encoded = json_encode($package, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        ResourcePackageService::validatePackage($decoded);

        $mediaIds = ResourcePackageService::collectMediaIds($decoded['entries'], ['cover']);
        $this->assertSame([7], $mediaIds);

        $remapped = ResourcePackageService::remapMediaInEntries(
            $decoded['entries'],
            ['cover'],
            [7 => 1001],
        );
        $payload = ResourcePackageService::prepareEntryPayload($remapped[0]);
        $this->assertSame(['title' => 'One', 'cover' => 1001], $payload);
    }
}
