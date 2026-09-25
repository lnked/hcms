<?php

declare(strict_types=1);

namespace Cms\Backup;

use RuntimeException;

/**
 * Curl client with configurable timeout for large backup uploads.
 */
final class BackupHttpClient
{
    public function __construct(private readonly int $timeoutSec = 120)
    {
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?int $timeoutSec = null,
    ): array {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Failed to init HTTP client');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $customMethod = strtoupper($method);
        if ($customMethod === '') {
            throw new RuntimeException('HTTP method must not be empty');
        }

        $responseHeaders = [];
        /** @var array<int, mixed> $options */
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeoutSec ?? $this->timeoutSec,
            CURLOPT_CUSTOMREQUEST => $customMethod,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                unset($ch);
                $trim = trim($line);
                if ($trim !== '' && str_contains($trim, ':')) {
                    [$name, $value] = explode(':', $trim, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return \strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            throw new RuntimeException($error !== '' ? $error : 'HTTP request failed');
        }

        return [
            'status' => $status,
            'body' => \is_string($raw) ? $raw : '',
            'headers' => $responseHeaders,
        ];
    }

    /**
     * PUT/POST a local file as the request body (streaming from disk).
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function uploadFile(
        string $method,
        string $url,
        string $localPath,
        array $headers = [],
        ?int $timeoutSec = null,
    ): array {
        $size = filesize($localPath);
        if ($size === false) {
            throw new RuntimeException('Cannot read file size: ' . $localPath);
        }
        $fp = fopen($localPath, 'rb');
        if ($fp === false) {
            throw new RuntimeException('Cannot open file: ' . $localPath);
        }

        $handle = curl_init($url);
        if ($handle === false) {
            fclose($fp);

            throw new RuntimeException('Failed to init HTTP client');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $customMethod = strtoupper($method);
        if ($customMethod === '') {
            fclose($fp);

            throw new RuntimeException('HTTP method must not be empty');
        }

        $responseHeaders = [];
        /** @var array<int, mixed> $options */
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeoutSec ?? $this->timeoutSec,
            CURLOPT_CUSTOMREQUEST => $customMethod,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fp,
            CURLOPT_INFILESIZE => $size,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                unset($ch);
                $trim = trim($line);
                if ($trim !== '' && str_contains($trim, ':')) {
                    [$name, $value] = explode(':', $trim, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return \strlen($line);
            },
        ];

        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        fclose($fp);

        if ($raw === false) {
            throw new RuntimeException($error !== '' ? $error : 'HTTP upload failed');
        }

        return [
            'status' => $status,
            'body' => \is_string($raw) ? $raw : '',
            'headers' => $responseHeaders,
        ];
    }

    /**
     * Download URL body to a local file.
     *
     * @param array<string, string> $headers
     */
    public function downloadToFile(string $url, string $localPath, array $headers = [], ?int $timeoutSec = null): void
    {
        $dir = \dirname($localPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create directory: ' . $dir);
        }

        $fp = fopen($localPath, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Cannot write file: ' . $localPath);
        }

        $handle = curl_init($url);
        if ($handle === false) {
            fclose($fp);

            throw new RuntimeException('Failed to init HTTP client');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSec ?? $this->timeoutSec,
            CURLOPT_HTTPHEADER => $headerLines,
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        fclose($fp);

        if ($ok === false || $status >= 400) {
            @unlink($localPath);

            throw new RuntimeException(
                $error !== '' ? $error : ('Download failed with HTTP ' . $status),
            );
        }
    }
}
