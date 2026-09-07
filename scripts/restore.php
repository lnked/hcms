<?php

declare(strict_types=1);

/**
 * Emergency recovery for a broken install — the one tool that must keep working
 * when everything else is down.
 *
 * Deliberately standalone: no Composer autoloader, no Cms\* classes, no
 * database. It repairs the very things those depend on, so it duplicates a
 * little logic from Cms\System\UpdateService on purpose.
 *
 * CLI:
 *   php scripts/restore.php                     # diagnose (default)
 *   php scripts/restore.php token               # print the browser token
 *   php scripts/restore.php fix-autoload        # repair a mis-generated vendor/
 *   php scripts/restore.php backups
 *   php scripts/restore.php restore [--backup=latest|<name>]
 *   php scripts/restore.php reinstall [--version=latest|x.y.z]
 *   php scripts/restore.php unlock
 *
 * Browser (when there is no shell): upload this file anywhere in the install
 * and open it with the token, e.g.
 *   https://site/restore.php?action=diagnose&token=…
 *
 * .env, storage/ (uploads, logs, backups) and the install lock are never
 * touched by any action.
 */

const CMS_RESTORE_PRESERVE = [
    '.env',
    '.htaccess',
    'storage',
];

const CMS_RESTORE_ACTIONS = ['diagnose', 'token', 'backups', 'restore', 'reinstall', 'fix-autoload', 'unlock'];

$cli = PHP_SAPI === 'cli';
$options = $cli ? cms_restore_cli_options($argv) : cms_restore_web_options();
$action = $options['action'];

if (!in_array($action, CMS_RESTORE_ACTIONS, true)) {
    cms_restore_fail('Unknown action "' . $action . '". Known: ' . implode(', ', CMS_RESTORE_ACTIONS), $cli);
}

$root = cms_restore_root($options['root'] ?? null);
if ($root === null) {
    cms_restore_fail('Cannot locate the install root (no VERSION + src/ above ' . __DIR__ . ')', $cli);
}

if ($action === 'token') {
    $token = cms_restore_expected_token($root);
    cms_restore_fail_unless($cli, 'token can only be printed from the CLI');
    fwrite(STDOUT, ($token ?? 'unavailable: set APP_SECRET in .env or write storage/restore.token') . "\n");
    exit(0);
}

if (!$cli) {
    $expected = cms_restore_expected_token($root);
    if ($expected === null) {
        cms_restore_fail('Browser access needs a token: set APP_SECRET in .env or write storage/restore.token', false, 403);
    }
    if (!hash_equals($expected, (string) ($options['token'] ?? ''))) {
        cms_restore_fail('Invalid token. Run "php scripts/restore.php token" to print it.', false, 403);
    }
}

$result = match ($action) {
    'diagnose' => cms_restore_diagnose($root),
    'backups' => ['backups' => cms_restore_backups($root)],
    'restore' => cms_restore_from_backup($root, (string) ($options['backup'] ?? 'latest')),
    'reinstall' => cms_restore_reinstall($root, (string) ($options['version'] ?? 'latest')),
    'fix-autoload' => cms_restore_fix_autoload($root),
    'unlock' => cms_restore_unlock($root),
};

cms_restore_output($action, $root, $result, $cli);

// ---------------------------------------------------------------- actions

/**
 * @return array<string, mixed>
 */
