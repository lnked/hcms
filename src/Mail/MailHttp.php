<?php

declare(strict_types=1);

namespace Cms\Mail;

use RuntimeException;

/**
 * Minimal HTTP POST helper for mail providers (curl preferred).
 */
final class MailHttp
{
    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string}
     */
    public static function post(string $url, string $body, array $headers, string $providerLabel): array
    {
        if (\function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init failed');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $responseBody = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            if ($errno !== 0) {
                throw new MailProviderException($providerLabel . ' request failed: ' . $error, 502);
            }

            return [$status, \is_string($responseBody) ? $responseBody : ''];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $responseBody = @file_get_contents($url, false, $context);
        $status = 0;
        $responseHeaders = \function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : null;
        if (\is_array($responseHeaders)) {
            foreach ($responseHeaders as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m) === 1) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }
        if (!\is_string($responseBody)) {
            throw new MailProviderException($providerLabel . ' request failed: empty response', 502);
        }

        return [$status, $responseBody];
    }

    /**
     * @param array<string, mixed> $decoded
     */
    public static function jsonErrorMessage(array $decoded): ?string
    {
        if (isset($decoded['message']) && \is_string($decoded['message']) && $decoded['message'] !== '') {
            return $decoded['message'];
        }
        if (isset($decoded['Message']) && \is_string($decoded['Message']) && $decoded['Message'] !== '') {
            return $decoded['Message'];
        }
        if (isset($decoded['error']) && \is_string($decoded['error']) && $decoded['error'] !== '') {
            return $decoded['error'];
        }
        if (isset($decoded['Error']) && \is_string($decoded['Error']) && $decoded['Error'] !== '') {
            return $decoded['Error'];
        }

        return null;
    }
}
