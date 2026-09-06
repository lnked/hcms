<?php

declare(strict_types=1);

namespace Cms\System;

use Cms\Core\Config;

final class LatestRelease
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * Always fetches GitHub latest.json (no disk cache).
     *
     * @return array<string, mixed>|null
     */
    public function fetch(): ?array
    {
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
