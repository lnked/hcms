<?php

declare(strict_types=1);

namespace Cms\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function data(mixed $data, int $status = 200): self
    {
        return self::json(['data' => $data], $status);
    }

    /**
     * @param array<string, list<string>> $fields
     */
    public static function error(string $code, string $message, int $status, array $fields = []): self
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return self::json(['error' => $error], $status);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $text, int $status = 200, string $contentType = 'text/plain; charset=utf-8'): self
    {
        return new self($status, $text, ['Content-Type' => $contentType]);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
