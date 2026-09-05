<?php

declare(strict_types=1);

namespace Cms\Install;

use Cms\Core\Paths;
use RuntimeException;
use ZipArchive;

final class ReleaseDownloader
{
    public const GITHUB_REPO = 'lnked/hcms';

    public function __construct(private readonly Paths $paths)
    {
    }

    public function srcReady(): bool
    {
        return is_file($this->paths->root . '/src/bootstrap.php');
    }

    /**
     * @return array<string, mixed>
     */
    public function download(): array
    {
        if ($this->srcReady()) {
            return ['skipped' => true, 'reason' => 'src_present'];
        }

        $manifest = $this->fetchJson($this->latestUrl('latest.json'));
        $version = isset($manifest['version']) && is_string($manifest['version']) ? $manifest['version'] : '';
        $zipUrl = isset($manifest['zip']) && is_string($manifest['zip'])
            ? $manifest['zip']
            : $this->latestUrl('cms-' . $version . '.zip');
        $expectedHash = isset($manifest['sha256']) && is_string($manifest['sha256'])
            ? strtolower($manifest['sha256'])
            : $this->fetchText($this->latestUrl('cms-' . $version . '.zip.sha256'));

        if ($version === '') {
            throw new RuntimeException('Invalid release manifest');
        }

        $tmp = $this->paths->storage() . '/cms-' . $version . '.zip';
        if (!is_dir($this->paths->storage())) {
            mkdir($this->paths->storage(), 0775, true);
        }

        $this->fetchToFile($zipUrl, $tmp);
        $actual = hash_file('sha256', $tmp);
        $expectedHash = strtolower(trim(preg_replace('/\s.*/', '', $expectedHash) ?? $expectedHash));
        if ($actual === false || $expectedHash === '' || !hash_equals($expectedHash, $actual)) {
            @unlink($tmp);
            throw new RuntimeException('Release checksum mismatch');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Unable to open release archive');
        }
        $zip->extractTo($this->paths->root);
        $zip->close();
        @unlink($tmp);

        return [
            'skipped' => false,
            'version' => $version,
            'changelog' => $manifest['changelog'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function latestManifest(): array
    {
        return $this->fetchJson($this->latestUrl('latest.json'));
    }

    private function latestUrl(string $asset): string
    {
        return 'https://github.com/' . self::GITHUB_REPO . '/releases/latest/download/' . $asset;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJson(string $url): array
    {
        $raw = $this->fetchText($url);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Unable to parse ' . $url);
        }

        return $data;
    }

    private function fetchText(string $url): string
    {
        $body = $this->httpGet($url);
        if ($body === null) {
            throw new RuntimeException('Unable to download ' . $url);
        }

        return $body;
    }

    private function fetchToFile(string $url, string $path): void
    {
        $body = $this->httpGet($url);
        if ($body === null || file_put_contents($path, $body) === false) {
            throw new RuntimeException('Unable to download ' . $url);
        }
    }

    private function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_USERAGENT => 'hcms-installer',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($body) || $code >= 400) {
                return null;
            }

            return $body;
        }

        $context = stream_context_create([
            'http' => ['timeout' => 60, 'header' => "User-Agent: hcms-installer\r\n"],
        ]);
        $body = @file_get_contents($url, false, $context);

        return is_string($body) ? $body : null;
    }
}
