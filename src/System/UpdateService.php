<?php

declare(strict_types=1);

namespace Cms\System;

use Cms\Core\Config;
use Cms\Core\Paths;
use Cms\Core\Settings;
use Cms\Core\Version;
use Cms\Database\Connection;
use RuntimeException;
use ZipArchive;

final class UpdateService
{
    private const PRESERVE = [
        '.env',
        'storage/installed.lock',
        'storage/uploads',
        'storage/logs',
        'storage/cache',
        'storage/backups',
        'storage/update.lock',
        'storage/update-status.json',
    ];

    public function __construct(
        private readonly Paths $paths,
        private readonly Config $config,
        private readonly LatestRelease $latest,
        private readonly ChangelogRepository $changelog,
        private readonly Connection $db,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function check(bool $force = false): array
    {
        if ($force) {
            $cache = $this->paths->cache() . '/latest.json';
            if (is_file($cache)) {
                @unlink($cache);
            }
        }
        $manifest = $this->latest->fetch();
        $current = Version::current();
        $latestVersion = is_array($manifest) && isset($manifest['version']) && is_string($manifest['version'])
            ? $manifest['version']
            : null;

        return [
            'current' => $current,
            'latest' => $latestVersion,
            'updateAvailable' => $latestVersion !== null && Version::isGreater($latestVersion, $current),
            'channel' => is_array($manifest) ? ($manifest['channel'] ?? 'stable') : 'stable',
            'releasedAt' => is_array($manifest) ? ($manifest['releasedAt'] ?? null) : null,
            'backupReady' => is_writable($this->paths->storage()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $check = $this->check(true);
        $from = (string) $check['current'];
        $to = is_string($check['latest'] ?? null) ? (string) $check['latest'] : $from;
        $changes = [];
        $hasBreaking = false;
        $migrationNotes = [];

        foreach ($this->changelog->since($from) as $release) {
            if (!Version::isGreater((string) $release['version'], $from)) {
                continue;
            }
            if ($to !== '' && Version::compare((string) $release['version'], $to) > 0) {
                continue;
            }
            $items = is_array($release['changes'] ?? null) ? $release['changes'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $changes[] = [
                    'version' => $release['version'],
                    'type' => $item['type'] ?? 'changed',
                    'area' => $item['area'] ?? null,
                    'text' => $item['text'] ?? '',
                    'migration' => $item['migration'] ?? null,
                ];
                if (($item['type'] ?? '') === 'breaking') {
                    $hasBreaking = true;
                    if (isset($item['migration']) && is_string($item['migration']) && $item['migration'] !== '') {
                        $migrationNotes[] = $item['migration'];
                    }
                }
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'updateAvailable' => (bool) $check['updateAvailable'],
            'changes' => $changes,
            'hasBreaking' => $hasBreaking,
            'migrationNotes' => $migrationNotes,
            'backupReady' => (bool) $check['backupReady'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $file = $this->statusFile();
        if (!is_file($file)) {
            return ['state' => 'idle', 'step' => null, 'error' => null];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : ['state' => 'idle', 'step' => null, 'error' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(bool $acknowledgeBreaking = false): array
    {
        $lock = $this->paths->storage() . '/update.lock';
        if (is_file($lock)) {
            throw new RuntimeException('Update already running');
        }
        if (!is_writable($this->paths->storage())) {
            throw new RuntimeException('storage/ is not writable — backup required');
        }

        $preview = $this->preview();
        if (!($preview['updateAvailable'] ?? false)) {
            throw new RuntimeException('No update available');
        }
        if (($preview['hasBreaking'] ?? false) && !$acknowledgeBreaking) {
            throw new RuntimeException('Breaking changes require acknowledgeBreaking=true');
        }

        if (!@file_put_contents($lock, (string) getmypid())) {
            throw new RuntimeException('Unable to acquire update lock');
        }

        $backupDir = null;
        try {
            $this->writeStatus('running', 'backup');
            $backupDir = $this->backup();

            $this->writeStatus('running', 'download');
            $version = (string) $preview['to'];
            $zipPath = $this->downloadRelease($version);

            $this->writeStatus('running', 'unpack');
            $this->unpack($zipPath);

            $this->writeStatus('running', 'migrate');
            $this->runPendingMigrations();

            $this->writeStatus('done', 'verify', null, [
                'from' => $preview['from'],
                'to' => Version::current(),
                'backup' => $backupDir,
            ]);
            @unlink($zipPath);

            return $this->status();
        } catch (\Throwable $e) {
            $this->writeStatus('failed', 'error', $e->getMessage());
            if ($backupDir !== null) {
                try {
                    $this->rollback($backupDir);
                    $this->writeStatus('failed', 'rolled_back', $e->getMessage(), ['backup' => $backupDir]);
                } catch (\Throwable $rollbackError) {
                    $this->writeStatus('failed', 'rollback_failed', $e->getMessage() . '; rollback: ' . $rollbackError->getMessage());
                }
            }
            throw $e;
        } finally {
            @unlink($lock);
        }
    }

    private function backup(): string
    {
        $dir = $this->paths->storage() . '/backups/update-' . date('YmdHis');
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create backup directory');
        }
        foreach (['VERSION', 'changelog.json', 'composer.json', 'composer.lock', 'src', $this->adminRel(), 'database'] as $rel) {
            $src = $this->paths->root . '/' . $rel;
            if (!file_exists($src)) {
                continue;
            }
            $this->copyPath($src, $dir . '/' . $rel);
        }

        return $dir;
    }

    private function rollback(string $backupDir): void
    {
        foreach (['VERSION', 'changelog.json', 'composer.json', 'composer.lock', 'src', $this->adminRel(), 'database'] as $rel) {
            $src = $backupDir . '/' . $rel;
            if (!file_exists($src)) {
                continue;
            }
            $dest = $this->paths->root . '/' . $rel;
            if (is_dir($dest)) {
                $this->removePath($dest);
            } elseif (is_file($dest)) {
                @unlink($dest);
            }
            $this->copyPath($src, $dest);
        }
    }

    private function downloadRelease(string $version): string
    {
        $base = 'https://github.com/' . $this->config->githubRepo . '/releases/latest/download/';
        $manifest = $this->httpJson($base . 'latest.json');
        $zipUrl = isset($manifest['zip']) && is_string($manifest['zip'])
            ? $manifest['zip']
            : $base . 'cms-' . $version . '.zip';
        $expected = isset($manifest['sha256']) && is_string($manifest['sha256'])
            ? strtolower(trim(preg_replace('/\s.*/', '', $manifest['sha256']) ?? $manifest['sha256']))
            : strtolower(trim($this->httpText($base . 'cms-' . $version . '.zip.sha256')));

        $tmp = $this->paths->storage() . '/cms-update-' . $version . '.zip';
        $body = $this->httpText($zipUrl);
        if (file_put_contents($tmp, $body) === false) {
            throw new RuntimeException('Unable to store release zip');
        }
        $actual = hash_file('sha256', $tmp);
        if ($actual === false || $expected === '' || !hash_equals($expected, $actual)) {
            @unlink($tmp);
            throw new RuntimeException('Release checksum mismatch');
        }

        return $tmp;
    }

    private function unpack(string $zipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open release archive');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $name === '' || str_ends_with($name, '/')) {
                continue;
            }
            $normalized = ltrim(str_replace('\\', '/', $name), '/');
            if (str_contains($normalized, '..')) {
                continue;
            }
            $normalized = $this->mapReleasePath($normalized);
            if ($this->shouldPreserve($normalized)) {
                continue;
            }
            $target = $this->paths->root . '/' . $normalized;
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                $zip->close();
                throw new RuntimeException('Unable to create ' . $dir);
            }
            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }
            $out = fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);
                continue;
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);
        }
        $zip->close();
    }

    private function adminRel(): string
    {
        return $this->paths->publicDir . '/admin';
    }

    /**
     * Release zips always ship admin under public/; remap to CMS_PUBLIC_DIR when needed.
     */
    private function mapReleasePath(string $relative): string
    {
        $publicDir = $this->paths->publicDir;
        if ($publicDir === 'public') {
            return $relative;
        }
        if ($relative === 'public' || str_starts_with($relative, 'public/')) {
            return $publicDir . substr($relative, strlen('public'));
        }

        return $relative;
    }

    private function shouldPreserve(string $relative): bool
    {
        foreach (self::PRESERVE as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    private function runPendingMigrations(): void
    {
        $appliedRaw = $this->settings->get('db.migrations');
        /** @var list<string> $applied */
        $applied = is_array($appliedRaw) ? array_values(array_map('strval', $appliedRaw)) : [];
        $dir = $this->paths->migrations();
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                if (str_starts_with($statement, '--')) {
                    continue;
                }
                $this->db->execRaw($statement);
            }
            $applied[] = $name;
        }
        $this->settings->set('db.migrations', $applied);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function writeStatus(string $state, ?string $step, ?string $error = null, array $extra = []): void
    {
        $payload = array_merge([
            'state' => $state,
            'step' => $step,
            'error' => $error,
            'updatedAt' => date('c'),
        ], $extra);
        @file_put_contents($this->statusFile(), json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function statusFile(): string
    {
        return $this->paths->storage() . '/update-status.json';
    }

    private function copyPath(string $src, string $dest): void
    {
        if (is_file($src)) {
            $dir = dirname($dest);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            copy($src, $dest);

            return;
        }
        if (!is_dir($src)) {
            return;
        }
        if (!is_dir($dest)) {
            mkdir($dest, 0775, true);
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $target = $dest . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    mkdir($parent, 0775, true);
                }
                copy($item->getPathname(), $target);
            }
        }
    }

    private function removePath(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function httpJson(string $url): array
    {
        $data = json_decode($this->httpText($url), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON from ' . $url);
        }

        return $data;
    }

    private function httpText(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init failed');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_USERAGENT => 'hcms-updater',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($body) || $code >= 400) {
                throw new RuntimeException('Download failed: ' . $url);
            }

            return $body;
        }

        $context = stream_context_create([
            'http' => ['timeout' => 120, 'header' => "User-Agent: hcms-updater\r\n"],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body)) {
            throw new RuntimeException('Download failed: ' . $url);
        }

        return $body;
    }
}
