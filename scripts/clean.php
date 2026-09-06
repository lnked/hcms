<?php

declare(strict_types=1);

/**
 * TEMP test helper — wipe install state for re-install.
 * DELETE THIS FILE after testing.
 *
 * Usage (from project root):
 *   php scripts/clean.php              → status + confirm prompt
 *   php scripts/clean.php --confirm    → run wipe
 *   # or via browser if scripts/ is exposed:
 *   /scripts/clean.php?confirm=1
 */

$root = dirname(__DIR__);
$envFile = $root . '/.env';
$lockFile = $root . '/storage/installed.lock';
$confirm = ($_GET['confirm'] ?? $_POST['confirm'] ?? '') === '1'
    || (PHP_SAPI === 'cli' && in_array('--confirm', $argv ?? [], true));

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
 * @return list<string> Absolute admin dir candidates (public/admin, public_html/admin, admin/, …)
 */
function clean_admin_candidates(string $root, array $env = []): array
{
    $publicDir = $env['CMS_PUBLIC_DIR'] ?? '';
    if (!is_string($publicDir) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $publicDir)) {
        $publicDir = is_dir($root . '/public_html') && !is_dir($root . '/public') ? 'public_html' : 'public';
    }

    $dirs = [
        $root . '/' . $publicDir . '/admin',
        $root . '/public/admin',
        $root . '/public_html/admin',
        $root . '/admin',
    ];

    $unique = [];
    $seen = [];
    foreach ($dirs as $dir) {
        $key = rtrim(str_replace('\\', '/', $dir), '/');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $dir;
    }

    return $unique;
}

/**
 * @return array{exists: bool, ok: bool, refs: list<string>, missing: list<string>, mtime: int}
 */
function clean_inspect_admin(string $adminDir): array
{
    $index = $adminDir . '/index.html';
    if (!is_file($index)) {
        return [
            'exists' => is_dir($adminDir),
            'ok' => false,
            'refs' => [],
            'missing' => is_dir($adminDir) ? ['index.html'] : [],
            'mtime' => 0,
        ];
    }
    $html = (string) file_get_contents($index);
    $refs = [];
    if (preg_match_all('#/admin/(assets/[^"\']+)#', $html, $matches)) {
        $refs = array_values(array_unique($matches[1]));
    }
    $missing = [];
    foreach ($refs as $rel) {
        if (!is_file($adminDir . '/' . $rel)) {
            $missing[] = $rel;
        }
    }

    return [
        'exists' => true,
        'ok' => $missing === [],
        'refs' => $refs,
        'missing' => $missing,
        'mtime' => (int) filemtime($index),
    ];
}

function clean_rmtree(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $removed = 0;
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
            $removed++;
        }
    }
    @rmdir($dir);

    return $removed;
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
        clean_rmtree($dir);
        $log[] = 'rmtree ' . $dirName;
    }

    // All possible admin SPA locations (public/ vs public_html/ vs docroot admin/)
    foreach (clean_admin_candidates($root, $env) as $adminDir) {
        if (!is_dir($adminDir) && !is_file($adminDir . '/index.html')) {
            continue;
        }
        $n = clean_rmtree($adminDir);
        $log[] = 'rmtree ' . str_replace($root . '/', '', $adminDir) . ' (' . $n . ' files)';
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
$adminReports = [];
foreach (clean_admin_candidates($root, $env) as $adminDir) {
    $info = clean_inspect_admin($adminDir);
    if (!$info['exists'] && $info['missing'] === []) {
        continue;
    }
    $adminReports[] = [
        'rel' => str_replace($root . '/', '', $adminDir),
        'info' => $info,
    ];
}

if (!$confirm) {
    echo '<!doctype html><html><head><meta charset="utf-8"/><title>HCMS clean (TEMP)</title>';
    echo '<style>body{font-family:ui-sans-serif,system-ui;max-width:720px;margin:40px auto;padding:0 16px}';
    echo '.warn{background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;margin:16px 0}';
    echo '.ok{color:#15803d}.bad{color:#b91c1c}.muted{color:#71717a;font-size:13px}';
    echo 'code{font-size:12px}button{background:#18181b;color:#fff;border:0;border-radius:8px;padding:10px 14px;cursor:pointer}</style></head><body>';
    echo '<h1>HCMS clean.php (TEMP)</h1>';
    echo '<div class="warn"><strong>Test only.</strong> Drops the entire MySQL database from .env and recreates it empty, then deletes .env, installed.lock, uploads/backups, package dirs, and <em>all</em> admin SPA copies (<code>public/admin</code>, <code>public_html/admin</code>, <code>admin/</code>) so install can re-run clean. Delete this file after testing.</div>';
    echo '<p>.env: ' . ($hasEnv ? 'yes' : 'no');
    if ($hasEnv && isset($env['CMS_PUBLIC_DIR'])) {
        echo ' · CMS_PUBLIC_DIR=<code>' . h($env['CMS_PUBLIC_DIR']) . '</code>';
    }
    echo '</p>';
    echo '<p>installed.lock: ' . ($hasLock ? 'yes' : 'no') . '</p>';
    echo '<p>DB: <strong>' . h($env['DB_DATABASE'] ?? '(none)') . '</strong> @ ' . h($env['DB_HOST'] ?? '-') . ' — will be <code>DROP DATABASE</code> + <code>CREATE DATABASE</code></p>';

    echo '<h2>Admin SPA trees</h2>';
    if ($adminReports === []) {
        echo '<p class="muted">No admin trees found.</p>';
    } else {
        echo '<ul>';
        foreach ($adminReports as $row) {
            $info = $row['info'];
            $cls = $info['ok'] ? 'ok' : 'bad';
            echo '<li class="' . $cls . '"><code>' . h($row['rel']) . '</code>';
            if ($info['ok']) {
                echo ' — ok';
                if ($info['refs'] !== []) {
                    echo ' · ' . h(implode(', ', $info['refs']));
                }
            } elseif ($info['exists']) {
                echo ' — broken';
                if ($info['missing'] !== []) {
                    echo ' · missing ' . h(implode(', ', $info['missing']));
                }
            } else {
                echo ' — empty/missing index';
            }
            echo '</li>';
        }
        echo '</ul>';
        echo '<p class="muted">Clean removes every listed tree so a fresh install cannot leave a stale <code>public_html/admin</code> vs <code>public/admin</code> split.</p>';
    }

    echo '<form method="post"><input type="hidden" name="confirm" value="1"/>';
    echo '<button type="submit">Wipe DB + install state</button></form>';
    echo '<p><a href="/install.php">install.php</a> · <a href="/admin">/admin</a></p>';
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        echo '<p class="muted">CLI: re-run with <code>--confirm</code> to wipe.</p>';
    }
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
echo '<p><a href="/install.php">→ install.php</a> · <a href="/scripts/clean.php">clean again</a></p>';
echo '<p class="err">DELETE scripts/clean.php when done testing.</p>';
echo '</body></html>';
