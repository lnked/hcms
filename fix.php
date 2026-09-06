<?php

declare(strict_types=1);

/**
 * One-shot layout fixer for shared hosting.
 *
 * Upload into the hosting document root (…/public_html) and open:
 *   /fix.php           → preview
 *   /fix.php?confirm=1 → apply
 *
 * Fixes nested public_html/public_html (or leftover public/):
 *   - public scripts stay in the current web folder
 *   - src / vendor / storage / .env / … move one level above
 *   - rewrites .env CMS_PUBLIC_DIR + .htaccess
 *
 * DELETE this file after a successful run.
 */

header('Content-Type: text/html; charset=utf-8');

$confirm = ($_GET['confirm'] ?? $_POST['confirm'] ?? '') === '1';

const FIX_WEB_NAMES = ['public', 'public_html', 'www', 'htdocs'];

/** @var list<string> */
const FIX_PROJECT_ENTRIES = [
    'src',
    'vendor',
    'database',
    'storage',
    'frontend',
    'tests',
    'composer.json',
    'composer.lock',
    'composer.phar',
    'VERSION',
    'changelog.json',
    'install.php',
    'clean.php',
    'fix.php',
    '.env',
    '.env.example',
    'phpunit.xml',
    'phpunit.xml.dist',
    'README.md',
    'LICENSE',
    'LICENSE.md',
];

/**
 * @return never
 */
function fix_page(string $title, string $body): void
{
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"/>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"/>'
        . '<title>' . fix_h($title) . '</title><style>'
        . 'body{margin:0;min-height:100vh;font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;'
        . 'display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box}'
        . '.box{max-width:640px;width:100%;background:#1e293b;border:1px solid #334155;border-radius:16px;padding:28px 24px}'
        . 'h1{font-size:22px;margin:0 0 8px}p{margin:0 0 12px;line-height:1.5;color:#cbd5e1;font-size:14px}'
        . 'code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}'
        . 'pre{background:#0f172a;border:1px solid #334155;border-radius:10px;padding:12px;overflow:auto;'
        . 'white-space:pre-wrap;word-break:break-word;max-height:360px}'
        . '.ok{color:#4ade80}.err{color:#f87171}.muted{color:#94a3b8}'
        . 'a.btn,button{display:inline-block;margin-top:12px;margin-right:8px;padding:10px 16px;border-radius:10px;'
        . 'background:#14b8a6;color:#042f2e;text-decoration:none;border:0;font-weight:600;cursor:pointer}'
        . 'a.secondary{background:#334155;color:#e2e8f0}'
        . '</style></head><body><div class="box"><h1>' . fix_h($title) . '</h1>' . $body . '</div></body></html>';
    exit;
}

function fix_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fix_is_web_name(string $name): bool
{
    return in_array($name, FIX_WEB_NAMES, true);
}

/**
 * @return array{webRoot: string, projectRoot: string, publicDir: string, nestedDirs: list<string>, projectEntries: list<string>, envPath: ?string, issues: list<string>}
 */
