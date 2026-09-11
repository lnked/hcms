<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Paths;
use Cms\Database\Connection;
use Cms\Media\MediaService;
use PDO;
use PHPUnit\Framework\TestCase;

final class MediaPublicCacheTest extends TestCase
{
    private string $root;

    private MediaService $media;

    private Connection $db;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hcms-media-cache-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/uploads', 0777, true);
        mkdir($this->root . '/public', 0777, true);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE cms_media (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER NULL,
                source_id INTEGER NULL,
                variant_key TEXT NULL,
                disk_path TEXT NOT NULL,
                original_name TEXT NOT NULL,
                mime TEXT NOT NULL,
                size INTEGER NOT NULL,
                width INTEGER NULL,
                height INTEGER NULL,
                created_at TEXT NOT NULL,
                uploaded_by INTEGER NULL
            )',
        );
        $this->db = new Connection($pdo);
        $this->media = new MediaService(
            $this->db,
            new Paths($this->root, 'public'),
            appUrl: 'http://localhost',
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testWarmCreatesPublicCopyAndInvalidateRemovesIt(): void
    {
        $relative = '2026/09/' . bin2hex(random_bytes(8)) . '.png';
        $absolute = $this->root . '/storage/uploads/' . $relative;
        mkdir(\dirname($absolute), 0777, true);
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertNotFalse($bytes);
        file_put_contents($absolute, $bytes);

        $this->db->execute(
            'INSERT INTO cms_media (parent_id, source_id, variant_key, disk_path, original_name, mime, size, width, height, created_at, uploaded_by)
             VALUES (NULL, NULL, NULL, :disk_path, :original_name, :mime, :size, 1, 1, :created_at, NULL)',
            [
                'disk_path' => $relative,
                'original_name' => 'cover.png',
                'mime' => 'image/png',
                'size' => \strlen($bytes),
                'created_at' => date('c'),
            ],
        );
        $id = (int) $this->db->lastInsertId();

        $this->media->warmPublicCache($id);
        $cached = $this->root . '/public/media/' . $id . '/cover.png';
        self::assertFileExists($cached);
        self::assertSame($bytes, (string) file_get_contents($cached));
        self::assertFileExists($this->root . '/public/media/.htaccess');

        $this->media->warmPublicCache($id);
        self::assertFileExists($cached);

        $this->media->invalidatePublicCache($id);
        self::assertFileDoesNotExist($cached);
        self::assertDirectoryDoesNotExist($this->root . '/public/media/' . $id);
    }

    public function testSvgIsNotWarmed(): void
    {
        $relative = '2026/09/' . bin2hex(random_bytes(8)) . '.svg';
        $absolute = $this->root . '/storage/uploads/' . $relative;
        mkdir(\dirname($absolute), 0777, true);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';
        file_put_contents($absolute, $svg);

        $this->db->execute(
            'INSERT INTO cms_media (parent_id, source_id, variant_key, disk_path, original_name, mime, size, width, height, created_at, uploaded_by)
             VALUES (NULL, NULL, NULL, :disk_path, :original_name, :mime, :size, NULL, NULL, :created_at, NULL)',
            [
                'disk_path' => $relative,
                'original_name' => 'icon.svg',
                'mime' => 'image/svg+xml',
                'size' => \strlen($svg),
                'created_at' => date('c'),
            ],
        );
        $id = (int) $this->db->lastInsertId();

        $this->media->warmPublicCache($id);
        self::assertDirectoryDoesNotExist($this->root . '/public/media/' . $id);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
