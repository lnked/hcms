<?php

declare(strict_types=1);

namespace Cms\Core;

/**
 * Configurable admin UI base path. Filesystem stays at {public}/admin/;
 * this only changes the public URL prefix.
 *
 * Variant A:
 * - UI at {uiBase} ('' = site root)
 * - Admin API at {uiBase}/api, except root UI → /admin/api
 */
final class AdminBase
{
    public const INTERNAL_API = '/admin/api';

    public const ASSET_PREFIX = '/admin';

    private const RESERVED = [
        'api',
        'media',
        'install',
        'install.php',
        'assets',
        'vendor',
        'storage',
        'index.php',
        'router.php',
    ];

    /**
     * @param string $ui '' | '/admin' | '/panel' — no trailing slash
     */
    private function __construct(private readonly string $ui)
    {
    }

    public static function default(): self
    {
        return new self('/admin');
    }

    public static function fromEnv(Env $env): self
    {
        return self::fromRaw($env->get('CMS_ADMIN_BASE', 'admin') ?? 'admin');
    }

    /**
     * Accepts "admin", "/admin", "panel", "", "/".
     * Invalid values fall back to /admin (boot must not die on a typo).
     */
    public static function fromRaw(?string $raw): self
    {
        $parsed = self::tryNormalize($raw ?? 'admin');
        if ($parsed['ok'] === false) {
            return self::default();
        }

        return new self($parsed['value']);
    }

    /**
     * @return array{ok: true, value: string}|array{ok: false, error: string}
     */
    public static function tryNormalize(?string $raw): array
    {
        if ($raw === null) {
            return ['ok' => false, 'error' => 'Admin base is required'];
        }

        $raw = trim($raw);
        if ($raw === '' || $raw === '/') {
            return ['ok' => true, 'value' => ''];
        }

        $raw = strtolower(trim($raw, '/'));
        if ($raw === '') {
            return ['ok' => true, 'value' => ''];
        }

        if (str_contains($raw, '/') || str_contains($raw, '\\')) {
            return ['ok' => false, 'error' => 'Use a single path segment (e.g. admin, panel) or empty for root'];
        }

        if (!preg_match('/^[a-z][a-z0-9_-]{0,47}$/', $raw)) {
            return ['ok' => false, 'error' => 'Invalid admin base (a-z, 0-9, _-; start with a letter)'];
        }

        if (\in_array($raw, self::RESERVED, true)) {
            return ['ok' => false, 'error' => 'Reserved path: ' . $raw];
        }

        return ['ok' => true, 'value' => '/' . $raw];
    }

    /** UI prefix: '' or '/panel'. */
    public function ui(): string
    {
        return $this->ui;
    }

    /** Env / form value: 'admin', 'panel', or ''. */
    public function segment(): string
    {
        return $this->ui === '' ? '' : ltrim($this->ui, '/');
    }

    /**
     * Admin API prefix under the chosen UI base.
     * Root UI keeps /admin/api so it never collides with public /api/{slug}.
     */
    public function apiPrefix(): string
    {
        return $this->ui === '' ? self::INTERNAL_API : $this->ui . '/api';
    }

    public function path(string $suffix = ''): string
    {
        $suffix = $suffix === '' ? '' : '/' . ltrim($suffix, '/');
        if ($this->ui === '') {
            return $suffix === '' ? '/' : $suffix;
        }

        return $this->ui . $suffix;
    }

    public function loginPath(): string
    {
        return $this->path('/login');
    }

    public function healthPath(): string
    {
        return $this->apiPrefix() . '/health';
    }

    public function healthUrl(string $appUrl): string
    {
        return rtrim($appUrl, '/') . $this->healthPath();
    }

    public function isSpaPath(string $path): bool
    {
        if ($this->ui === '') {
            return !$this->isReservedRootPath($path);
        }

        if ($path === $this->ui) {
            return true;
        }

        if (!str_starts_with($path, $this->ui . '/')) {
            return false;
        }

        return !str_starts_with($path, $this->apiPrefix());
    }

    /**
     * Map public admin API URL onto internal /admin/api/* routes.
     */
    public function canonicalize(string $path): string
    {
        $prefix = $this->apiPrefix();
        if ($prefix === self::INTERNAL_API) {
            return $path;
        }

        if ($path === $prefix) {
            return self::INTERNAL_API;
        }

        if (str_starts_with($path, $prefix . '/')) {
            return self::INTERNAL_API . substr($path, \strlen($prefix));
        }

        return $path;
    }

    /**
     * Old /admin UI URLs → current base (assets + API stay on /admin/*).
     */
    public function legacyRedirect(string $path): ?string
    {
        if ($this->ui === '/admin') {
            return null;
        }

        if ($path === self::ASSET_PREFIX || str_starts_with($path, self::ASSET_PREFIX . '/')) {
            if ($path === self::INTERNAL_API || str_starts_with($path, self::INTERNAL_API . '/')) {
                return null;
            }
            if ($path === self::ASSET_PREFIX . '/assets' || str_starts_with($path, self::ASSET_PREFIX . '/assets/')) {
                return null;
            }
            // Static leftovers under /admin/* (favicon, etc.) — do not redirect.
            if ($this->looksLikeStaticAdminFile($path)) {
                return null;
            }

            $rest = $path === self::ASSET_PREFIX ? '' : substr($path, \strlen(self::ASSET_PREFIX));

            return $this->path($rest);
        }

        return null;
    }

    /**
     * @return array{adminBase: string, uiBase: string, apiPrefix: string}
     */
    public function toPublicArray(): array
    {
        return [
            'adminBase' => $this->segment(),
            'uiBase' => $this->ui,
            'apiPrefix' => $this->apiPrefix(),
        ];
    }

    private function isReservedRootPath(string $path): bool
    {
        if ($path === '/install.php' || str_starts_with($path, '/install.php/')) {
            return true;
        }
        if ($path === '/api' || str_starts_with($path, '/api/')) {
            return true;
        }
        if ($path === '/media' || str_starts_with($path, '/media/')) {
            return true;
        }
        // Physical admin tree: API, assets, and other static files.
        if ($path === self::ASSET_PREFIX || str_starts_with($path, self::ASSET_PREFIX . '/')) {
            return true;
        }

        return false;
    }

    private function looksLikeStaticAdminFile(string $path): bool
    {
        if ($path === self::ASSET_PREFIX . '/index.html') {
            return true;
        }

        $name = basename($path);
        if ($name === '' || $name === 'admin') {
            return false;
        }

        return str_contains($name, '.');
    }
}
