<?php

declare(strict_types=1);

namespace Cms\Http;

use RuntimeException;

final class CurlHttpClient implements HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Failed to init HTTP client');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
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
            'body' => is_string($raw) ? $raw : '',
        ];
    }
}
