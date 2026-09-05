<?php

declare(strict_types=1);

namespace Cms\Http;

final class Route
{
    /**
     * @param callable(Request, array<string, string>, \Cms\Auth\AuthContext|null): Response $handler
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly mixed $handler,
        public readonly bool $public = false,
        public readonly string $auth = 'admin',
    ) {
    }
}