function cms_restore_diagnose(string $root): array
{
    $publicDir = cms_restore_public_dir($root);
    $problems = [];

    foreach (['VERSION', 'src/bootstrap.php', 'src/Http/Kernel.php', $publicDir . '/index.php'] as $rel) {
        if (!is_file($root . '/' . $rel)) {
            $problems[] = 'missing ' . $rel;
        } elseif (filesize($root . '/' . $rel) === 0) {
            $problems[] = 'empty ' . $rel;
        }
    }

    $autoload = cms_restore_autoload_state($root);
    if ($autoload['broken']) {
        $problems[] = 'vendor autoloader resolves outside the install (' . $autoload['sample'] . ') — run fix-autoload';
    }
    if (!is_file($root . '/src/autoload.php')) {
        $problems[] = 'src/autoload.php missing — this version still depends on a healthy vendor/';
    }
    foreach (['', '/storage', '/storage/backups'] as $rel) {
        $dir = $root . $rel;
        if ($rel !== '' && !is_dir($dir)) {
            continue;
        }
        if (!is_writable($dir)) {
            $problems[] = 'not writable: ' . ($rel === '' ? '.' : ltrim($rel, '/'));
        }
    }
    $zeroByte = cms_restore_zero_byte_files($root . '/src');
    foreach ($zeroByte as $file) {
        $problems[] = 'zero-byte source file: ' . $file;
    }
    if (is_file($root . '/storage/update.lock')) {
        $problems[] = 'update.lock present — an update is running or died; run unlock';
    }

    return [
        'php' => PHP_VERSION,
        'phpSupported' => PHP_VERSION_ID >= 80300,
        'root' => $root,
        'publicDir' => $publicDir,
        'version' => cms_restore_version($root),
        'autoload' => $autoload,
        'updateStatus' => cms_restore_json_file($root . '/storage/update-status.json'),
        'updateJournal' => cms_restore_json_file($root . '/storage/update-journal.json'),
        'backups' => cms_restore_backups($root),
        'recentFatals' => cms_restore_tail($root . '/storage/logs/php-fatal.log', 20),
        'problems' => $problems,
        'healthy' => $problems === [],
    ];
}

/**
 * Repairs the failure mode where vendor/ was generated at the wrong depth, so
 * every generated path points above the install root.
 *
 * @return array<string, mixed>
 */
function cms_restore_fix_autoload(string $root): array
{
    $dir = $root . '/vendor/composer';
    if (!is_dir($dir)) {
        return ['patched' => [], 'note' => 'no vendor/composer — nothing to repair'];
    }

    $patched = [];
    foreach (['autoload_classmap.php', 'autoload_psr4.php', 'autoload_namespaces.php', 'autoload_files.php'] as $name) {
        $file = $dir . '/' . $name;
        if (!is_file($file)) {
            continue;
        }
        $before = (string) file_get_contents($file);
        $after = preg_replace(
            '/\$baseDir\s*=\s*dirname\((?:dirname\()+\$vendorDir\)+\);/',
            '$baseDir = dirname($vendorDir);',
            $before,
        );
        if (is_string($after) && $after !== $before && file_put_contents($file, $after) !== false) {
            $patched[] = 'vendor/composer/' . $name;
        }
    }

    $static = $dir . '/autoload_static.php';
    if (is_file($static)) {
        $before = (string) file_get_contents($static);
        $after = str_replace("__DIR__ . '/../../..'", "__DIR__ . '/../..'", $before);
        if ($after !== $before && file_put_contents($static, $after) !== false) {
            $patched[] = 'vendor/composer/autoload_static.php';
        }
    }

    cms_restore_reset_opcache();
    $state = cms_restore_autoload_state($root);

    return [
        'patched' => $patched,
        'autoload' => $state,
        'healthy' => !$state['broken'],
    ];
}

/**
 * @return array<string, mixed>
 */
function cms_restore_from_backup(string $root, string $which): array
{
    $backups = cms_restore_backups($root);
    if ($backups === []) {
        cms_restore_fail('No backups in storage/backups — use reinstall instead', PHP_SAPI === 'cli');
    }
    $name = $which === 'latest' ? (string) $backups[count($backups) - 1]['name'] : $which;
    $dir = $root . '/storage/backups/' . basename($name);
    if (!is_dir($dir)) {
        cms_restore_fail('Backup not found: ' . $name, PHP_SAPI === 'cli');
    }

    $restored = [];
    foreach (cms_restore_children($dir) as $entry) {
        if (cms_restore_is_preserved($entry)) {
            continue;
        }
        cms_restore_replace($dir . '/' . $entry, $root . '/' . $entry);
        $restored[] = $entry;
    }
    cms_restore_reset_opcache();

    return [
        'backup' => $name,
        'restored' => $restored,
        'version' => cms_restore_version($root),
        'healthy' => cms_restore_diagnose($root)['healthy'],
    ];
}

