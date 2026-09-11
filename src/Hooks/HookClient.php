<?php

declare(strict_types=1);

namespace Cms\Hooks;

use Cms\Security\HmacSignature;

/**
 * Sync signed HTTP POST used by resource hooks and inbound endpoints.
 */
final class HookClient
{
    /** @var callable(string, string, array<string, string>, int): array{status: ?int, body: string, error: ?string, durationMs: int} */
    private $httpClient;

    /**
     * @param (callable(string, string, array<string, string>, int): array{status: ?int, body: string, error: ?string, durationMs: int})|null $httpClient
     */
    public function __construct(?callable $httpClient = null)
    {
        $this->httpClient = $httpClient ?? [$this, 'defaultHttpClient'];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $extraHeaders
     * @return array{
     *   ok: bool,
     *   status: ?int,
     *   body: string,
     *   decoded: array<string, mixed>,
     *   error: ?string,
     *   durationMs: int
     * }
     */
    public function post(string $url, string $secret, array $payload, int $timeoutMs, string $phase, array $extraHeaders = []): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-HCMS-Event' => $phase,
            'X-HCMS-Signature' => HmacSignature::header($body, $secret),
            'User-Agent' => 'HCMS-Hooks/1.0',
            ...$extraHeaders,
        ];

        $result = ($this->httpClient)($url, $body, $headers, max(100, $timeoutMs));
        $ok = $result['status'] !== null && $result['status'] >= 200 && $result['status'] < 300;
        $decoded = [];
        if ($result['body'] !== '') {
            $parsed = json_decode($result['body'], true);
            if (\is_array($parsed)) {
                $decoded = $parsed;
            }
        }

        $error = $ok ? null : ($result['error'] ?? ('HTTP ' . ($result['status'] ?? 'n/a')));
        if ($error !== null && \strlen($error) > 500) {
            $error = substr($error, 0, 497) . '...';
        }

        return [
            'ok' => $ok,
            'status' => $result['status'],
            'body' => $result['body'],
            'decoded' => $decoded,
            'error' => $error,
            'durationMs' => $result['durationMs'],
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: ?int, body: string, error: ?string, durationMs: int}
     */
    private function defaultHttpClient(string $url, string $body, array $headers, int $timeoutMs): array
    {
        $started = hrtime(true);
        $timeoutSec = max(1, (int) ceil($timeoutMs / 1000));
        if (\function_exists('curl_init')) {
            return $this->curlRequest($url, $body, $headers, $started, $timeoutSec);
        }

        return $this->streamRequest($url, $body, $headers, $started, $timeoutSec);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: ?int, body: string, error: ?string, durationMs: int}
     */
    private function curlRequest(string $url, string $body, array $headers, int $started, int $timeoutSec): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'status' => null,
                'body' => '',
                'error' => 'curl_init failed',
                'durationMs' => $this->elapsedMs($started),
            ];
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $responseBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = $errno !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'status' => $status > 0 ? $status : null,
            'body' => \is_string($responseBody) ? $responseBody : '',
            'error' => $error !== null && $error !== '' ? $error : null,
            'durationMs' => $this->elapsedMs($started),
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: ?int, body: string, error: ?string, durationMs: int}
     */
    private function streamRequest(string $url, string $body, array $headers, int $started, int $timeoutSec): array
    {
        $headerBlob = '';
        foreach ($headers as $name => $value) {
            $headerBlob .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headerBlob,
                'content' => $body,
                'timeout' => $timeoutSec,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $status = null;
        $responseHeaders = \function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : null;
        if (!\is_array($responseHeaders)) {
            $responseHeaders = [];
        }
        if ($responseHeaders !== [] && preg_match('/\s(\d{3})\s/', (string) $responseHeaders[0], $m) === 1) {
            $status = (int) $m[1];
        }

        if ($raw === false) {
            return [
                'status' => $status,
                'body' => '',
                'error' => 'request failed',
                'durationMs' => $this->elapsedMs($started),
            ];
        }

        return [
            'status' => $status,
            'body' => $raw,
            'error' => null,
            'durationMs' => $this->elapsedMs($started),
        ];
    }

    private function elapsedMs(int $started): int
    {
        return (int) max(0, (hrtime(true) - $started) / 1_000_000);
    }
}
