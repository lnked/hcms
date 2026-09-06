<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Paths;
use Cms\Core\Version;
use Cms\Install\Installer;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    public function testStatusWhenNotInstalled(): void
    {
        $installer = new Installer(new Paths(dirname(__DIR__)));
        $status = $installer->status();

        $this->assertFalse($status['installed']);
        $this->assertTrue($status['srcReady']);
        $this->assertSame(Version::current(), $status['version']);
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

    public function testCompleteRejectsInvalidAdministratorWithFieldErrors(): void
    {
        $root = sys_get_temp_dir() . '/hcms-install-val-' . uniqid();
        mkdir($root . '/storage', 0777, true);
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
}