/**
 * @return array<string, mixed>
 */
function cms_restore_reinstall(string $root, string $version): array
{
    $repo = cms_restore_env($root, 'CMS_GITHUB_REPO') ?? 'lnked/hcms';
    $base = 'https://github.com/' . $repo . '/releases/';
    $manifestUrl = $version === 'latest'
        ? $base . 'latest/download/latest.json'
        : $base . 'download/v' . $version . '/latest.json';

    $manifest = json_decode(cms_restore_http($manifestUrl), true);
    if (!is_array($manifest) || !isset($manifest['zip'], $manifest['version'])) {
        cms_restore_fail('Release manifest unusable: ' . $manifestUrl, PHP_SAPI === 'cli');
    }

    $target = (string) $manifest['version'];
    $zipPath = $root . '/storage/restore-' . $target . '.zip';
    if (file_put_contents($zipPath, cms_restore_http((string) $manifest['zip'])) === false) {
        cms_restore_fail('Cannot write ' . $zipPath, PHP_SAPI === 'cli');
    }

    $expected = isset($manifest['sha256']) && is_string($manifest['sha256']) ? strtolower(trim($manifest['sha256'])) : '';
    $actual = (string) hash_file('sha256', $zipPath);
    if ($expected !== '' && !hash_equals($expected, $actual)) {
        @unlink($zipPath);
        cms_restore_fail('Checksum mismatch for ' . $manifest['zip'], PHP_SAPI === 'cli');
    }

    $work = $root . '/storage/restore-work-' . $target;
    cms_restore_remove($work);
    if (!mkdir($work, 0775, true) && !is_dir($work)) {
        cms_restore_fail('Cannot create ' . $work, PHP_SAPI === 'cli');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true || !$zip->extractTo($work)) {
        $zip->close();
        cms_restore_remove($work);
        @unlink($zipPath);
        cms_restore_fail('Cannot extract the release archive', PHP_SAPI === 'cli');
    }
    $zip->close();
    @unlink($zipPath);

    $publicDir = cms_restore_public_dir($root);
    $swapped = [];
    foreach (cms_restore_children($work) as $entry) {
        if (cms_restore_is_preserved($entry)) {
            continue;
        }
        $dest = $entry === 'public' && $publicDir !== 'public' ? $publicDir : $entry;
        cms_restore_replace($work . '/' . $entry, $root . '/' . $dest);
        $swapped[] = $dest;
    }
    cms_restore_remove($work);
    cms_restore_reset_opcache();

    $diagnose = cms_restore_diagnose($root);

    return [
        'version' => $target,
        'replaced' => $swapped,
        'healthy' => $diagnose['healthy'],
        'problems' => $diagnose['problems'],
    ];
}

/**
 * @return array<string, mixed>
 */
function cms_restore_unlock(string $root): array
{
    $cleared = [];
    foreach (['update.lock', 'update.job.json', 'update-journal.json'] as $name) {
        $file = $root . '/storage/' . $name;
        if (is_file($file) && @unlink($file)) {
            $cleared[] = 'storage/' . $name;
        }
    }
    @file_put_contents($root . '/storage/update-status.json', json_encode([
        'state' => 'failed',
        'step' => 'error',
        'progress' => 100,
        'error' => 'Cleared by scripts/restore.php',
        'updatedAt' => date('c'),
    ], JSON_UNESCAPED_SLASHES));

    return ['cleared' => $cleared];
}

// ---------------------------------------------------------------- helpers

/**
 * @return array<string, mixed>
 */
