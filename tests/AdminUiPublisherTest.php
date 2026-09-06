<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Paths;
use Cms\System\AdminUiPublisher;
use PHPUnit\Framework\TestCase;

final class AdminUiPublisherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hcms-admin-ui-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public/admin/assets', 0775, true);
        mkdir($this->root . '/public_html/admin/assets', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function testPublishesLegacyPublicAdminIntoPublicHtml(): void
    {
        $js = 'index-new.js';
        file_put_contents(
            $this->root . '/public/admin/index.html',
            '<script src="/admin/assets/' . $js . '"></script>',
        );
        file_put_contents($this->root . '/public/admin/assets/' . $js, 'ok');
        file_put_contents(
            $this->root . '/public_html/admin/index.html',
            '<script src="/admin/assets/index-old.js"></script>',
        );
        file_put_contents($this->root . '/public_html/admin/assets/index-old.js', 'stale');

        $paths = new Paths($this->root, 'public_html');
        $publisher = new AdminUiPublisher($paths);
        $publisher->publishFromReleaseTree();

        $this->assertFileExists($this->root . '/public_html/admin/index.html');
        $this->assertFileExists($this->root . '/public_html/admin/assets/' . $js);
        $this->assertStringContainsString($js, (string) file_get_contents($this->root . '/public_html/admin/index.html'));
        $this->assertDirectoryDoesNotExist($this->root . '/public/admin');
    }

    public function testResolveIndexHealsBrokenPublicHtmlFromLegacy(): void
    {
        $js = 'index-new.js';
        file_put_contents(
            $this->root . '/public/admin/index.html',
            '<script src="/admin/assets/' . $js . '"></script>',
        );
        file_put_contents($this->root . '/public/admin/assets/' . $js, 'ok');
        file_put_contents(
            $this->root . '/public_html/admin/index.html',
            '<script src="/admin/assets/missing.js"></script>',
        );

        $paths = new Paths($this->root, 'public_html');
        $resolved = (new AdminUiPublisher($paths))->resolveIndex();

        $this->assertSame($paths->adminIndex(), $resolved);
        $this->assertFileExists($this->root . '/public_html/admin/assets/' . $js);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeDir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
