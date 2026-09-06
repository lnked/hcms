<?php

declare(strict_types=1);

namespace Cms\System;

use Cms\Core\Paths;

/**
 * Keeps the built admin SPA under CMS_PUBLIC_DIR/admin in sync.
 * Release zips always ship public/admin; shared hosting often uses public_html.
 */
final class AdminUiPublisher
{
    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * After unpack: publish admin into the live public dir and drop a stale legacy copy.
     */
    public function publishFromReleaseTree(): void
    {
        $live = $this->paths->public() . '/admin';
        $legacy = $this->paths->root . '/public/admin';

        if ($this->paths->publicDir === 'public') {
            return;
        }

        if (is_dir($legacy) && realpath($legacy) !== realpath($live)) {
            $this->replaceDir($legacy, $live);
            $this->removeDir($legacy);
        }
    }

    /**
     * Self-heal for installs where update wrote into public/ but SPA reads public_html/.
     *
     * @return string Absolute path to a usable admin index.html
     */
    public function resolveIndex(): string
    {
        $primary = $this->paths->adminIndex();
        $legacy = $this->paths->root . '/public/admin/index.html';

        if ($this->indexAssetsExist($primary)) {
            return $primary;
        }

        if ($legacy !== $primary && $this->indexAssetsExist($legacy)) {
            $this->publishFromReleaseTree();
            if ($this->indexAssetsExist($primary)) {
                return $primary;
            }

            return $legacy;
        }

        return $primary;
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
        $adminDir = dirname($indexPath);
        foreach ($matches[1] as $rel) {
            if (!is_file($adminDir . '/' . $rel)) {
                return false;
            }
        }

        return true;
    }

    private function replaceDir(string $src, string $dest): void
    {
        if (is_dir($dest)) {
            $this->removeDir($dest);
        }
        $parent = dirname($dest);
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