function fix_detect(string $here): array
{
    $here = rtrim(str_replace('\\', '/', $here), '/');
    $base = basename($here);
    $issues = [];
    $nestedDirs = [];
    $projectEntries = [];

    if (!fix_is_web_name($base)) {
        $issues[] = 'fix.php лежит не в public/public_html/www/htdocs (сейчас: ' . $base . '). '
            . 'Залей его в document root хостинга.';
    }

    $webRoot = $here;
    $publicDir = fix_is_web_name($base) ? $base : 'public_html';
    $projectRoot = dirname($webRoot);

    // Uploaded into nested …/public_html/public_html → work from outer web root.
    $parent = dirname($here);
    $parentBase = basename($parent);
    if (
        fix_is_web_name($base)
        && fix_is_web_name($parentBase)
        && $base === $parentBase
        && (is_dir($parent . '/src') || is_file($parent . '/.env') || is_dir($parent . '/vendor'))
    ) {
        $webRoot = $parent;
        $publicDir = $parentBase;
        $projectRoot = dirname($webRoot);
        $issues[] = 'Обнаружен вложенный ' . $base . '/' . $base . ' — исправление пойдёт от внешнего web root.';
    }

    foreach (['public', $publicDir] as $nested) {
        $path = $webRoot . '/' . $nested;
        if (!is_dir($path)) {
            continue;
        }
        $realNested = realpath($path);
        $realWeb = realpath($webRoot);
        if ($realNested !== false && $realWeb !== false && $realNested === $realWeb) {
            continue;
        }
        if (is_file($path . '/index.php') || is_dir($path . '/admin') || $nested === 'public') {
            $nestedDirs[] = $nested;
        }
    }
    $nestedDirs = array_values(array_unique($nestedDirs));

    foreach (FIX_PROJECT_ENTRIES as $name) {
        if ($name === 'fix.php') {
            continue;
        }
        if (file_exists($webRoot . '/' . $name)) {
            $projectEntries[] = $name;
        }
    }

    $envPath = null;
    foreach ([$webRoot . '/.env', $projectRoot . '/.env'] as $candidate) {
        if (is_file($candidate)) {
            $envPath = $candidate;
            break;
        }
    }

    if ($projectRoot === $webRoot || $projectRoot === '/' || $projectRoot === '.') {
        $issues[] = 'Нет родительской папки над web root — некуда поднять src/vendor.';
    } elseif (!is_writable($projectRoot)) {
        $issues[] = 'Родительская папка не writable: ' . $projectRoot;
    }

    if ($nestedDirs === [] && $projectEntries === [] && is_file($webRoot . '/index.php') && is_dir($projectRoot . '/src')) {
        $issues[] = 'Похоже, layout уже нормальный (index.php в web root, src выше).';
    }

    if ($nestedDirs === [] && $projectEntries === [] && !is_file($webRoot . '/index.php')) {
        $issues[] = 'Не найден ни nested public/, ни CMS-файлы для переноса, ни index.php.';
    }

    return [
        'webRoot' => $webRoot,
        'projectRoot' => $projectRoot,
        'publicDir' => $publicDir,
        'nestedDirs' => $nestedDirs,
        'projectEntries' => $projectEntries,
        'envPath' => $envPath,
        'issues' => $issues,
    ];
}

/**
 * @param array{webRoot: string, projectRoot: string, publicDir: string, nestedDirs: list<string>, projectEntries: list<string>, envPath: ?string} $plan
 * @return list<string>
 */
function fix_apply(array $plan): array
{
    $log = [];
    $webRoot = $plan['webRoot'];
    $projectRoot = $plan['projectRoot'];
    $publicDir = $plan['publicDir'];

    foreach ($plan['nestedDirs'] as $nested) {
        $from = $webRoot . '/' . $nested;
        if (!is_dir($from)) {
            continue;
        }
        fix_merge_dir($from, $webRoot, $log);
        fix_remove_dir($from);
        $log[] = 'flatten ' . $nested . '/ → ' . basename($webRoot) . '/';
    }

    foreach (FIX_PROJECT_ENTRIES as $name) {
        if ($name === 'fix.php') {
            continue;
        }
        $from = $webRoot . '/' . $name;
        if (!file_exists($from)) {
            continue;
        }
        $to = $projectRoot . '/' . $name;
        fix_move_entry($from, $to, $log);
        $log[] = 'lift ' . $name . ' → ' . basename($projectRoot) . '/';
    }

    if (!is_file($webRoot . '/index.php')) {
        throw new RuntimeException('После flatten нет index.php в ' . $webRoot);
    }

    $envPath = is_file($projectRoot . '/.env')
        ? $projectRoot . '/.env'
        : (is_file($webRoot . '/.env') ? $webRoot . '/.env' : null);

    if ($envPath !== null) {
        fix_patch_env($envPath, $publicDir, $log);
        if (dirname($envPath) !== $projectRoot && is_file($envPath)) {
            fix_move_entry($envPath, $projectRoot . '/.env', $log);
            $envPath = $projectRoot . '/.env';
            $log[] = 'lift .env → project root';
        }
    } else {
        $log[] = 'warn: .env не найден — CMS_PUBLIC_DIR не обновлён';
    }

    fix_write_web_htaccess($webRoot, $log);
    fix_write_root_htaccess($projectRoot, $publicDir, $log);

    if ($envPath !== null) {
        fix_patch_db_public_dir($envPath, $publicDir, $log);
    }

    // Keep fix.php out of web root if it still sits here (this running copy may stay until request ends).
    $fixInWeb = $webRoot . '/fix.php';
    if (is_file($fixInWeb) && realpath($fixInWeb) === realpath(__FILE__)) {
        $target = $projectRoot . '/fix.php';
        // Don't move ourselves mid-request on flaky FS — copy marker note instead; user deletes via UI.
        $log[] = 'remind: удали fix.php из web root после проверки';
    }

    return $log;
}

