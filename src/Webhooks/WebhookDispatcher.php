<?php

declare(strict_types=1);

namespace Cms\Webhooks;

use Cms\Security\HmacSignature;

final class WebhookDispatcher
{
    private const TIMEOUT_SECONDS = 5;
    private const MAX_ATTEMPTS = 3;

    /** @var callable(string, string, array<string, string>): array{status: ?int, body: string, error: ?string, durationMs: int} */
    private $httpClient;

    /**
     * @param (callable(string, string, array<string, string>): array{status: ?int, body: string, error: ?string, durationMs: int})|null $httpClient
     */
    public function __construct(
        private readonly WebhookRepository $webhooks,
        ?callable $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? [$this, 'defaultHttpClient'];
    }

    public static function signatureHeader(string $body, string $secret): string
    {
        return HmacSignature::header($body, $secret);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchAfterResponse(string $event, array $payload, ?int $resourceId = null): void
    {
        register_shutdown_function(function () use ($event, $payload, $resourceId): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            $this->dispatch($event, $payload, $resourceId);
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload, ?int $resourceId = null): void
    {
        $candidates = $this->webhooks->findActiveForResource($resourceId);
        foreach ($candidates as $webhook) {
            if (!$this->matchesEvent($webhook, $event)) {
                continue;
            }
            $this->deliverWithRetries($webhook, $event, $payload);
        }
    }

    /**
     * Single attempt used by the admin test endpoint (no retries / no shutdown).
     *
     * @param array<string, mixed> $webhook
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function deliverOnce(array $webhook, string $event, array $payload, int $attempt = 1): array
    {
        return $this->attemptDelivery($webhook, $event, $payload, $attempt);
    }

    /**
     * @param array<string, mixed> $webhook
     * @param array<string, mixed> $payload
     */
    private function deliverWithRetries(array $webhook, string $event, array $payload): void
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $backoff = $attempt - 1;
            if ($backoff > 0) {
                sleep($backoff);
            }
            $delivery = $this->attemptDelivery($webhook, $event, $payload, $attempt);
            if (($delivery['status'] ?? '') === 'success') {
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $webhook
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function attemptDelivery(array $webhook, string $event, array $payload, int $attempt): array
    {
        $pending = $this->webhooks->createDelivery([
            'webhook_id' => (int) $webhook['id'],
            'event' => $event,
            'payload' => $payload,
            'response_code' => null,
            'duration_ms' => null,
            'attempt' => $attempt,
            'status' => 'pending',
            'error_message' => null,
        ]);
        $deliveryId = (string) (int) $pending['id'];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-HCMS-Event' => $event,
            'X-HCMS-Signature' => self::signatureHeader($body, (string) $webhook['secret']),
            'X-HCMS-Delivery-Id' => $deliveryId,
            'User-Agent' => 'HCMS-Webhooks/1.0',
        ];

        $result = ($this->httpClient)((string) $webhook['url'], $body, $headers);
        $ok = $result['status'] !== null && $result['status'] >= 200 && $result['status'] < 300;
        $error = $ok ? null : ($result['error'] ?? ('HTTP ' . ($result['status'] ?? 'n/a')));
        if ($error !== null && strlen($error) > 500) {
            $error = substr($error, 0, 497) . '...';
        }

        return $this->webhooks->updateDelivery((int) $pending['id'], [
            'response_code' => $result['status'],
            'duration_ms' => $result['durationMs'],
            'status' => $ok ? 'success' : 'failed',
            'error_message' => $error,
        ]);
    }

    /**
     * @param array<string, mixed> $webhook
     */
    private function matchesEvent(array $webhook, string $event): bool
    {
        $events = $webhook['events'];
        if (is_string($events)) {
            $decoded = json_decode($events, true);
            $events = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($events)) {
            return false;
        }

        return in_array($event, $events, true);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: ?int, body: string, error: ?string, durationMs: int}
     */
    private function defaultHttpClient(string $url, string $body, array $headers): array
    {
        $started = hrtime(true);
        if (function_exists('curl_init')) {
            return $this->curlRequest($url, $body, $headers, $started);
        }

        return $this->streamRequest($url, $body, $headers, $started);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: ?int, body: string, error: ?string, durationMs: int}
     */
    private function curlRequest(string $url, string $body, array $headers, int $started): array
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
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $responseBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = $errno !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'status' => $status > 0 ? $status : null,
            'body' => is_string($responseBody) ? $responseBody : '',
            'error' => $error !== null && $error !== '' ? $error : null,
            'durationMs' => $this->elapsedMs($started),
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: ?int, body: string, error: ?string, durationMs: int}
     */
    private function streamRequest(string $url, string $body, array $headers, int $started): array
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
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $status = null;
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : null;
        if (!is_array($responseHeaders)) {
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
