<?php

declare(strict_types=1);

namespace Cms\Install;

use Cms\Core\Version;

/**
 * Anonymous install ping after a successful `complete`.
 *
 * Sends version / PHP major.minor / OS family / source — no emails, hosts, or IPs.
 * Failures are swallowed; install must never depend on this endpoint.
 *
 * Opt-out: payload `telemetry: false`, env `HCMS_TELEMETRY=0`, or `HCMS_NO_TELEMETRY=1`.
 * Endpoint override: `HCMS_TELEMETRY_URL` (empty / `off` disables the ping).
 */
final class InstallTelemetry
{
    public const DEFAULT_URL = 'https://api.2js.ru/api/installs';

    /** @var list<string> */
    private const SOURCES = ['wizard', 'api', 'cli'];

    /**
     * @param array<string, mixed> $payload Installer::complete body
     */
    public static function enabled(array $payload): bool
    {
        $env = getenv('HCMS_TELEMETRY');
        if (\is_string($env) && $env !== '') {
            $norm = strtolower(trim($env));
            if (\in_array($norm, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }

        $no = getenv('HCMS_NO_TELEMETRY');
        if (\is_string($no) && \in_array(strtolower(trim($no)), ['1', 'true', 'yes', 'on'], true)) {
            return false;
        }

        if (\array_key_exists('telemetry', $payload) && $payload['telemetry'] === false) {
            return false;
        }

        $url = self::endpoint();

        return $url !== '';
    }

    public static function endpoint(): string
    {
        $override = getenv('HCMS_TELEMETRY_URL');
        if (\is_string($override)) {
            $trimmed = trim($override);
            if ($trimmed === '' || \in_array(strtolower($trimmed), ['0', 'off', 'false'], true)) {
                return '';
            }

            return rtrim($trimmed, '/');
        }

        return self::DEFAULT_URL;
    }

    /**
     * Queue a fire-and-forget POST after the HTTP response is sent.
     *
     * @param array<string, mixed> $payload
     */
    public static function schedule(array $payload): void
    {
        if (!self::enabled($payload)) {
            return;
        }

        $body = self::buildBody($payload);
        $url = self::endpoint();
        if ($url === '') {
            return;
        }

        register_shutdown_function(static function () use ($url, $body): void {
            self::post($url, $body);
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{version: string, php: string, source: string, os: string, date: string}
     */
    public static function buildBody(array $payload): array
    {
        $source = isset($payload['telemetrySource']) && \is_string($payload['telemetrySource'])
            ? strtolower(trim($payload['telemetrySource']))
            : 'api';
        if (!\in_array($source, self::SOURCES, true)) {
            $source = 'api';
        }

        $os = \defined('PHP_OS_FAMILY') ? (string) PHP_OS_FAMILY : 'Unknown';
        if (!\in_array($os, ['Windows', 'BSD', 'Darwin', 'Solaris', 'Linux'], true)) {
            $os = 'Unknown';
        }

        return [
            'version' => Version::current(),
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'source' => $source,
            'os' => $os,
            'date' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array{version: string, php: string, source: string, os: string, date: string} $body
     */
    public static function post(string $url, array $body): void
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!\is_string($json)) {
            return;
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: hcms-install-telemetry/' . Version::current(),
        ];

        try {
            if (\function_exists('curl_init')) {
                $ch = curl_init($url);
                if ($ch === false) {
                    return;
                }
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_TIMEOUT => 3,
                    CURLOPT_HTTPHEADER => $headers,
                ]);
                curl_exec($ch);
                curl_close($ch);

                return;
            }

            if (!(bool) \ini_get('allow_url_fopen')) {
                return;
            }

            @file_get_contents($url, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'content' => $json,
                    'timeout' => 3,
                    'ignore_errors' => true,
                ],
            ]));
        } catch (\Throwable) {
            // never surface telemetry errors
        }
    }
}
