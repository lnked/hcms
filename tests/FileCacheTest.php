<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\FileCache;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hcms-filecache-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $cache = new FileCache($this->dir);
        $cache->flush();
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testSetGetForget(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('openapi.json', ['ok' => true], 60);
        $this->assertSame(['ok' => true], $cache->get('openapi.json'));

        $cache->forget('openapi.json');
        $this->assertNull($cache->get('openapi.json'));
    }

    public function testTtlExpiry(): void
    {
        $cache = new FileCache($this->dir);
        $path = $this->dir . '/short.cache';
        file_put_contents($path, json_encode([
            'expires_at' => time() - 10,
            'value' => 'stale',
        ]));
        $this->assertNull($cache->get('short'));
        $this->assertFileDoesNotExist($path);
    }

    public function testFlush(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->flush();
        $this->assertNull($cache->get('a'));
        $this->assertNull($cache->get('b'));
    }
}
