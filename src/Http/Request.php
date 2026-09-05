<?php

declare(strict_types=1);

namespace Cms\Http;

final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly mixed $body,
        public readonly string $rawBody,
        public readonly string $ip,
        public readonly string $userAgent,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = self::normalizePath($path);

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_') || !is_string($value)) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        $raw = (string) file_get_contents('php://input');
        $body = null;
        $contentType = $headers['content-type'] ?? '';
        if ($raw !== '' && str_contains($contentType, 'application/json')) {
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : null;
        }

        /** @var array<string, mixed> $get */
        $get = $_GET;
        $query = self::flattenQuery($get);

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        return new self($method, $path, $query, $headers, $body, $raw, $ip, $ua);
    }

    /**
     * Flatten PHP nested query arrays so filter[field]=x becomes key "filter[field]".
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function flattenQuery(array $input, string $prefix = ''): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $out += self::flattenQuery($value, $full);
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $out[$full] = (string) $value;
            }
        }

        return $out;
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        if (str_ends_with($path, '/index.php')) {
            $path = substr($path, 0, -10);
            $path = $path === '' ? '/' : $path;
        }

        return $path;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if ($header === null || !preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        return is_array($this->body) ? $this->body : [];
    }

    public function query(string $key, ?string $default = null): ?string
    {
        return $this->query[$key] ?? $default;
    }
}
