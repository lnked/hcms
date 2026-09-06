<?php

declare(strict_types=1);

namespace Cms\Core;

final class FileCache
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    public function get(string $key): mixed
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || !array_key_exists('value', $payload)) {
            return null;
        }

        $expiresAt = $payload['expires_at'] ?? null;
        if (is_int($expiresAt) && $expiresAt < time()) {
            $this->forget($key);

            return null;
        }

        return $payload['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $payload = [
            'expires_at' => $ttl > 0 ? time() + $ttl : null,
            'value' => $value,
        ];

        file_put_contents(
            $this->path($key),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            LOCK_EX,
        );
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function flush(): void
    {
        $files = glob($this->directory . '/*.cache') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function path(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $key) ?: 'key';

        return $this->directory . '/' . $safe . '.cache';
    }
}
