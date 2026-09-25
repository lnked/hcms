<?php

declare(strict_types=1);

namespace Cms\Backup;

use Cms\Core\Paths;
use RuntimeException;

final class RemoteDriverFactory
{
    public function __construct(
        private readonly BackupRemoteSettings $settings,
        private readonly BackupHttpClient $http,
        private readonly Paths $paths,
    ) {
    }

    public function make(string $provider): RemoteDriver
    {
        $config = $this->settings->raw();

        return match ($provider) {
            'google' => new GoogleDriveDriver($config['google'], $this->http),
            'yandex' => new YandexDiskDriver($config['yandex'], $this->http),
            'dropbox' => new DropboxDriver($config['dropbox'], $this->http),
            'sftp' => new SftpDriver($config['sftp'], $this->paths->storage() . '/tmp'),
            default => throw new RuntimeException('Unknown backup provider: ' . $provider),
        };
    }

    /**
     * @return list<string>
     */
    public function connectedProviders(): array
    {
        $out = [];
        foreach (BackupRemoteSettings::ALL_PROVIDERS as $id) {
            if ($this->make($id)->connected()) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
