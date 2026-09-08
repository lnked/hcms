<?php

declare(strict_types=1);

namespace Cms\Http;

interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
