<?php

declare(strict_types=1);

namespace Cms\System;

use Cms\Core\Config;
use Cms\Core\Paths;
use Cms\Core\PhpCli;
use Cms\Core\Settings;
use Cms\Core\Version;
use Cms\Database\Connection;
use Cms\Database\PendingMigrations;
use RuntimeException;
use ZipArchive;

final class UpdateService
{
    private const PRESERVE = [
        '.env',
        '.htaccess',
        'storage',
    ];

    /** Paths inside the release that are swapped entry by entry, not wholesale. */
    private const SPLIT_DIRS = ['public'];

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
        unset($force);

        return $this->summarize($this->latest->fetch());
    }

    /**
     * @param array<string, mixed>|null $manifest
     *
     * @return array<string, mixed>
     */
    private function summarize(?array $manifest): array
    {
        $current = Version::current();
        $latestVersion = \is_array($manifest) && isset($manifest['version']) && \is_string($manifest['version'])
            ? $manifest['version']
            : null;

        return [
            'current' => $current,
            'latest' => $latestVersion,
            'updateAvailable' => $latestVersion !== null && Version::isGreater($latestVersion, $current),
            'channel' => \is_array($manifest) ? ($manifest['channel'] ?? 'stable') : 'stable',
            'releasedAt' => \is_array($manifest) ? ($manifest['releasedAt'] ?? null) : null,
            'backupReady' => is_writable($this->paths->storage()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $manifest = $this->latest->fetch();
        $check = $this->summarize($manifest);
        $from = (string) $check['current'];
        $to = \is_string($check['latest'] ?? null) ? (string) $check['latest'] : $from;

        // The local changelog stops at the installed version, so notes about an
        // update can only come from the manifest; the local file is the fallback
        // for older manifests that ship none.
        $releases = ReleaseNotes::fromManifest($manifest);
        if ($releases === []) {
            $releases = $this->changelog->since($from);
        }
        $delta = ReleaseNotes::delta($releases, $from, $to);

        return [
            'from' => $from,
            'to' => $to,
            'updateAvailable' => (bool) $check['updateAvailable'],
            'changes' => $delta['changes'],
            'hasBreaking' => $delta['hasBreaking'],
            'migrationNotes' => $delta['migrationNotes'],
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
            return ['state' => 'idle', 'step' => null, 'progress' => 0, 'error' => null];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return \is_array($data) ? $data : ['state' => 'idle', 'step' => null, 'progress' => 0, 'error' => null];
    }

    /**
     * Validate, lock, return immediately; real work runs via {@see continueInBackground()}.
     *
     * @return array<string, mixed>
     */
    public function queue(bool $acknowledgeBreaking = false): array
    {
        $lock = $this->paths->storage() . '/update.lock';
        if (is_file($lock)) {
            throw new RuntimeException('Update already running');
        }
        $this->preflight();

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

        $job = [
            'from' => (string) $preview['from'],
            'to' => (string) $preview['to'],
        ];
        $jobFile = $this->jobFile();
        if (@file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_SLASHES)) === false) {
            @unlink($lock);

            throw new RuntimeException('Unable to write update job');
        }

        $this->writeStatus('running', 'starting', null, [
            'from' => $job['from'],
            'to' => $job['to'],
        ]);

        return $this->status();
    }

    /**
     * Finish HTTP response first, then run the armed update job.
     */
    public function continueInBackground(): void
    {
        $jobFile = $this->jobFile();
        if (!is_file($jobFile)) {
            return;
        }

        ignore_user_abort(true);
        @set_time_limit(0);

        $raw = json_decode((string) file_get_contents($jobFile), true);
        @unlink($jobFile);
        if (!\is_array($raw)) {
            $this->writeStatus('failed', 'error', 'Invalid update job');
            @unlink($this->paths->storage() . '/update.lock');

            return;
        }

        $this->executeJob($raw);
    }

    /**
     * Download → stage → verify → swap. Nothing in the live tree is touched
     * until a complete, verified copy of the new release sits on disk, and the
     * swap itself is a series of renames that a journal can undo.
     *
     * @param array<string, mixed> $job
     */
    private function executeJob(array $job): void
    {
        $lock = $this->paths->storage() . '/update.lock';
        $backupDir = null;
        $zipPath = null;
        $workDir = null;
        try {
            $to = isset($job['to']) && \is_string($job['to']) ? $job['to'] : '';
            $from = isset($job['from']) && \is_string($job['from']) ? $job['from'] : Version::current();
            if ($to === '') {
                throw new RuntimeException('Update job missing target version');
            }
            $progress = ['from' => $from, 'to' => $to];

            $this->writeStatus('running', 'backup', null, $progress);
            $backupDir = $this->backup();

            $this->writeStatus('running', 'download', null, $progress);
            $zipPath = $this->downloadRelease($to);

            $this->writeStatus('running', 'unpack', null, $progress);
            $workDir = $this->extractToWorkDir($zipPath, $to);

            $this->writeStatus('running', 'verify', null, $progress);
            $this->assertTreeUsable($workDir, 'staged release');

            $this->writeStatus('running', 'swap', null, $progress);
            $this->swapIn($workDir, $backupDir, $from, $to);
            $this->resetOpcache();
            $this->assertTreeUsable($this->paths->root, 'updated install');

            $this->writeStatus('running', 'publish', null, $progress);
            (new AdminUiPublisher($this->paths))->publishFromReleaseTree();
            $this->syncStorageGuards($workDir);

            $this->writeStatus('running', 'migrate', null, $progress);
            $this->runPendingMigrations();

            $this->writeStatus('done', 'verify', null, [
                'from' => $from,
                'to' => Version::current(),
                'backup' => $backupDir,
            ]);
        } catch (\Throwable $e) {
            $this->writeStatus('failed', 'error', $e->getMessage());
            try {
                $reverted = $this->revertSwap();
                if ($reverted || $backupDir === null) {
                    $this->writeStatus('failed', 'rolled_back', $e->getMessage(), ['backup' => $backupDir]);
                } else {
                    $this->rollback($backupDir);
                    $this->writeStatus('failed', 'rolled_back', $e->getMessage(), ['backup' => $backupDir]);
                }
                $this->resetOpcache();
            } catch (\Throwable $rollbackError) {
                $this->writeStatus(
                    'failed',
                    'rollback_failed',
                    $e->getMessage() . '; rollback: ' . $rollbackError->getMessage(),
                    ['backup' => $backupDir],
                );
            }
        } finally {
            if (\is_string($zipPath) && is_file($zipPath)) {
                @unlink($zipPath);
            }
            if (\is_string($workDir)) {
                $this->removePath(\dirname($workDir));
            }
            @unlink($lock);
            @unlink($this->jobFile());
        }
    }

    /**
     * Everything that must hold before the first byte is written, so a doomed
     * update fails while the site is still healthy.
     */
    private function preflight(): void
    {
        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            throw new RuntimeException('PHP 8.3+ required, running ' . PHP_VERSION);
        }
        // Fail before swap when shell PHP is older than the web SAPI (common on shared hosting).
        PhpCli::resolve();
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required to unpack releases');
        }

        $storage = $this->paths->storage();
        if (!is_dir($storage) || !is_writable($storage)) {
            throw new RuntimeException('storage/ is not writable — backup required');
        }
        if (!is_writable($this->paths->root)) {
            throw new RuntimeException('the install root is not writable — cannot swap files in');
        }
        foreach (['src', 'vendor', 'database', $this->publicRel()] as $rel) {
            $path = $this->paths->root . '/' . $rel;
            if (file_exists($path) && !is_writable($path)) {
                throw new RuntimeException($rel . ' is not writable — cannot replace it');
            }
        }

        $free = @disk_free_space($storage);
        if (\is_float($free) && $free > 0 && $free < 64 * 1024 * 1024) {
            throw new RuntimeException('less than 64 MB free on disk — free space before updating');
        }
    }

    /**
     * @return string Path to the extracted release tree.
     */
    private function extractToWorkDir(string $zipPath, string $version): string
    {
        $base = $this->paths->storage() . '/update-work-' . $version;
        $this->removePath($base);
        $tree = $base . '/tree';
        if (!mkdir($tree, 0775, true) && !is_dir($tree)) {
            throw new RuntimeException('Unable to create ' . $tree);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open release archive');
        }
        $extracted = $zip->extractTo($tree);
        $zip->close();
        if (!$extracted) {
            throw new RuntimeException('Unable to extract the release archive — out of disk space?');
        }

        return $tree;
    }

    private function assertTreeUsable(string $root, string $label): void
    {
        $problems = TreeVerifier::problems($root);
        if ($problems !== []) {
            throw new RuntimeException('Rejected ' . $label . ': ' . implode('; ', $problems));
        }
    }

    /**
     * Renames the staged tree in one entry at a time, journalling each step so
     * an interrupted swap can be undone by {@see revertSwap()} — including from
     * a later request, after the worker was killed mid-update.
     */
    private function swapIn(string $workDir, string $backupDir, string $from, string $to): void
    {
        $plan = [];
        foreach ($this->releaseEntries($workDir) as $relative) {
            $plan[] = [
                'staged' => $workDir . '/' . $relative,
                'dest' => $this->paths->root . '/' . $this->mapReleasePath($relative),
            ];
        }
        if ($plan === []) {
            throw new RuntimeException('Staged release is empty');
        }

        $journalFile = $this->journal();
        $journal = [
            'state' => 'swapping',
            'from' => $from,
            'to' => $to,
            'backup' => $backupDir,
            'applied' => [],
            'startedAt' => date('c'),
        ];
        $journalFile->write($journal);

        foreach ($plan as $step) {
            $dest = (string) $step['dest'];
            $old = $dest . '.old';
            $this->removePath($old);

            if (file_exists($dest) && !@rename($dest, $old)) {
                throw new RuntimeException('Unable to move ' . $dest . ' aside');
            }
            if (!@rename((string) $step['staged'], $dest)) {
                @rename($old, $dest);

                throw new RuntimeException('Unable to move the new ' . $dest . ' into place');
            }

            $journal['applied'][] = $dest;
            $journalFile->write($journal);
        }

        foreach ($journal['applied'] as $dest) {
            $this->removePath($dest . '.old');
        }
        $journalFile->clear();
    }

    /**
     * Undo a swap recorded in the journal. Safe to call when there is nothing
     * to undo.
     */
    private function revertSwap(): bool
    {
        $reverted = $this->journal()->revert();
        if ($reverted !== []) {
            $this->resetOpcache();
        }

        return $reverted !== [];
    }

    /**
     * Top-level release paths to swap, expanding the entries of
     * {@see SPLIT_DIRS} so unrelated files living next to them survive.
     *
     * @return list<string>
     */
    private function releaseEntries(string $workDir): array
    {
        $entries = [];
        foreach ($this->childNames($workDir) as $name) {
            if ($this->shouldPreserve($name)) {
                continue;
            }
            if (is_dir($workDir . '/' . $name) && \in_array($name, self::SPLIT_DIRS, true)) {
                foreach ($this->childNames($workDir . '/' . $name) as $child) {
                    $entries[] = $name . '/' . $child;
                }
                continue;
            }
            $entries[] = $name;
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function childNames(string $dir): array
    {
        $items = @scandir($dir);
        if ($items === false) {
            return [];
        }
        $names = [];
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                $names[] = $item;
            }
        }
        sort($names);

        return $names;
    }

    /**
     * storage/ is never swapped, so its hardening files are seeded when absent.
     */
    private function syncStorageGuards(string $workDir): void
    {
        foreach (['storage/.htaccess', 'storage/uploads/.htaccess'] as $rel) {
            $source = $workDir . '/' . $rel;
            $target = $this->paths->root . '/' . $rel;
            if (is_file($source) && !is_file($target) && is_dir(\dirname($target))) {
                @copy($source, $target);
            }
        }
    }

    private function resetOpcache(): void
    {
        if (\function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    private function journal(): UpdateJournal
    {
        return new UpdateJournal($this->paths->storage());
    }

    private function publicRel(): string
    {
        return $this->paths->publicDir;
    }

    /**
     * Synchronous helper (tests / CLI). Prefers queue + background in HTTP.
     *
     * @return array<string, mixed>
     */
    public function run(bool $acknowledgeBreaking = false): array
    {
        $this->queue($acknowledgeBreaking);
        $this->continueInBackground();

        return $this->status();
    }

    private function jobFile(): string
    {
        return $this->paths->storage() . '/update.job.json';
    }

    /**
     * @return list<string> Everything an update may replace, relative to root.
     */
    private function backedUpPaths(): array
    {
        return [
            'VERSION',
            'changelog.json',
            'CHANGELOG.md',
            'composer.json',
            'composer.lock',
            'install.php',
            'cms',
            'src',
            'vendor',
            'database',
            'scripts',
            $this->adminRel(),
            $this->publicRel() . '/index.php',
            $this->publicRel() . '/router.php',
        ];
    }

    private function backup(): string
    {
        $dir = $this->paths->storage() . '/backups/update-' . date('YmdHis');
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create backup directory');
        }
        foreach ($this->backedUpPaths() as $rel) {
            $src = $this->paths->root . '/' . $rel;
            if (!file_exists($src)) {
                continue;
            }
            $this->copyPath($src, $dir . '/' . $rel);
        }
        if (!is_file($dir . '/VERSION') || !is_dir($dir . '/src')) {
            throw new RuntimeException('Backup is incomplete — refusing to update');
        }

        return $dir;
    }

    private function rollback(string $backupDir): void
    {
        foreach ($this->backedUpPaths() as $rel) {
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
        $zipUrl = isset($manifest['zip']) && \is_string($manifest['zip'])
            ? $manifest['zip']
            : $base . 'cms-' . $version . '.zip';
        $expected = isset($manifest['sha256']) && \is_string($manifest['sha256'])
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
            return $publicDir . substr($relative, \strlen('public'));
        }

        return $relative;
    }

    private function shouldPreserve(string $entry): bool
    {
        return \in_array($entry, self::PRESERVE, true);
    }

    private function runPendingMigrations(): void
    {
        $script = $this->paths->root . '/scripts/apply-pending-migrations.php';
        if (!is_file($script)) {
            // Pre-0.55.2 releases: fall back to in-process apply (may be stale after swap).
            PendingMigrations::apply($this->db, $this->paths, $this->settings);

            return;
        }

        $php = PhpCli::resolve();
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($this->paths->root);
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        if ($code !== 0) {
            $detail = trim(implode("\n", $output));

            throw new RuntimeException(
                'Pending migrations failed'
                . ($detail !== '' ? ': ' . $detail : ' (exit ' . $code . ')'),
            );
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function writeStatus(string $state, ?string $step, ?string $error = null, array $extra = []): void
    {
        $payload = array_merge([
            'state' => $state,
            'step' => $step,
            'progress' => self::progressForStep($step, $state),
            'error' => $error,
            'updatedAt' => date('c'),
        ], $extra);
        @file_put_contents($this->statusFile(), json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private static function progressForStep(?string $step, string $state): int
    {
        if ($state === 'done') {
            return 100;
        }

        return match ($step) {
            'starting' => 5,
            'backup' => 15,
            'download' => 35,
            'unpack' => 50,
            'verify' => 60,
            'swap' => 70,
            'publish' => 80,
            'migrate' => 90,
            'rolled_back', 'rollback_failed', 'error' => 100,
            default => 0,
        };
    }

    private function statusFile(): string
    {
        return $this->paths->storage() . '/update-status.json';
    }

    private function copyPath(string $src, string $dest): void
    {
        if (is_file($src)) {
            $dir = \dirname($dest);
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
                $parent = \dirname($target);
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
        if (!\is_array($data)) {
            throw new RuntimeException('Invalid JSON from ' . $url);
        }

        return $data;
    }

    private function httpText(string $url): string
    {
        if (\function_exists('curl_init')) {
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
            if (!\is_string($body) || $code >= 400) {
                throw new RuntimeException('Download failed: ' . $url);
            }

            return $body;
        }

        $context = stream_context_create([
            'http' => ['timeout' => 120, 'header' => "User-Agent: hcms-updater\r\n"],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!\is_string($body)) {
            throw new RuntimeException('Download failed: ' . $url);
        }

        return $body;
    }
}
