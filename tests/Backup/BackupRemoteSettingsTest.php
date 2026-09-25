<?php

declare(strict_types=1);

namespace Cms\Tests\Backup;

use Cms\Backup\BackupRemoteSettings;
use Cms\Core\Settings;
use Cms\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class BackupRemoteSettingsTest extends TestCase
{
    public function testPublicConfigMasksSecrets(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE cms_settings (`key` TEXT PRIMARY KEY, value_json TEXT, updated_at TEXT)');
        $settings = new Settings(new Connection($pdo));
        $remote = new BackupRemoteSettings($settings);
        $remote->update([
            'google' => [
                'enabled' => true,
                'clientId' => 'cid',
                'clientSecret' => 'supersecret123',
            ],
            'sftp' => [
                'enabled' => true,
                'host' => 'backup.example.com',
                'username' => 'u',
                'auth' => 'password',
                'password' => 'passw0rd',
            ],
        ]);

        $public = $remote->publicConfig('https://cms.test', '/admin/api');
        self::assertTrue($public['providers']['google']['clientSecretConfigured']);
        self::assertSame('••••t123', $public['providers']['google']['clientSecretMasked']);
        self::assertStringContainsString('/backups/cloud/google/callback', $public['providers']['google']['redirectUri']);
        self::assertTrue($public['providers']['sftp']['connected']);
        self::assertSame('••••w0rd', $public['providers']['sftp']['passwordMasked']);
    }
}
