<?php

declare(strict_types=1);

/**
 * One-shot fix: sync admin SPA across public/ ↔ public_html/ ↔ admin/
 * (broken white screen when index refs assets that .htaccess serves from another folder).
 *
 * Usage:
 *   /fix2.php           → diagnose + run fix
 *   /fix2.php?dry=1     → diagnose only
 *   DELETE after use.
 */

$root = __DIR__;
$dry = ($_GET['dry'] ?? '') === '1';

header('Content-Type: text/html; charset=utf-8');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @return array<string, string>
 */
function fix2_env(string $root): array
{
    $path = $root . '/.env';
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
 * @return list<string>
 */
function fix2_candidates(string $root, string $publicDir): array
{
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

function fix2_index_ok(string $indexPath): bool
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

/**
 * @return array{ok: bool, refs: list<string>, missing: list<string>, mtime: int}
 */
function fix2_inspect(string $adminDir): array
{
    $index = $adminDir . '/index.html';
    if (!is_file($index)) {
        return ['ok' => false, 'refs' => [], 'missing' => ['index.html'], 'mtime' => 0];
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
        'ok' => $missing === [],
        'refs' => $refs,
        'missing' => $missing,
        'mtime' => (int) filemtime($index),
    ];
}

function fix2_remove_dir(string $path): void
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
            fix2_remove_dir($full);
        } else {
            @unlink($full);
        }
    }
    @rmdir($path);
}

function fix2_copy_dir(string $src, string $dest): void
{
    if (!is_dir($dest) && !mkdir($dest, 0775, true) && !is_dir($dest)) {
        throw new RuntimeException('Cannot mkdir ' . $dest);
    }
    $items = scandir($src);
    if ($items === false) {
        throw new RuntimeException('Cannot read ' . $src);
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $from = $src . '/' . $item;
        $to = $dest . '/' . $item;
        if (is_dir($from)) {
            fix2_copy_dir($from, $to);
        } elseif (!@copy($from, $to)) {
            throw new RuntimeException('Cannot copy ' . $from . ' → ' . $to);
        }
    }
}

function fix2_replace_dir(string $src, string $dest): void
{
    if (is_dir($dest)) {
        fix2_remove_dir($dest);
    }
    $parent = dirname($dest);
    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
        throw new RuntimeException('Cannot mkdir ' . $parent);
    }
    fix2_copy_dir($src, $dest);
}

function fix2_same_path(string $a, string $b): bool
{
    $ra = realpath($a);
    $rb = realpath($b);
    if ($ra !== false && $rb !== false) {
        return $ra === $rb;
    }

    return rtrim(str_replace('\\', '/', $a), '/') === rtrim(str_replace('\\', '/', $b), '/');
}

/**
 * Align root .htaccess rewrite target with CMS_PUBLIC_DIR.
 *
 * @return list<string>
 */
function fix2_htaccess(string $root, string $publicDir, bool $dry): array
{
    $log = [];
    $path = $root . '/.htaccess';
    if (!is_file($path)) {
        $log[] = '.htaccess missing — skip';

        return $log;
    }
    $raw = (string) file_get_contents($path);
    $want = 'RewriteRule ^(.*)$ ' . $publicDir . '/$1 [L]';
    if (str_contains($raw, $want) || str_contains($raw, 'RewriteRule ^(.*)$ ' . $publicDir . '/$1')) {
        $log[] = '.htaccess already rewrites to ' . $publicDir . '/';

        return $log;
    }

    // Common broken state: release template rewrites to public/ while env says public_html
    $next = preg_replace(
        '#RewriteRule\s+\^\(\.\*\)\$\s+(public|public_html)/\$1\s+\[L\]#',
        'RewriteRule ^(.*)$ ' . $publicDir . '/$1 [L]',
        $raw,
        1,
        $count,
    );
    if (!is_string($next) || $count < 1) {
        // Ensure allowlist for fix2.php + rewrite block if template-like
        if (str_contains($raw, 'RewriteRule ^public/')) {
            $next = str_replace(
                "RewriteRule ^public/ - [L]\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteRule ^(.*)$ public/$1 [L]",
                "RewriteRule ^{$publicDir}/ - [L]\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteRule ^(.*)$ {$publicDir}/\$1 [L]",
                $raw,
            );
            if ($next === $raw) {
                $log[] = '.htaccess rewrite not patched (unknown format) — admin sync still applied';

                return $log;
            }
        } else {
            $log[] = '.htaccess rewrite not patched (unknown format) — admin sync still applied';

            return $log;
        }
    }

    if (!str_contains($next, 'fix2.php')) {
        $next = str_replace(
            'RewriteRule ^clean\.php$ - [L]',
            "RewriteRule ^clean\\.php$ - [L]\n    RewriteRule ^fix2\\.php$ - [L]",
            $next,
        );
    }

    if ($dry) {
        $log[] = 'DRY: would rewrite .htaccess → ' . $publicDir . '/';

        return $log;
    }
    if (@file_put_contents($path, $next) === false) {
        $log[] = 'ERROR: cannot write .htaccess';

        return $log;
    }
    $log[] = 'Updated .htaccess rewrite → ' . $publicDir . '/';

    return $log;
}

