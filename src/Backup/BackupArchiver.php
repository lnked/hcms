<?php

declare(strict_types=1);

namespace Cms\Backup;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

final class BackupArchiver
{
    public function zipDirectory(string $sourceDir, string $zipPath): void
    {
        if (!is_dir($sourceDir)) {
            throw new RuntimeException('Backup directory missing: ' . $sourceDir);
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create zip: ' . $zipPath);
        }

        $sourceDir = rtrim($sourceDir, '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $full = $file->getPathname();
            $relative = ltrim(substr($full, \strlen($sourceDir)), '/\\');
            if ($relative === '') {
                continue;
            }
            if ($file->isDir()) {
                $zip->addEmptyDir(str_replace('\\', '/', $relative));
            } elseif ($file->isFile()) {
                $zip->addFile($full, str_replace('\\', '/', $relative));
            }
        }

        $zip->close();
    }

    public function extractZip(string $zipPath, string $destDir): void
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException('Zip not found: ' . $zipPath);
        }
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new RuntimeException('Cannot create extract dir: ' . $destDir);
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Cannot open zip: ' . $zipPath);
        }
        if (!$zip->extractTo($destDir)) {
            $zip->close();

            throw new RuntimeException('Zip extract failed: ' . $zipPath);
        }
        $zip->close();
    }

    public function copyTree(string $from, string $to): int
    {
        if (!is_dir($from)) {
            return 0;
        }
        if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
            throw new RuntimeException('Cannot create uploads dir: ' . $to);
        }

        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $full = $file->getPathname();
            $relative = ltrim(substr($full, \strlen(rtrim($from, '/'))), '/\\');
            if ($relative === '') {
                continue;
            }
            $target = $to . '/' . str_replace('\\', '/', $relative);
            if ($file->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new RuntimeException('Cannot create dir: ' . $target);
                }
                continue;
            }
            $dir = \dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create dir: ' . $dir);
            }
            if (!copy($full, $target)) {
                throw new RuntimeException('Copy failed: ' . $full);
            }
            $bytes += (int) $file->getSize();
        }

        return $bytes;
    }

    public function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
