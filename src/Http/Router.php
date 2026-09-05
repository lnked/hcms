<?php

declare(strict_types=1);

namespace Cms\Http;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * @param callable(Request, array<string, string>, \Cms\Auth\AuthContext|null): Response $handler
     */
    public function add(
        string $method,
        string $pattern,
        callable $handler,
        bool $public = false,
        string $auth = 'admin',
    ): void {
        $this->routes[] = new Route(strtoupper($method), $pattern, $handler, $public, $auth);
    }

    /**
     * @return array{route: Route, params: array<string, string>}|null
     */
    public function match(Request $request): ?array
    {
        foreach ($this->routes as $route) {
            if ($route->method !== $request->method && $route->method !== 'ANY') {
                continue;
            }

            $params = $this->matchPattern($route->pattern, $request->path);
            if ($params === null) {
                continue;
            }

            return ['route' => $route, 'params' => $params];
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private function matchPattern(string $pattern, string $path): ?array
    {
        $pattern = Request::normalizePath($pattern);
        if ($pattern === $path) {
            return [];
        }

        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $pattern);
        if (!is_string($regex)) {
            return null;
        }

        if (!preg_match('#^' . $regex . '$#', $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
