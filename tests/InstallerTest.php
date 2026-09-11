<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Paths;
use Cms\Core\Version;
use Cms\Install\Installer;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $this->removeDir($this->tmp);
        }
    }

    public function testStatusWhenNotInstalled(): void
    {
        $installer = new Installer(new Paths(\dirname(__DIR__)));
        $status = $installer->status();

        $this->assertFalse($status['installed']);
        $this->assertTrue($status['srcReady']);
        $this->assertSame(Version::current(), $status['version']);
        $this->assertArrayHasKey('checks', $status['requirements']);
        $this->assertArrayHasKey('suggestedPublicDir', $status);
        $this->assertArrayHasKey('insideWebRoot', $status);
    }

    public function testLockDetection(): void
    {
        $root = sys_get_temp_dir() . '/hcms-install-' . uniqid('', true);
        mkdir($root . '/storage', 0777, true);
        $this->tmp = $root;
        $paths = new Paths($root);
        $installer = new Installer($paths);
        $this->assertFalse($installer->isInstalled());
        file_put_contents($paths->installedLock(), '{}');
        $this->assertTrue($installer->isInstalled());
    }

    public function testCompleteRejectsInvalidAdministratorWithFieldErrors(): void
    {
        $root = sys_get_temp_dir() . '/hcms-install-val-' . uniqid('', true);
        mkdir($root . '/storage', 0777, true);
        $this->tmp = $root;
        $installer = new Installer(new Paths($root));

        try {
            $installer->complete([
                'database' => ['host' => '127.0.0.1', 'name' => 'x', 'user' => 'u'],
                'application' => ['name' => 'HCMS', 'url' => 'http://localhost'],
                'administrator' => [
                    'name' => '',
                    'email' => 'bad',
                    'password' => 'short',
                    'passwordConfirm' => 'other',
                ],
            ]);
            $this->fail('Expected ValidationException');
        } catch (\Cms\Install\ValidationException $e) {
            $fields = $e->fields();
            $this->assertSame(['Name is required'], $fields['name']);
            $this->assertSame(['Enter a valid email'], $fields['email']);
            $this->assertSame(['Password must be at least 8 characters'], $fields['password']);
            $this->assertSame(['Passwords do not match'], $fields['passwordConfirm']);
        }
    }

    public function testFlattenWhenInstalledInsidePublicHtml(): void
    {
        $base = sys_get_temp_dir() . '/hcms-flat-' . uniqid('', true);
        $web = $base . '/public_html';
        mkdir($web . '/public/admin', 0777, true);
        mkdir($web . '/src', 0777, true);
        mkdir($web . '/vendor', 0777, true);
        mkdir($web . '/storage', 0777, true);
        mkdir($web . '/database/migrations', 0777, true);
        file_put_contents($web . '/public/index.php', '<?php // front');
        file_put_contents($web . '/public/admin/index.html', '<html></html>');
        file_put_contents($web . '/install.php', '<?php // installer');
        file_put_contents($web . '/src/bootstrap.php', '<?php');
        file_put_contents($web . '/composer.json', '{}');
        $this->tmp = $base;

        $installer = new Installer(new Paths($web));
        $status = $installer->status();
        $this->assertTrue($status['insideWebRoot']);
        $this->assertSame('public_html', $status['suggestedPublicDir']);

        $paths = $installer->preparePublicLayout('public_html');

        $this->assertSame($base, $paths->root);
        $this->assertSame('public_html', $paths->publicDir);
        $this->assertSame($web, $paths->public());

        $this->assertFileExists($web . '/index.php');
        $this->assertFileExists($web . '/admin/index.html');
        $this->assertDirectoryDoesNotExist($web . '/public');
        $this->assertDirectoryDoesNotExist($web . '/public_html');
        $this->assertDirectoryExists($base . '/src');
        $this->assertDirectoryExists($base . '/vendor');
        $this->assertDirectoryExists($base . '/storage');
        $this->assertFileExists($base . '/install.php');
        $this->assertFileExists($base . '/composer.json');
        $this->assertDirectoryDoesNotExist($web . '/src');
        $this->assertDirectoryDoesNotExist($web . '/vendor');
    }

    public function testFlattenForcesPublicHtmlEvenIfFormSaysPublic(): void
    {
        $base = sys_get_temp_dir() . '/hcms-force-' . uniqid('', true);
        $web = $base . '/public_html';
        mkdir($web . '/public', 0777, true);
        mkdir($web . '/src', 0777, true);
        file_put_contents($web . '/public/index.php', '<?php');
        $this->tmp = $base;

        $paths = (new Installer(new Paths($web)))->preparePublicLayout('public');

        $this->assertSame('public_html', $paths->publicDir);
        $this->assertSame($base, $paths->root);
        $this->assertFileExists($web . '/index.php');
        $this->assertDirectoryDoesNotExist($web . '/public');
    }

    public function testRenamePublicToPublicHtmlWhenProjectRootIsOutside(): void
    {
        $root = sys_get_temp_dir() . '/hcms-rename-' . uniqid('', true);
        mkdir($root . '/public/admin', 0777, true);
        file_put_contents($root . '/public/index.php', '<?php');
        $this->tmp = $root;

        $installer = new Installer(new Paths($root));
        $paths = $installer->preparePublicLayout('public_html');

        $this->assertSame($root, $paths->root);
        $this->assertDirectoryExists($root . '/public_html');
        $this->assertFileExists($root . '/public_html/index.php');
        $this->assertDirectoryDoesNotExist($root . '/public');
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
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
