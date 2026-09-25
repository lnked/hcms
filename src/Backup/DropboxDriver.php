<?php

declare(strict_types=1);

namespace Cms\Backup;

use RuntimeException;

final class DropboxDriver implements RemoteDriver
{
    private const TOKEN_URL = 'https://api.dropboxapi.com/oauth2/token';
    private const UPLOAD_URL = 'https://content.dropboxapi.com/2/files/upload';
    private const DOWNLOAD_URL = 'https://content.dropboxapi.com/2/files/download';
    private const LIST_URL = 'https://api.dropboxapi.com/2/files/list_folder';
    private const DELETE_URL = 'https://api.dropboxapi.com/2/files/delete_v2';

    private ?string $accessToken = null;

    /**
     * @param array{enabled: bool, clientId: string, clientSecret: string, refreshToken: string, accountLabel: string} $config
     */
    public function __construct(
        private readonly array $config,
        private readonly BackupHttpClient $http,
        private readonly string $remoteFolder = '/hcms-backups',
    ) {
    }

    public function id(): string
    {
        return 'dropbox';
    }

    public function connected(): bool
    {
        return $this->config['enabled']
            && $this->config['clientId'] !== ''
            && $this->config['clientSecret'] !== ''
            && $this->config['refreshToken'] !== '';
    }

    public function test(): void
    {
        $res = $this->http->request(
            'POST',
            self::LIST_URL,
            [
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Content-Type' => 'application/json',
            ],
            json_encode(['path' => '', 'limit' => 1], JSON_UNESCAPED_SLASHES) ?: '{}',
        );
        if ($res['status'] >= 400) {
            throw new RuntimeException('Dropbox test failed: HTTP ' . $res['status']);
        }
    }

    public function upload(string $localPath, string $destKey): void
    {
        $path = rtrim($this->remoteFolder, '/') . '/' . ltrim($destKey, '/');
        $arg = json_encode([
            'path' => $path,
            'mode' => 'overwrite',
            'autorename' => false,
            'mute' => true,
        ], JSON_UNESCAPED_SLASHES) ?: '{}';

        $body = file_get_contents($localPath);
        if ($body === false) {
            throw new RuntimeException('Cannot read file for Dropbox upload');
        }

        $res = $this->http->request(
            'POST',
            self::UPLOAD_URL,
            [
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Content-Type' => 'application/octet-stream',
                'Dropbox-API-Arg' => $arg,
            ],
            $body,
            600,
        );
        if ($res['status'] >= 400) {
            throw new RuntimeException('Dropbox upload failed: HTTP ' . $res['status'] . ' ' . $res['body']);
        }
    }

    public function download(string $destKey, string $localPath): void
    {
        $path = rtrim($this->remoteFolder, '/') . '/' . ltrim($destKey, '/');
        $arg = json_encode(['path' => $path], JSON_UNESCAPED_SLASHES) ?: '{}';
        $res = $this->http->request(
            'POST',
            self::DOWNLOAD_URL,
            [
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Dropbox-API-Arg' => $arg,
            ],
            '',
            600,
        );
        if ($res['status'] >= 400) {
            throw new RuntimeException('Dropbox download failed: HTTP ' . $res['status']);
        }
        $dir = \dirname($localPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create download dir');
        }
        if (file_put_contents($localPath, $res['body']) === false) {
            throw new RuntimeException('Cannot write Dropbox download');
        }
    }

    public function listKeys(): array
    {
        $res = $this->http->request(
            'POST',
            self::LIST_URL,
            [
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Content-Type' => 'application/json',
            ],
            json_encode(['path' => $this->remoteFolder, 'limit' => 100], JSON_UNESCAPED_SLASHES) ?: '{}',
        );
        $json = json_decode($res['body'], true);
        $keys = [];
        $entries = \is_array($json) && isset($json['entries']) && \is_array($json['entries']) ? $json['entries'] : [];
        foreach ($entries as $entry) {
            if (\is_array($entry) && isset($entry['name']) && \is_string($entry['name'])) {
                $keys[] = $entry['name'];
            }
        }

        return $keys;
    }

    public function delete(string $destKey): void
    {
        $path = rtrim($this->remoteFolder, '/') . '/' . ltrim($destKey, '/');
        $this->http->request(
            'POST',
            self::DELETE_URL,
            [
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Content-Type' => 'application/json',
            ],
            json_encode(['path' => $path], JSON_UNESCAPED_SLASHES) ?: '{}',
        );
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }
        if (!$this->connected()) {
            throw new RuntimeException('Dropbox is not connected');
        }
        $res = $this->http->request(
            'POST',
            self::TOKEN_URL,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->config['refreshToken'],
                'client_id' => $this->config['clientId'],
                'client_secret' => $this->config['clientSecret'],
            ]),
        );
        $json = json_decode($res['body'], true);
        if ($res['status'] >= 400 || !\is_array($json) || !isset($json['access_token']) || !\is_string($json['access_token'])) {
            throw new RuntimeException('Dropbox token refresh failed: HTTP ' . $res['status']);
        }
        $this->accessToken = $json['access_token'];

        return $this->accessToken;
    }
}