// --- run ---
$env = fix2_env($root);
$publicDir = $env['CMS_PUBLIC_DIR'] ?? '';
if ($publicDir === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $publicDir)) {
    $publicDir = is_dir($root . '/public_html') && !is_dir($root . '/public') ? 'public_html' : 'public';
}

$version = is_file($root . '/VERSION') ? trim((string) file_get_contents($root . '/VERSION')) : '?';
$candidates = fix2_candidates($root, $publicDir);
$reports = [];
$best = null;
$bestMtime = -1;

foreach ($candidates as $dir) {
    $info = fix2_inspect($dir);
    $rel = str_replace($root . '/', '', $dir);
    $reports[] = ['dir' => $dir, 'rel' => $rel, 'info' => $info];
    if ($info['ok'] && $info['mtime'] >= $bestMtime) {
        $bestMtime = $info['mtime'];
        $best = $dir;
    }
}

$log = [];
$log[] = 'CMS root: ' . $root;
$log[] = 'VERSION: ' . $version;
$log[] = 'CMS_PUBLIC_DIR: ' . $publicDir;
$log[] = $dry ? 'Mode: DRY RUN' : 'Mode: APPLY';

if ($best === null) {
    $log[] = 'ERROR: no complete admin tree found (index.html + assets).';
} else {
    $log[] = 'Source (newest complete): ' . str_replace($root . '/', '', $best);
    foreach ($candidates as $target) {
        if (fix2_same_path($best, $target)) {
            $log[] = 'skip (source): ' . str_replace($root . '/', '', $target);
            continue;
        }
        $tInfo = fix2_inspect($target);
        $need = !$tInfo['ok'] || $tInfo['mtime'] < $bestMtime;
        if (!$need) {
            $log[] = 'ok (up to date): ' . str_replace($root . '/', '', $target);
            continue;
        }
        if ($dry) {
            $log[] = 'DRY: would sync → ' . str_replace($root . '/', '', $target);
            continue;
        }
        try {
            fix2_replace_dir($best, $target);
            $log[] = 'synced → ' . str_replace($root . '/', '', $target);
        } catch (Throwable $e) {
            $log[] = 'ERROR sync ' . str_replace($root . '/', '', $target) . ': ' . $e->getMessage();
        }
    }
}

foreach (fix2_htaccess($root, $publicDir, $dry) as $line) {
    $log[] = $line;
}

echo '<!doctype html><html><head><meta charset="utf-8"><title>HCMS fix2</title>';
echo '<style>body{font:14px/1.45 system-ui,sans-serif;max-width:920px;margin:2rem auto;padding:0 1rem}';
echo 'code,pre{font-family:ui-monospace,monospace}pre{background:#f4f4f5;padding:1rem;overflow:auto}';
echo '.ok{color:#15803d}.bad{color:#b91c1c}.muted{color:#71717a}</style></head><body>';
echo '<h1>HCMS fix2.php</h1>';
echo '<p class="muted">Sync admin SPA so <code>/admin</code> index and <code>/admin/assets/*</code> match.</p>';

echo '<h2>Trees</h2><ul>';
foreach ($reports as $row) {
    $info = $row['info'];
    $cls = $info['ok'] ? 'ok' : 'bad';
    echo '<li class="' . $cls . '"><code>' . h($row['rel']) . '</code>';
    if (!is_dir($row['dir']) && !is_file($row['dir'] . '/index.html')) {
        echo ' — missing';
    } elseif ($info['ok']) {
        echo ' — ok · mtime ' . date('c', $info['mtime']);
        if ($info['refs'] !== []) {
            echo ' · ' . h(implode(', ', $info['refs']));
        }
    } else {
        echo ' — broken';
        if ($info['missing'] !== []) {
            echo ' · missing: ' . h(implode(', ', $info['missing']));
        }
    }
    echo '</li>';
}
echo '</ul>';

echo '<h2>Log</h2><pre>' . h(implode("\n", $log)) . '</pre>';

if ($dry) {
    echo '<p><a href="/fix2.php">→ Apply fix</a></p>';
} else {
    echo '<p class="ok">Done. Open <a href="/admin">/admin</a> (hard refresh).</p>';
    echo '<p><a href="/fix2.php?dry=1">diagnose again</a></p>';
}
echo '<p class="bad">DELETE fix2.php after fixing.</p>';
echo '</body></html>';