/**
 * @param list<string> $log
 */
function fix_merge_dir(string $source, string $destination, array &$log): void
{
    if (!is_dir($source)) {
        return;
    }
    if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
        throw new RuntimeException('Не удалось создать ' . $destination);
    }
    $items = scandir($source);
    if ($items === false) {
        throw new RuntimeException('Не удалось прочитать ' . $source);
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        fix_move_entry($source . '/' . $item, $destination . '/' . $item, $log);
    }
}

/**
 * @param list<string> $log
 */
function fix_move_entry(string $from, string $to, array &$log): void
{
    if (!file_exists($from)) {
        return;
    }
    if (!file_exists($to)) {
        $parent = dirname($to);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('Не удалось создать ' . $parent);
        }
        if (!@rename($from, $to)) {
            throw new RuntimeException('Не удалось переместить ' . $from . ' → ' . $to);
        }

        return;
    }
    if (is_dir($from) && is_dir($to)) {
        fix_merge_dir($from, $to, $log);
        fix_remove_dir($from);

        return;
    }
    if (is_file($from) && is_file($to)) {
        if (!@unlink($to) || !@rename($from, $to)) {
            throw new RuntimeException('Не удалось заменить ' . $to);
        }

        return;
    }

    throw new RuntimeException('Конфликт путей: ' . $from . ' vs ' . $to);
}

function fix_remove_dir(string $path): void
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
            fix_remove_dir($full);
        } else {
            @unlink($full);
        }
    }
    @rmdir($path);
}

/**
 * @param list<string> $log
 */
function fix_patch_env(string $envPath, string $publicDir, array &$log): void
{
    $raw = (string) file_get_contents($envPath);
    if (preg_match('/^CMS_PUBLIC_DIR=.*$/m', $raw) === 1) {
        $raw = preg_replace('/^CMS_PUBLIC_DIR=.*$/m', 'CMS_PUBLIC_DIR=' . $publicDir, $raw, 1) ?? $raw;
    } else {
        $raw = rtrim($raw) . "\n\nCMS_PUBLIC_DIR=" . $publicDir . "\n";
    }
    if (file_put_contents($envPath, $raw) === false) {
        throw new RuntimeException('Не удалось записать ' . $envPath);
    }
    $log[] = 'env CMS_PUBLIC_DIR=' . $publicDir;
}

/**
 * @return array<string, string>
 */
