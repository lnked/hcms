<?php

declare(strict_types=1);

namespace Cms\Backup;

use RuntimeException;

final class GoogleDriveDriver implements RemoteDriver
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart';
    private const FILES_URL = 'https://www.googleapis.com/drive/v3/files';

    private ?string $accessToken = null;

    /**
     * @param array{enabled: bool, clientId: string, clientSecret: string, refreshToken: string, accountLabel: string} $config
     */
    public function __construct(
        private readonly array $config,
        private readonly BackupHttpClient $http,
    ) {
    }

    public function id(): string
    {
        return 'google';
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
        $this->accessToken();
        $res = $this->http->request(
            'GET',
            self::FILES_URL . '?pageSize=1&fields=files(id)',
            ['Authorization' => 'Bearer ' . $this->accessToken()],
        );
        if ($res['status'] >= 400) {
            throw new RuntimeException('Google Drive test failed: HTTP ' . $res['status']);
        }
    }

    public function upload(string $localPath, string $destKey): void
    {
        $meta = json_encode(['name' => $destKey, 'mimeType' => 'application/zip'], JSON_UNESCAPED_SLASHES);
        $boundary = 'hcms_' . bin2hex(random_bytes(8));
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . $meta . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: application/zip\r\n\r\n"
            . (file_get_contents($localPath) ?: '') . "\r\n"
            . '--' . $boundary . "--\r\n";

        $res = $this->http->request(
            'POST',
            self::UPLOAD_URL,
            [
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Content-Type' => 'multipart/related; boundary=' . $boundary,
            ],
            $body,
            600,
        );
        if ($res['status'] >= 400) {
            throw new RuntimeException('Google Drive upload failed: HTTP ' . $res['status'] . ' ' . $res['body']);
        }
    }

    public function download(string $destKey, string $localPath): void
    {
        $fileId = $this->findFileId($destKey);
        if ($fileId === null) {
            throw new RuntimeException('Google Drive file not found: ' . $destKey);
        }
        $this->http->downloadToFile(
            self::FILES_URL . '/' . rawurlencode($fileId) . '?alt=media',
            $localPath,
            ['Authorization' => 'Bearer ' . $this->accessToken()],
            600,
        );
    }

    public function listKeys(): array
    {
        $res = $this->http->request(
            'GET',
            self::FILES_URL . '?pageSize=100&fields=files(name)&q=' . rawurlencode("name contains 'data-' and trashed=false"),
            ['Authorization' => 'Bearer ' . $this->accessToken()],
        );
        if ($res['status'] >= 400) {
            return [];
        }
        $json = json_decode($res['body'], true);
        $keys = [];
        if (\is_array($json) && isset($json['files']) && \is_array($json['files'])) {
            foreach ($json['files'] as $file) {
                if (\is_array($file) && isset($file['name']) && \is_string($file['name'])) {
                    $keys[] = $file['name'];
                }
            }
        }

        return $keys;
    }

    public function delete(string $destKey): void
    {
        $fileId = $this->findFileId($destKey);
        if ($fileId === null) {
            return;
        }
        $this->http->request(
            'DELETE',
            self::FILES_URL . '/' . rawurlencode($fileId),
            ['Authorization' => 'Bearer ' . $this->accessToken()],
        );
    }

    private function findFileId(string $destKey): ?string
    {
        $q = "name = '" . str_replace("'", "\\'", $destKey) . "' and trashed = false";
        $res = $this->http->request(
            'GET',
            self::FILES_URL . '?pageSize=1&fields=files(id)&q=' . rawurlencode($q),
            ['Authorization' => 'Bearer ' . $this->accessToken()],
        );
        $json = json_decode($res['body'], true);
        if (\is_array($json) && isset($json['files'][0]['id']) && \is_string($json['files'][0]['id'])) {
            return $json['files'][0]['id'];
        }

        return null;
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }
        if (!$this->connected()) {
            throw new RuntimeException('Google Drive is not connected');
        }
        $res = $this->http->request(
            'POST',
            self::TOKEN_URL,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'client_id' => $this->config['clientId'],
                'client_secret' => $this->config['clientSecret'],
                'refresh_token' => $this->config['refreshToken'],
                'grant_type' => 'refresh_token',
            ]),
        );
        $json = json_decode($res['body'], true);
        if ($res['status'] >= 400 || !\is_array($json) || !isset($json['access_token']) || !\is_string($json['access_token'])) {
            throw new RuntimeException('Google token refresh failed: HTTP ' . $res['status']);
        }
        $this->accessToken = $json['access_token'];

        return $this->accessToken;
    }
}
