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

    public function testSyncsNewerPublicAdminIntoPublicHtmlWithoutDeletingSource(): void
    {
        $js = 'index-new.js';
        file_put_contents(
            $this->root . '/public/admin/index.html',
            '<script src="/admin/assets/' . $js . '"></script>',
        );
        file_put_contents($this->root . '/public/admin/assets/' . $js, 'ok');
        touch($this->root . '/public/admin/index.html', time());

        file_put_contents(
            $this->root . '/public_html/admin/index.html',
            '<script src="/admin/assets/index-old.js"></script>',
        );
        file_put_contents($this->root . '/public_html/admin/assets/index-old.js', 'stale');
        touch($this->root . '/public_html/admin/index.html', time() - 100);

        $paths = new Paths($this->root, 'public_html');
        (new AdminUiPublisher($paths))->publishFromReleaseTree();

        $this->assertFileExists($this->root . '/public_html/admin/index.html');
        $this->assertFileExists($this->root . '/public_html/admin/assets/' . $js);
        $this->assertStringContainsString($js, (string) file_get_contents($this->root . '/public_html/admin/index.html'));
        // Keep public/admin — .htaccess may still rewrite to public/
        $this->assertDirectoryExists($this->root . '/public/admin');
        $this->assertFileExists($this->root . '/public/admin/assets/' . $js);
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

    public function testSyncsWhenPublicHtmlIsCompleteButStaleVersusPublic(): void
    {
        // Realistic broken state: public_html has full OLD assets, public has NEW.
        // spa() used to keep serving old because assets "exist".
        $oldJs = 'index-old.js';
        $newJs = 'index-new.js';

        file_put_contents(
            $this->root . '/public_html/admin/index.html',
            '<script src="/admin/assets/' . $oldJs . '"></script>',
        );
        file_put_contents($this->root . '/public_html/admin/assets/' . $oldJs, 'old');
        touch($this->root . '/public_html/admin/index.html', time() - 200);

        file_put_contents(
            $this->root . '/public/admin/index.html',
            '<script src="/admin/assets/' . $newJs . '"></script>',
        );
        file_put_contents($this->root . '/public/admin/assets/' . $newJs, 'new');
        touch($this->root . '/public/admin/index.html', time());

        $paths = new Paths($this->root, 'public_html');
        $html = (string) file_get_contents((new AdminUiPublisher($paths))->resolveIndex());

        $this->assertStringContainsString($newJs, $html);
        $this->assertFileExists($this->root . '/public_html/admin/assets/' . $newJs);
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
