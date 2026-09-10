<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Media\MediaService;
use PHPUnit\Framework\TestCase;

final class MediaPublicUrlTest extends TestCase
{
    public function testSafePublicFilenameFromOriginalName(): void
    {
        self::assertSame('cover.jpg', MediaService::safePublicFilename('cover.jpg', 'image/jpeg'));
        self::assertSame('my-photo.png', MediaService::safePublicFilename('my photo.png', 'image/png'));
        self::assertSame('file.jpg', MediaService::safePublicFilename('обложка.jpg', 'image/jpeg'));
        self::assertSame('file.bin', MediaService::safePublicFilename(null, null));
        self::assertSame('file.webp', MediaService::safePublicFilename('', 'image/webp'));
        self::assertSame('a..b.txt', MediaService::safePublicFilename('a..b.txt', 'text/plain'));
    }

    public function testPublicPathAndFullUrl(): void
    {
        $path = MediaService::publicPath(50, 'cover.jpg', 'image/jpeg');
        self::assertSame('/media/50/cover.jpg', $path);

        $full = MediaService::publicFullUrl('https://api.2js.ru/', 50, 'cover.jpg', 'image/jpeg');
        self::assertSame('https://api.2js.ru/media/50/cover.jpg', $full);

        $urls = MediaService::publicUrls('http://localhost:8080', 7, 'Hero Banner!.png', 'image/png');
        self::assertSame('/media/7/Hero-Banner.png', $urls['url']);
        self::assertSame('http://localhost:8080/media/7/Hero-Banner.png', $urls['fullUrl']);
    }

    public function testPublicPathFallbackWithoutName(): void
    {
        self::assertSame('/media/3/file.bin', MediaService::publicPath(3));
        self::assertSame('/media/3/file.jpg', MediaService::publicPath(3, null, 'image/jpeg'));
    }
}
