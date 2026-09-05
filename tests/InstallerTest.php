<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Paths;
use Cms\Install\Installer;
use Cms\Install\ReleaseDownloader;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    public function testStatusWhenNotInstalled(): void
    {
        $installer = new Installer(new Paths(dirname(__DIR__)));
        $status = $installer->status();

        $this->assertFalse($status['installed']);
        $this->assertTrue($status['srcReady']);
        $this->assertSame('0.6.0', $status['version']);
        $this->assertArrayHasKey('checks', $status['requirements']);
    }

    public function testLockDetection(): void
    {
        $root = sys_get_temp_dir() . '/hcms-install-' . uniqid();
        mkdir($root . '/storage', 0777, true);
        $paths = new Paths($root);
        $installer = new Installer($paths);
        $this->assertFalse($installer->isInstalled());
        file_put_contents($paths->installedLock(), '{}');
        $this->assertTrue($installer->isInstalled());
    }

    public function testDownloadSkippedWhenSrcPresent(): void
    {
        $downloader = new ReleaseDownloader(new Paths(dirname(__DIR__)));
        $result = $downloader->download();

        $this->assertTrue($result['skipped']);
        $this->assertSame('src_present', $result['reason']);
    }
}
