<?php

declare(strict_types=1);

/**
 * TEMP test helper — wipe install state for re-install.
 * DELETE THIS FILE after testing.
 *
 * Usage:
 *   /clean.php              → status + confirm button
 *   /clean.php?confirm=1    → run wipe (also accepts POST confirm=1)
 */

$root = __DIR__;
$envFile = $root . '/.env';
$lockFile = $root . '/storage/installed.lock';
$confirm = ($_GET['confirm'] ?? $_POST['confirm'] ?? '') === '1';

header('Content-Type: text/html; charset=utf-8');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @return array<string, string>
 */
function clean_parse_env(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v);
    }

    return $out;
}

/**
 * @param array<string, string> $env
 * @return list<string>
 */
function clean_wipe_db(array $env): array
{
    $log = [];
    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $port = (int) ($env['DB_PORT'] ?? '3306');
    $db = $env['DB_DATABASE'] ?? '';
    $user = $env['DB_USERNAME'] ?? 'root';
    $pass = $env['DB_PASSWORD'] ?? '';
    $charset = $env['DB_CHARSET'] ?? 'utf8mb4';
    $collation = $env['DB_COLLATION'] ?? 'utf8mb4_unicode_ci';

    if ($db === '') {
        $log[] = 'DB skip: no DB_DATABASE in .env';

        return $log;
    }

    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $db)) {
        throw new RuntimeException('Refusing to wipe DB with unsafe name: ' . $db);
    }

    $charsetIdent = preg_match('/^[A-Za-z0-9_]+$/', $charset) ? $charset : 'utf8mb4';
    $collationIdent = preg_match('/^[A-Za-z0-9_]+$/', $collation) ? $collation : 'utf8mb4_unicode_ci';
    $ident = '`' . str_replace('`', '``', $db) . '`';

    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charsetIdent),
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    } catch (PDOException $e) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $db, $charsetIdent),
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $log[] = 'DB: connected with dbname (server-level connect failed)';
    }

    $schema = $pdo->quote($db);

    $objects = $pdo->query(
        'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES'
        . " WHERE TABLE_SCHEMA = {$schema}"
        . ' ORDER BY TABLE_NAME',
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($objects as $row) {
        $log[] = 'found ' . (string) $row['TABLE_TYPE'] . ' ' . (string) $row['TABLE_NAME'];
    }
    if ($objects === []) {
        $log[] = 'DB: no tables/views before wipe';
    }

    $recreated = false;
    try {
        $pdo->exec('DROP DATABASE IF EXISTS ' . $ident);
        $pdo->exec(
            'CREATE DATABASE ' . $ident
            . ' CHARACTER SET ' . $charsetIdent
            . ' COLLATE ' . $collationIdent,
        );
        $pdo->exec('USE ' . $ident);
        $log[] = 'DROP DATABASE ' . $db;
        $log[] = 'CREATE DATABASE ' . $db . ' (' . $charsetIdent . '/' . $collationIdent . ')';
        $recreated = true;
    } catch (PDOException $e) {
        $log[] = 'DROP/CREATE DATABASE denied — falling back to object wipe: ' . $e->getMessage();
        $pdo->exec('USE ' . $ident);
    }

    if (!$recreated) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $left = $pdo->query(
            'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES'
            . " WHERE TABLE_SCHEMA = {$schema}",
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($left as $row) {
            $name = (string) $row['TABLE_NAME'];
            $safe = '`' . str_replace('`', '``', $name) . '`';
            if ((string) $row['TABLE_TYPE'] === 'VIEW') {
                $pdo->exec('DROP VIEW IF EXISTS ' . $safe);
                $log[] = 'DROP VIEW ' . $name;
            } else {
                $pdo->exec('DROP TABLE IF EXISTS ' . $safe);
                $log[] = 'DROP TABLE ' . $name;
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $routines = $pdo->query(
            'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES'
            . " WHERE ROUTINE_SCHEMA = {$schema}",
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($routines as $row) {
            $name = (string) $row['ROUTINE_NAME'];
            $safe = '`' . str_replace('`', '``', $name) . '`';
            $kind = strtoupper((string) $row['ROUTINE_TYPE']) === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
            $pdo->exec('DROP ' . $kind . ' IF EXISTS ' . $safe);
            $log[] = 'DROP ' . $kind . ' ' . $name;
        }

        $events = $pdo->query(
            'SELECT EVENT_NAME FROM information_schema.EVENTS'
            . " WHERE EVENT_SCHEMA = {$schema}",
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($events as $name) {
            $event = (string) $name;
            $safe = '`' . str_replace('`', '``', $event) . '`';
            $pdo->exec('DROP EVENT IF EXISTS ' . $safe);
            $log[] = 'DROP EVENT ' . $event;
        }
    }

    $remaining = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES'
        . " WHERE TABLE_SCHEMA = {$schema}",
    )->fetchColumn();
    $log[] = $remaining === 0
        ? 'DB empty — ready for install'
        : 'DB warning: ' . $remaining . ' object(s) still present';

    return $log;
}

/**
 * @param array<string, string> $env
 * @return list<string>
 */
function clean_wipe_files(string $root, array $env = []): array
{
    $log = [];
    $paths = [
        $root . '/.env',
        $root . '/storage/installed.lock',
        $root . '/storage/update.lock',
        $root . '/storage/update.job.json',
        $root . '/storage/update-status.json',
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            @unlink($path);
            $log[] = 'unlink ' . str_replace($root . '/', '', $path);
        }
    }

    // temp release zips / update artifacts
    foreach (glob($root . '/storage/cms-*.zip') ?: [] as $zip) {
        @unlink($zip);
        $log[] = 'unlink ' . str_replace($root . '/', '', $zip);
    }
    foreach (glob($root . '/storage/cms-update-*.zip') ?: [] as $zip) {
        @unlink($zip);
        $log[] = 'unlink ' . str_replace($root . '/', '', $zip);
    }

    // optional: clear uploads + backups from previous test installs
    foreach ([$root . '/storage/uploads', $root . '/storage/backups', $root . '/storage/cache'] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $p = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($p);
            } else {
                if (str_ends_with($p, '.gitkeep')) {
                    continue;
                }
                @unlink($p);
                $log[] = 'unlink ' . str_replace($root . '/', '', $p);
            }
        }
    }

    // Package files from release zip — wipe so next install re-downloads (fixes stale Installer HY093).
    foreach (['src', 'vendor', 'database'] as $dirName) {
        $dir = $root . '/' . $dirName;
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $p = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
        $log[] = 'rmtree ' . $dirName;
    }

    $publicDirs = [];
    $fromEnv = $env['CMS_PUBLIC_DIR'] ?? '';
    if (is_string($fromEnv) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $fromEnv)) {
        $publicDirs[] = $fromEnv;
    }
    foreach (['public', 'public_html'] as $candidate) {
        if (!in_array($candidate, $publicDirs, true)) {
            $publicDirs[] = $candidate;
        }
    }

    foreach ($publicDirs as $publicDir) {
        $adminDir = $root . '/' . $publicDir . '/admin';
        if (!is_dir($adminDir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($adminDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $p = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($adminDir);
        $log[] = 'rmtree ' . $publicDir . '/admin';
    }

    foreach ([$root . '/VERSION', $root . '/changelog.json', $root . '/composer.json', $root . '/composer.lock'] as $path) {
        if (is_file($path)) {
            @unlink($path);
            $log[] = 'unlink ' . str_replace($root . '/', '', $path);
        }
    }

    return $log;
}

$env = clean_parse_env($envFile);
$hasLock = is_file($lockFile);
$hasEnv = is_file($envFile);

if (!$confirm) {
    echo '<!doctype html><html><head><meta charset="utf-8"/><title>HCMS clean (TEMP)</title>';
    echo '<style>body{font-family:ui-sans-serif,system-ui;max-width:640px;margin:40px auto;padding:0 16px}';
    echo '.warn{background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;margin:16px 0}';
    echo 'button{background:#18181b;color:#fff;border:0;border-radius:8px;padding:10px 14px;cursor:pointer}</style></head><body>';
    echo '<h1>HCMS clean.php (TEMP)</h1>';
    echo '<div class="warn"><strong>Test only.</strong> Drops the entire MySQL database from .env and recreates it empty, then deletes .env, installed.lock, uploads/backups, and package dirs so install can re-run clean. Delete this file after testing.</div>';
    echo '<p>.env: ' . ($hasEnv ? 'yes' : 'no') . '</p>';
    echo '<p>installed.lock: ' . ($hasLock ? 'yes' : 'no') . '</p>';
    echo '<p>DB: <strong>' . h($env['DB_DATABASE'] ?? '(none)') . '</strong> @ ' . h($env['DB_HOST'] ?? '-') . ' — will be <code>DROP DATABASE</code> + <code>CREATE DATABASE</code></p>';
    echo '<form method="post"><input type="hidden" name="confirm" value="1"/>';
    echo '<button type="submit">Wipe DB + install state</button></form>';
    echo '<p><a href="/install.php">install.php</a> · <a href="/admin">/admin</a></p>';
    echo '</body></html>';
    exit;
}

$log = [];
try {
    if ($hasEnv) {
        $log = array_merge($log, clean_wipe_db($env));
    } else {
        $log[] = 'DB skip: no .env (tables not dropped)';
    }
    $log = array_merge($log, clean_wipe_files($root, $env));
    $ok = true;
    $error = null;
} catch (Throwable $e) {
    $ok = false;
    $error = $e->getMessage();
}

echo '<!doctype html><html><head><meta charset="utf-8"/><title>HCMS cleaned</title>';
echo '<style>body{font-family:ui-sans-serif,system-ui;max-width:640px;margin:40px auto;padding:0 16px}';
echo 'pre{background:#f4f4f5;padding:12px;border-radius:8px;overflow:auto;font-size:12px}';
echo '.ok{color:#15803d}.err{color:#b91c1c}</style></head><body>';
echo '<h1 class="' . ($ok ? 'ok' : 'err') . '">' . ($ok ? 'Cleaned' : 'Failed') . '</h1>';
if ($error !== null) {
    echo '<p class="err">' . h($error) . '</p>';
}
echo '<pre>' . h(implode("\n", $log === [] ? ['(nothing to do)'] : $log)) . '</pre>';
echo '<p><a href="/install.php">→ install.php</a> · <a href="/clean.php">clean again</a></p>';
echo '<p class="err">DELETE clean.php when done testing.</p>';
echo '</body></html>';
