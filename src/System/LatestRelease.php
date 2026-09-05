<?php

declare(strict_types=1);

namespace Cms\System;

use Cms\Core\Config;
use Cms\Core\Paths;

final class LatestRelease
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetch(): ?array
    {
        $cacheFile = $this->paths->cache() . '/latest.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 21600) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $url = sprintf(
            'https://github.com/%s/releases/latest/download/latest.json',
            $this->config->githubRepo,
        );

        $json = $this->httpGet($url);
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['version']) || !is_string($data['version'])) {
            return null;
        }

        if (!is_dir($this->paths->cache())) {
            @mkdir($this->paths->cache(), 0775, true);
        }
        @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_SLASHES));

        return $data;
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
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'hcms-updater',
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
            'http' => ['timeout' => 8, 'header' => "User-Agent: hcms-updater\r\n"],
        ]);
        $body = @file_get_contents($url, false, $context);

        return is_string($body) ? $body : null;
    }
}
