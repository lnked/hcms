<?php

declare(strict_types=1);

namespace Cms\Backup;

use Cms\Core\Paths;
use Cms\Core\Version;
use Cms\Database\Connection;
use RuntimeException;
use Throwable;

final class DataBackupService
{
    private readonly BackupArchiver $archiver;
    private readonly SqlDumper $dumper;
    private readonly SqlRestorer $restorer;

    public function __construct(
        private readonly Paths $paths,
        private readonly Connection $db,
        private readonly BackupRemoteSettings $remoteSettings,
        private readonly RemoteDriverFactory $drivers,
    ) {
        $this->archiver = new BackupArchiver();
        $this->dumper = new SqlDumper($db);
        $this->restorer = new SqlRestorer($db);
    }

    public function backupsDir(): string
    {
        return $this->paths->storage() . '/backups';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $dir = $this->backupsDir();
        if (!is_dir($dir)) {
            return [];
        }
        $items = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (!str_starts_with($name, 'data-') || str_starts_with($name, 'data-pre-restore-')) {
                continue;
            }
            $path = $dir . '/' . $name;
            if (!is_dir($path)) {
                continue;
            }
            $manifest = $this->readManifest($path);
            $zip = $path . '.zip';
            $items[] = [
                'id' => $name,
                'createdAt' => $manifest['createdAt'] ?? null,
                'appVersion' => $manifest['appVersion'] ?? null,
                'tables' => $manifest['tables'] ?? [],
                'mediaBytes' => $manifest['mediaBytes'] ?? 0,
                'destinations' => $manifest['destinations'] ?? [],
                'hasZip' => is_file($zip),
                'sizeBytes' => $this->dirSize($path) + (is_file($zip) ? (int) filesize($zip) : 0),
            ];
        }
        usort(
            $items,
            static fn (array $a, array $b): int => strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? '')),
        );

        return $items;
    }

    /**
     * @param list<string> $pushTo
     * @return array<string, mixed>
     */
    public function create(array $pushTo = []): array
    {
        $this->assertWritable();
        $id = 'data-' . gmdate('Ymd\THis');
        $dir = $this->backupsDir() . '/' . $id;
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create backup directory');
        }

        $this->writeStatus('running', 'dump', null, ['id' => $id]);

        try {
            $tables = $this->dumper->dumpToFile($dir . '/database.sql');
            $this->writeStatus('running', 'media', null, ['id' => $id]);
            $mediaBytes = $this->archiver->copyTree($this->paths->media(), $dir . '/uploads');

            $manifest = [
                'id' => $id,
                'createdAt' => gmdate('c'),
                'appVersion' => Version::current(),
                'phpVersion' => PHP_VERSION,
                'tables' => $tables,
                'mediaBytes' => $mediaBytes,
                'destinations' => [],
            ];
            $this->writeManifest($dir, $manifest);

            $this->writeStatus('running', 'archive', null, ['id' => $id]);
            $zipPath = $this->backupsDir() . '/' . $id . '.zip';
            $this->archiver->zipDirectory($dir, $zipPath);

            $pushed = [];
            $errors = [];
            foreach ($pushTo as $provider) {
                $provider = strtolower(trim($provider));
                if (!\in_array($provider, BackupRemoteSettings::ALL_PROVIDERS, true)) {
                    continue;
                }
                $this->writeStatus('running', 'push:' . $provider, null, ['id' => $id]);
                try {
                    $driver = $this->drivers->make($provider);
                    if (!$driver->connected()) {
                        throw new RuntimeException($provider . ' is not connected');
                    }
                    $driver->upload($zipPath, $id . '.zip');
                    $pushed[] = $provider;
                } catch (Throwable $e) {
                    $errors[$provider] = $e->getMessage();
                }
            }

            $manifest['destinations'] = $pushed;
            if ($errors !== []) {
                $manifest['pushErrors'] = $errors;
            }
            $this->writeManifest($dir, $manifest);

            $this->enforceRetention();
            $this->writeStatus('done', 'complete', null, [
                'id' => $id,
                'destinations' => $pushed,
                'pushErrors' => $errors,
            ]);

            return [
                'id' => $id,
                'destinations' => $pushed,
                'pushErrors' => $errors,
                'tables' => \count($tables),
                'mediaBytes' => $mediaBytes,
            ];
        } catch (Throwable $e) {
            $this->archiver->removeTree($dir);
            @unlink($this->backupsDir() . '/' . $id . '.zip');
            $this->writeStatus('failed', 'error', $e->getMessage(), ['id' => $id]);

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function push(string $id, string $provider): array
    {
        $id = $this->assertBackupId($id);
        $zipPath = $this->backupsDir() . '/' . $id . '.zip';
        if (!is_file($zipPath)) {
            $dir = $this->backupsDir() . '/' . $id;
            if (!is_dir($dir)) {
                throw new RuntimeException('Backup not found: ' . $id);
            }
            $this->archiver->zipDirectory($dir, $zipPath);
        }
        $driver = $this->drivers->make($provider);
        if (!$driver->connected()) {
            throw new RuntimeException($provider . ' is not connected');
        }
        $driver->upload($zipPath, $id . '.zip');
        $dir = $this->backupsDir() . '/' . $id;
        $manifest = $this->readManifest($dir);
        $dest = $manifest['destinations'] ?? [];
        if (!\is_array($dest)) {
            $dest = [];
        }
        if (!\in_array($provider, $dest, true)) {
            $dest[] = $provider;
        }
        $manifest['destinations'] = array_values($dest);
        $this->writeManifest($dir, $manifest);

        return ['id' => $id, 'provider' => $provider];
    }

    /**
     * @return array{id: string, safetyBackup: string}
     */
    public function restore(string $id, bool $confirm): array
    {
        if (!$confirm) {
            throw new RuntimeException('Restore requires confirm=true');
        }
        $id = $this->assertBackupId($id);
        $dir = $this->backupsDir() . '/' . $id;
        if (!is_dir($dir) || !is_file($dir . '/database.sql')) {
            $zip = $this->backupsDir() . '/' . $id . '.zip';
            if (!is_file($zip)) {
                throw new RuntimeException('Backup not found: ' . $id);
            }
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot extract backup');
            }
            $this->archiver->extractZip($zip, $dir);
        }

        $this->writeStatus('running', 'pre-restore-backup', null, ['id' => $id]);
        $safety = $this->createSafetySnapshot();

        try {
            $this->writeStatus('running', 'restore-sql', null, ['id' => $id, 'safety' => $safety]);
            $this->restorer->restoreFromFile($dir . '/database.sql');

            $uploadsSrc = $dir . '/uploads';
            if (is_dir($uploadsSrc)) {
                $this->writeStatus('running', 'restore-media', null, ['id' => $id]);
                $media = $this->paths->media();
                $this->archiver->removeTree($media);
                $this->archiver->copyTree($uploadsSrc, $media);
            }

            $this->writeStatus('done', 'restored', null, ['id' => $id, 'safety' => $safety]);

            return ['id' => $id, 'safetyBackup' => $safety];
        } catch (Throwable $e) {
            $this->writeStatus('failed', 'restore-error', $e->getMessage(), [
                'id' => $id,
                'safety' => $safety,
            ]);

            throw $e;
        }
    }

    public function delete(string $id): void
    {
        $id = $this->assertBackupId($id);
        $this->archiver->removeTree($this->backupsDir() . '/' . $id);
        @unlink($this->backupsDir() . '/' . $id . '.zip');
    }

    public function zipPath(string $id): string
    {
        $id = $this->assertBackupId($id);
        $zip = $this->backupsDir() . '/' . $id . '.zip';
        if (!is_file($zip)) {
            $dir = $this->backupsDir() . '/' . $id;
            if (!is_dir($dir)) {
                throw new RuntimeException('Backup not found: ' . $id);
            }
            $this->archiver->zipDirectory($dir, $zip);
        }

        return $zip;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $file = $this->statusFile();
        if (!is_file($file)) {
            return ['state' => 'idle'];
        }
        $json = json_decode((string) file_get_contents($file), true);

        return \is_array($json) ? $json : ['state' => 'idle'];
    }

    /**
     * Create backup asynchronously via shutdown (for HTTP).
     *
     * @param list<string> $pushTo
     * @return array{state: string, pushTo: list<string>}
     */
    public function enqueueCreate(array $pushTo = []): array
    {
        $lock = $this->paths->storage() . '/backup.lock';
        if (is_file($lock)) {
            $pid = (int) trim((string) file_get_contents($lock));
            if ($pid > 0 && $this->pidAlive($pid)) {
                throw new RuntimeException('A backup job is already running');
            }
        }
        file_put_contents($lock, (string) getmypid());
        $this->writeStatus('queued', 'starting', null, ['pushTo' => $pushTo]);

        $service = $this;
        register_shutdown_function(static function () use ($service, $pushTo, $lock): void {
            try {
                $service->create($pushTo);
            } catch (Throwable) {
                // status already written
            } finally {
                @unlink($lock);
            }
        });

        return ['state' => 'queued', 'pushTo' => $pushTo];
    }

    private function createSafetySnapshot(): string
    {
        $id = 'data-pre-restore-' . gmdate('Ymd\THis');
        $dir = $this->backupsDir() . '/' . $id;
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create safety backup');
        }
        $tables = $this->dumper->dumpToFile($dir . '/database.sql');
        $mediaBytes = $this->archiver->copyTree($this->paths->media(), $dir . '/uploads');
        $this->writeManifest($dir, [
            'id' => $id,
            'createdAt' => gmdate('c'),
            'appVersion' => Version::current(),
            'phpVersion' => PHP_VERSION,
            'tables' => $tables,
            'mediaBytes' => $mediaBytes,
            'destinations' => [],
            'kind' => 'pre-restore',
        ]);

        return $id;
    }

    private function enforceRetention(): void
    {
        $keep = $this->remoteSettings->raw()['retention'];
        $all = [];
        foreach ($this->list() as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id !== '') {
                $all[] = $id;
            }
        }
        if (\count($all) <= $keep) {
            return;
        }
        foreach (\array_slice($all, $keep) as $old) {
            $this->delete($old);
        }
    }

    private function assertWritable(): void
    {
        $dir = $this->backupsDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create backups directory');
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('Backups directory is not writable');
        }
    }

    private function assertBackupId(string $id): string
    {
        $id = trim($id);
        if (preg_match('/^data-(pre-restore-)?\d{8}T\d{6}$/', $id) !== 1) {
            throw new RuntimeException('Invalid backup id');
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $dir): array
    {
        $file = $dir . '/manifest.json';
        if (!is_file($file)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($file), true);

        return \is_array($json) ? $json : [];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(string $dir, array $manifest): void
    {
        file_put_contents(
            $dir . '/manifest.json',
            json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}',
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function writeStatus(string $state, ?string $step, ?string $error, array $extra = []): void
    {
        $payload = array_merge([
            'state' => $state,
            'step' => $step,
            'error' => $error,
            'updatedAt' => gmdate('c'),
        ], $extra);
        @file_put_contents($this->statusFile(), json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function statusFile(): string
    {
        return $this->paths->storage() . '/backup-status.json';
    }

    private function dirSize(string $dir): int
    {
        $size = 0;
        if (!is_dir($dir)) {
            return 0;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $size += (int) $file->getSize();
            }
        }

        return $size;
    }

    private function pidAlive(int $pid): bool
    {
        if (\function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return false;
    }
}