function cms_restore_autoload_state(string $root): array
{
    $classmap = $root . '/vendor/composer/autoload_classmap.php';
    if (!is_file($classmap)) {
        return ['present' => false, 'broken' => false, 'sample' => null];
    }
    $map = @include $classmap;
    if (!is_array($map) || $map === []) {
        return ['present' => true, 'broken' => true, 'sample' => 'class map is empty'];
    }
    foreach ($map as $class => $file) {
        if (is_string($file) && !is_file($file)) {
            return ['present' => true, 'broken' => true, 'sample' => $class . ' => ' . $file];
        }
    }

    return ['present' => true, 'broken' => false, 'sample' => null];
}

/**
 * @return list<array<string, mixed>>
 */
function cms_restore_backups(string $root): array
{
    $dir = $root . '/storage/backups';
    if (!is_dir($dir)) {
        return [];
    }
    $backups = [];
    foreach (cms_restore_children($dir) as $entry) {
        if (!is_dir($dir . '/' . $entry)) {
            continue;
        }
        $backups[] = [
            'name' => $entry,
            'createdAt' => date('c', (int) filemtime($dir . '/' . $entry)),
            'version' => cms_restore_version($dir . '/' . $entry),
            'hasVendor' => is_dir($dir . '/' . $entry . '/vendor'),
        ];
    }

    return $backups;
}

/**
 * @return list<string>
 */
function cms_restore_children(string $dir): array
{
    $items = @scandir($dir);
    if ($items === false) {
        return [];
    }
    $out = [];
    foreach ($items as $item) {
        if ($item !== '.' && $item !== '..') {
            $out[] = $item;
        }
    }
    sort($out);

    return $out;
}

function cms_restore_is_preserved(string $entry): bool
{
    return in_array($entry, CMS_RESTORE_PRESERVE, true);
}

/**
 * Swap a path in with as small a window as possible: stage next to the target,
 * rename the old one aside, rename the new one in, then drop the old copy.
 */
function cms_restore_replace(string $src, string $dest): void
{
    if (is_file($src)) {
        if (@copy($src, $dest . '.new') && @rename($dest . '.new', $dest)) {
            return;
        }
        @unlink($dest . '.new');
        cms_restore_fail('Cannot write ' . $dest, PHP_SAPI === 'cli');
    }
    if (!is_dir($src)) {
        return;
    }

    $staged = $dest . '.new';
    cms_restore_remove($staged);
    cms_restore_copy($src, $staged);

    $old = $dest . '.old';
    cms_restore_remove($old);
    if (is_dir($dest) && !@rename($dest, $old)) {
        cms_restore_remove($staged);
        cms_restore_fail('Cannot move ' . $dest . ' aside', PHP_SAPI === 'cli');
    }
    if (!@rename($staged, $dest)) {
        @rename($old, $dest);
        cms_restore_remove($staged);
        cms_restore_fail('Cannot move the new ' . $dest . ' into place', PHP_SAPI === 'cli');
    }
    cms_restore_remove($old);
}

function cms_restore_copy(string $src, string $dest): void
{
    if (is_file($src)) {
        @mkdir(dirname($dest), 0775, true);
        @copy($src, $dest);

        return;
    }
    if (!is_dir($src)) {
        return;
    }
    if (!is_dir($dest) && !@mkdir($dest, 0775, true) && !is_dir($dest)) {
        return;
    }
    foreach (cms_restore_children($src) as $entry) {
        cms_restore_copy($src . '/' . $entry, $dest . '/' . $entry);
    }
}

function cms_restore_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        @unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (cms_restore_children($path) as $entry) {
        cms_restore_remove($path . '/' . $entry);
    }
    @rmdir($path);
}

/**
 * @return list<string>
 */
function cms_restore_zero_byte_files(string $dir, int $limit = 5): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getSize() === 0 && str_ends_with($file->getFilename(), '.php')) {
            $found[] = $file->getPathname();
            if (count($found) >= $limit) {
                break;
            }
        }
    }

    return $found;
}

