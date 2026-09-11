<?php

declare(strict_types=1);

namespace Cms\System;

use Cms\Core\Paths;

/**
 * Keeps the built admin SPA reachable for both PHP spa() and static /admin/* rewrites.
 *
 * Release zips ship public/admin. Hosting may use CMS_PUBLIC_DIR=public_html while
 * root .htaccess still rewrites to public/ — both trees must stay in sync.
 */
final class AdminUiPublisher
{
    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * After unpack / on spa heal: sync the newest complete admin tree to every live candidate.
     */
    public function publishFromReleaseTree(): void
    {
        $dirs = $this->candidateAdminDirs();
        $source = $this->newestCompleteAdmin($dirs);
        if ($source === null) {
            return;
        }

        foreach ($dirs as $target) {
            if ($this->samePath($source, $target)) {
                continue;
            }
            if ($this->shouldReplace($source, $target)) {
                $this->replaceDir($source, $target);
            }
        }
    }

    /**
     * @return string Absolute path to a usable admin index.html
     */
    public function resolveIndex(): string
    {
        $this->publishFromReleaseTree();

        $primary = $this->paths->adminIndex();
        if ($this->indexAssetsExist($primary)) {
            return $primary;
        }

        foreach ($this->candidateAdminDirs() as $dir) {
            $index = $dir . '/index.html';
            if ($this->indexAssetsExist($index)) {
                return $index;
            }
        }

        return $primary;
    }

    /**
     * @return list<string>
     */
    private function candidateAdminDirs(): array
    {
        $dirs = [
            $this->paths->public() . '/admin',
            $this->paths->root . '/public/admin',
            $this->paths->root . '/public_html/admin',
            $this->paths->root . '/admin',
        ];

        $unique = [];
        $seen = [];
        foreach ($dirs as $dir) {
            $key = $this->normalizePath($dir);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $dir;
        }

        return $unique;
    }

    /**
     * @param list<string> $dirs
     */
    private function newestCompleteAdmin(array $dirs): ?string
    {
        $best = null;
        $bestMtime = -1;
        foreach ($dirs as $dir) {
            $index = $dir . '/index.html';
            if (!$this->indexAssetsExist($index)) {
                continue;
            }
            $mtime = (int) filemtime($index);
            if ($mtime >= $bestMtime) {
                $bestMtime = $mtime;
                $best = $dir;
            }
        }

        return $best;
    }

    private function shouldReplace(string $source, string $target): bool
    {
        $sourceIndex = $source . '/index.html';
        $targetIndex = $target . '/index.html';
        if (!$this->indexAssetsExist($sourceIndex)) {
            return false;
        }
        if (!$this->indexAssetsExist($targetIndex)) {
            return true;
        }

        return filemtime($sourceIndex) > filemtime($targetIndex);
    }

    private function indexAssetsExist(string $indexPath): bool
    {
        if (!is_file($indexPath)) {
            return false;
        }
        $html = (string) file_get_contents($indexPath);
        if (!preg_match_all('#/admin/(assets/[^"\']+)#', $html, $matches)) {
            return true;
        }
        $adminDir = \dirname($indexPath);
        foreach ($matches[1] as $rel) {
            if (!is_file($adminDir . '/' . $rel)) {
                return false;
            }
        }

        return true;
    }

    private function samePath(string $a, string $b): bool
    {
        $ra = realpath($a);
        $rb = realpath($b);
        if ($ra !== false && $rb !== false) {
            return $ra === $rb;
        }

        return $this->normalizePath($a) === $this->normalizePath($b);
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function replaceDir(string $src, string $dest): void
    {
        if (is_dir($dest)) {
            $this->removeDir($dest);
        }
        $parent = \dirname($dest);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            return;
        }
        $this->copyDir($src, $dest);
    }

    private function copyDir(string $src, string $dest): void
    {
        if (!is_dir($dest) && !mkdir($dest, 0775, true) && !is_dir($dest)) {
            return;
        }
        $items = scandir($src);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $src . '/' . $item;
            $to = $dest . '/' . $item;
            if (is_dir($from)) {
                $this->copyDir($from, $to);
            } else {
                @copy($from, $to);
            }
        }
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeDir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
