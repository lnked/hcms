<?php

declare(strict_types=1);

namespace Cms\Core;

final class Paths
{
    public function __construct(public readonly string $root)
    {
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
        return $this->root . '/public';
    }

    public function adminIndex(): string
    {
        return $this->root . '/public/admin/index.html';
    }
}