function cms_restore_root(?string $override): ?string
{
    $candidates = [];
    if ($override !== null && $override !== '') {
        $candidates[] = $override;
    }
    $dir = __DIR__;
    for ($i = 0; $i < 4; $i++) {
        $candidates[] = $dir;
        $dir = dirname($dir);
    }
    foreach ($candidates as $candidate) {
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
        if (is_file($candidate . '/VERSION') && is_dir($candidate . '/src')) {
            return $candidate;
        }
    }

    return null;
}

function cms_restore_public_dir(string $root): string
{
    $configured = cms_restore_env($root, 'CMS_PUBLIC_DIR');
    if (is_string($configured) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $configured)) {
        return $configured;
    }
    if (!is_dir($root . '/public') && is_dir($root . '/public_html')) {
        return 'public_html';
    }

    return 'public';
}

function cms_restore_env(string $root, string $key): ?string
{
    $file = $root . '/.env';
    if (!is_file($file)) {
        return null;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        if (trim($name) === $key) {
            return trim($value, " \t\"'");
        }
    }

    return null;
}

function cms_restore_expected_token(string $root): ?string
{
    $file = $root . '/storage/restore.token';
    if (is_file($file)) {
        $token = trim((string) file_get_contents($file));
        if ($token !== '') {
            return $token;
        }
    }
    $secret = cms_restore_env($root, 'APP_SECRET');
    if (is_string($secret) && $secret !== '') {
        return hash('sha256', 'hcms-restore:' . $secret);
    }

    return null;
}

function cms_restore_version(string $root): ?string
{
    $file = $root . '/VERSION';

    return is_file($file) ? trim((string) file_get_contents($file)) : null;
}

/**
 * @return array<string, mixed>|null
 */
function cms_restore_json_file(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? $data : null;
}

/**
 * @return list<string>
 */
function cms_restore_tail(string $file, int $lines): array
{
    if (!is_file($file)) {
        return [];
    }
    $all = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return array_values(array_slice($all, -$lines));
}

function cms_restore_http(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_USERAGENT => 'hcms-restore',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (is_string($body) && $code < 400) {
                return $body;
            }
        }
        cms_restore_fail('Download failed: ' . $url, PHP_SAPI === 'cli');
    }

    $body = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 180, 'header' => "User-Agent: hcms-restore\r\n"],
    ]));
    if (!is_string($body)) {
        cms_restore_fail('Download failed: ' . $url, PHP_SAPI === 'cli');
    }

    return $body;
}

function cms_restore_reset_opcache(): void
{
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
}

/**
 * @param list<string> $argv
 *
 * @return array<string, string>
 */
function cms_restore_cli_options(array $argv): array
{
    $options = ['action' => 'diagnose'];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
            $options[$key] = (string) $value;
            continue;
        }
        $options['action'] = $arg;
    }

    return $options;
}

/**
 * @return array<string, string>
 */
function cms_restore_web_options(): array
{
    $options = ['action' => 'diagnose'];
    foreach (['action', 'token', 'backup', 'version', 'root'] as $key) {
        if (isset($_GET[$key]) && is_string($_GET[$key])) {
            $options[$key] = $_GET[$key];
        }
    }

    return $options;
}

/**
 * @param array<string, mixed> $result
 *
 * @return never
 */
function cms_restore_output(string $action, string $root, array $result, bool $cli)
{
    $payload = ['action' => $action, 'root' => $root] + $result;
    if (!$cli) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit(($result['healthy'] ?? true) === false ? 1 : 0);
}

/**
 * @return never
 */
function cms_restore_fail(string $message, bool $cli, int $status = 500)
{
    if ($cli) {
        fwrite(STDERR, 'restore: ' . $message . "\n");
        exit(1);
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function cms_restore_fail_unless(bool $condition, string $message): void
{
    if (!$condition) {
        cms_restore_fail($message, false, 403);
    }
}
