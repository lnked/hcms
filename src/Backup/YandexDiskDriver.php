<?php

declare(strict_types=1);

namespace Cms\Backup;

use RuntimeException;

final class YandexDiskDriver implements RemoteDriver
{
    private const TOKEN_URL = 'https://oauth.yandex.ru/token';
    private const API = 'https://cloud-api.yandex.net/v1/disk';

    private ?string $accessToken = null;

    /**
     * @param array{enabled: bool, clientId: string, clientSecret: string, refreshToken: string, accountLabel: string} $config
     */
    public function __construct(
        private readonly array $config,
        private readonly BackupHttpClient $http,
        private readonly string $remoteFolder = 'hcms-backups',
    ) {
    }

    public function id(): string
    {
        return 'yandex';
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
            'GET',
            self::API . '/',
            ['Authorization' => 'OAuth ' . $this->accessToken()],
        );
        if ($res['status'] >= 400) {
            throw new RuntimeException('Yandex Disk test failed: HTTP ' . $res['status']);
        }
        $this->ensureFolder();
    }

    public function upload(string $localPath, string $destKey): void
    {
        $this->ensureFolder();
        $path = $this->diskPath($destKey);
        $hrefRes = $this->http->request(
            'GET',
            self::API . '/resources/upload?path=' . rawurlencode($path) . '&overwrite=true',
            ['Authorization' => 'OAuth ' . $this->accessToken()],
        );
        $json = json_decode($hrefRes['body'], true);
        $href = \is_array($json) && isset($json['href']) && \is_string($json['href']) ? $json['href'] : '';
        if ($href === '') {
            throw new RuntimeException('Yandex Disk upload URL missing: HTTP ' . $hrefRes['status']);
        }
        $put = $this->http->uploadFile('PUT', $href, $localPath, [], 600);
        if ($put['status'] >= 400) {
            throw new RuntimeException('Yandex Disk upload failed: HTTP ' . $put['status']);
        }
    }

    public function download(string $destKey, string $localPath): void
    {
        $path = $this->diskPath($destKey);
        $hrefRes = $this->http->request(
            'GET',
            self::API . '/resources/download?path=' . rawurlencode($path),
            ['Authorization' => 'OAuth ' . $this->accessToken()],
        );
        $json = json_decode($hrefRes['body'], true);
        $href = \is_array($json) && isset($json['href']) && \is_string($json['href']) ? $json['href'] : '';
        if ($href === '') {
            throw new RuntimeException('Yandex Disk download URL missing');
        }
        $this->http->downloadToFile($href, $localPath, [], 600);
    }

    public function listKeys(): array
    {
        $res = $this->http->request(
            'GET',
            self::API . '/resources?path=' . rawurlencode('disk:/' . $this->remoteFolder) . '&limit=100',
            ['Authorization' => 'OAuth ' . $this->accessToken()],
        );
        $json = json_decode($res['body'], true);
        $keys = [];
        $items = \is_array($json) && isset($json['_embedded']['items']) && \is_array($json['_embedded']['items'])
            ? $json['_embedded']['items']
            : [];
        foreach ($items as $item) {
            if (\is_array($item) && isset($item['name']) && \is_string($item['name'])) {
                $keys[] = $item['name'];
            }
        }

        return $keys;
    }

    public function delete(string $destKey): void
    {
        $this->http->request(
            'DELETE',
            self::API . '/resources?path=' . rawurlencode($this->diskPath($destKey)) . '&permanently=true',
            ['Authorization' => 'OAuth ' . $this->accessToken()],
        );
    }

    private function ensureFolder(): void
    {
        $this->http->request(
            'PUT',
            self::API . '/resources?path=' . rawurlencode('disk:/' . $this->remoteFolder),
            ['Authorization' => 'OAuth ' . $this->accessToken()],
        );
    }

    private function diskPath(string $destKey): string
    {
        return 'disk:/' . $this->remoteFolder . '/' . ltrim($destKey, '/');
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }
        if (!$this->connected()) {
            throw new RuntimeException('Yandex Disk is not connected');
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
            throw new RuntimeException('Yandex token refresh failed: HTTP ' . $res['status']);
        }
        $this->accessToken = $json['access_token'];

        return $this->accessToken;
    }
}