function fix_parse_env(string $path): array
{
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
 * @param list<string> $log
 */
function fix_patch_db_public_dir(string $envPath, string $publicDir, array &$log): void
{
    if (!extension_loaded('pdo_mysql')) {
        $log[] = 'db skip: нет pdo_mysql';

        return;
    }

    $env = fix_parse_env($envPath);
    $db = $env['DB_DATABASE'] ?? '';
    if ($db === '') {
        $log[] = 'db skip: нет DB_DATABASE';

        return;
    }

    try {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $env['DB_HOST'] ?? '127.0.0.1',
                (int) ($env['DB_PORT'] ?? '3306'),
                $db,
                $env['DB_CHARSET'] ?? 'utf8mb4',
            ),
            $env['DB_USERNAME'] ?? '',
            $env['DB_PASSWORD'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $stmt = $pdo->prepare(
            'UPDATE cms_settings SET value_json = ?, updated_at = ? WHERE `key` = ?',
        );
        $stmt->execute([
            json_encode($publicDir, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            date('Y-m-d H:i:s'),
            'app.public_dir',
        ]);
        if ($stmt->rowCount() === 0) {
            $ins = $pdo->prepare(
                'INSERT INTO cms_settings (`key`, value_json, updated_at) VALUES (?, ?, ?)',
            );
            $ins->execute([
                'app.public_dir',
                json_encode($publicDir, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                date('Y-m-d H:i:s'),
            ]);
        }
        $log[] = 'db app.public_dir=' . $publicDir;
    } catch (Throwable $e) {
        $log[] = 'db skip: ' . $e->getMessage();
    }
}

/**
 * @param list<string> $log
 */
function fix_write_web_htaccess(string $webRoot, array &$log): void
{
    $path = $webRoot . '/.htaccess';
    $contents = <<<'HTACCESS'
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    # Avoid /admin → /admin/ (physical admin directory).
    DirectorySlash Off

    # Pass Bearer token to PHP (CGI/Apache often drop Authorization).
    RewriteCond %{HTTP:Authorization} .
    RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Existing files (admin assets, uploads, etc.)
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteRule ^ - [L]

    # Everything else → front controller
    RewriteRule ^ index.php [L]
</IfModule>

<IfModule mod_setenvif.c>
    SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
</IfModule>

Options -Indexes

HTACCESS;

    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Не удалось записать ' . $path);
    }
    $log[] = 'write ' . basename($webRoot) . '/.htaccess';
}

/**
 * @param list<string> $log
 */
function fix_write_root_htaccess(string $projectRoot, string $publicDir, array &$log): void
{
    $path = $projectRoot . '/.htaccess';
    $contents = <<<HTACCESS
<IfModule mod_rewrite.c>
    RewriteEngine On
    DirectorySlash Off

    RewriteCond %{HTTP:Authorization} .
    RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    RewriteRule ^install\\.php\$ - [L]
    RewriteRule ^clean\\.php\$ - [L]
    RewriteRule ^fix\\.php\$ - [L]
    RewriteRule ^{$publicDir}/ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^(.*)\$ {$publicDir}/\$1 [L]
</IfModule>

Options -Indexes

<FilesMatch "^\\.env">
    Require all denied
</FilesMatch>

HTACCESS;

    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Не удалось записать ' . $path);
    }
    $log[] = 'write project .htaccess';
}

// --- main ---

$plan = fix_detect(__DIR__);

if (!$confirm) {
    $body = '<p>Текущий путь: <code>' . fix_h(__DIR__) . '</code></p>'
        . '<p>Web root: <code>' . fix_h($plan['webRoot']) . '</code></p>'
        . '<p>Project root (куда поднимется код): <code>' . fix_h($plan['projectRoot']) . '</code></p>'
        . '<p>CMS_PUBLIC_DIR → <code>' . fix_h($plan['publicDir']) . '</code></p>'
        . '<p class="muted">Вложенные web-папки: <code>'
        . fix_h($plan['nestedDirs'] === [] ? '—' : implode(', ', $plan['nestedDirs']))
        . '</code></p>'
        . '<p class="muted">Поднятие вверх: <code>'
        . fix_h($plan['projectEntries'] === [] ? '—' : implode(', ', $plan['projectEntries']))
        . '</code></p>';

    if ($plan['issues'] !== []) {
        $body .= '<p class="err">Замечания:</p><pre>' . fix_h(implode("\n", $plan['issues'])) . '</pre>';
    }

    $body .= '<p>После фикса document root хостинга должен остаться на <code>'
        . fix_h($plan['publicDir']) . '</code>. Админка: <code>/admin</code>.</p>'
        . '<a class="btn" href="?confirm=1">Исправить layout</a>'
        . '<a class="btn secondary" href="/admin">Открыть admin</a>';

    fix_page('HCMS layout fix', $body);
}

try {
    if ($plan['projectRoot'] === $plan['webRoot'] || $plan['projectRoot'] === '/') {
        throw new RuntimeException('Некорректный project root');
    }
    if (!is_writable($plan['projectRoot'])) {
        throw new RuntimeException('Parent не writable: ' . $plan['projectRoot']);
    }

    $log = fix_apply($plan);
    $self = __FILE__;
    if (is_file($self) && str_starts_with(str_replace('\\', '/', $self), $plan['webRoot'] . '/')) {
        // Best-effort: remove from docroot after success.
        @unlink($self);
        $log[] = is_file($self) ? 'warn: не удалось удалить fix.php — удали вручную' : 'unlink fix.php';
    }

    $body = '<p class="ok">Готово.</p><pre>' . fix_h(implode("\n", $log)) . '</pre>'
        . '<p>Проверь сайт и <code>/admin</code>. Если fix.php ещё лежит в web root — удали.</p>'
        . '<a class="btn" href="/admin">Открыть admin</a>'
        . '<a class="btn secondary" href="/">На сайт</a>';
    fix_page('HCMS layout fix — OK', $body);
} catch (Throwable $e) {
    fix_page(
        'HCMS layout fix — ошибка',
        '<p class="err">' . fix_h($e->getMessage()) . '</p>'
            . '<a class="btn secondary" href="?">Назад</a>',
    );
}
