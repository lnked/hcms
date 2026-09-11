<?php

declare(strict_types=1);

namespace Cms\Core;

final class Paths
{
    public readonly string $publicDir;

    public function __construct(public readonly string $root, ?string $publicDir = null)
    {
        $this->publicDir = self::normalizePublicDir($publicDir ?? self::detectPublicDir($root));
    }

    public static function fromRoot(string $root): self
    {
        return new self($root);
    }

    public function envFile(): string
    {
        return $this->root . '/.env';
    }

    public function installedLock(): string
    {
        return $this->root . '/storage/installed.lock';
    }

    public function versionFile(): string
    {
        return $this->root . '/VERSION';
    }

    public function changelogFile(): string
    {
        return $this->root . '/changelog.json';
    }

    public function storage(): string
    {
        return $this->root . '/storage';
    }

    public function media(): string
    {
        return $this->root . '/storage/uploads';
    }

    /** Web-reachable warm cache for pretty /media/{id}/{filename} (filled on first PHP hit). */
    public function publicMedia(): string
    {
        return $this->public() . '/media';
    }

    public function cache(): string
    {
        return $this->root . '/storage/cache';
    }

    public function logs(): string
    {
        return $this->root . '/storage/logs';
    }

    public function migrations(): string
    {
        return $this->root . '/database/migrations';
    }

    public function public(): string
    {
        return $this->root . '/' . $this->publicDir;
    }

    public function adminIndex(): string
    {
        return $this->public() . '/admin/index.html';
    }

    /**
     * Safe folder name for the HTTP document root (e.g. public, public_html).
     */
    public static function normalizePublicDir(string $dir): string
    {
        $dir = trim(str_replace('\\', '/', $dir), '/');
        if ($dir === '' || str_contains($dir, '..') || str_contains($dir, '/')) {
            return 'public';
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $dir)) {
            return 'public';
        }

        return $dir;
    }

    /**
     * Common shared-hosting / local document-root folder names.
     */
    public static function isKnownWebRootName(string $name): bool
    {
        return \in_array($name, ['public', 'public_html', 'www', 'htdocs'], true);
    }

    private static function detectPublicDir(string $root): string
    {
        $envFile = $root . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                if (trim($k) === 'CMS_PUBLIC_DIR') {
                    return self::normalizePublicDir(trim($v));
                }
            }
        }

        // Prefer existing public_html when public/ is missing (shared hosting layout).
        if (!is_dir($root . '/public') && is_dir($root . '/public_html')) {
            return 'public_html';
        }

        return 'public';
    }
}
