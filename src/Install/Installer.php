<?php

declare(strict_types=1);

namespace Cms\Install;

use Cms\Auth\Password;
use Cms\Core\Locale;
use Cms\Core\Paths;
use Cms\Core\Version;
use Cms\Database\Connection;
use RuntimeException;

final class Installer
{
    /**
     * Release / project entries that must live above the HTTP document root
     * when the installer itself was unpacked into public_html (or public, …).
     *
     * @var list<string>
     */
    private const PROJECT_ROOT_ENTRIES = [
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
        'cms',
        '.env',
        '.env.example',
        'phpunit.xml',
        'phpunit.xml.dist',
        'README.md',
        'LICENSE',
        'LICENSE.md',
    ];

    public function __construct(private Paths $paths)
    {
    }

    public function isInstalled(): bool
    {
        return is_file($this->paths->installedLock());
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $srcReady = is_file($this->paths->root . '/src/bootstrap.php');
        $rootName = basename(rtrim($this->paths->root, '/\\'));
        $insideWebRoot = Paths::isKnownWebRootName($rootName);

        return [
            'installed' => $this->isInstalled(),
            'srcReady' => $srcReady,
            'version' => Version::current(),
            'requirements' => $this->requirements(),
            'installRootName' => $rootName,
            'insideWebRoot' => $insideWebRoot,
            'suggestedPublicDir' => $insideWebRoot ? $rootName : 'public',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function requirements(): array
    {
        $root = rtrim($this->paths->root, '/\\');
        $rootWritable = is_writable($root);
        $storageWritable = is_dir($this->paths->storage())
            ? is_writable($this->paths->storage())
            : $rootWritable;

        // Unpacked into hosting docroot → non-public tree must go to the parent.
        $parentWritable = true;
        if (Paths::isKnownWebRootName(basename($root))) {
            $parent = dirname($root);
            $parentWritable = is_dir($parent) && is_writable($parent);
        }

        $checks = [
            'php' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'json' => extension_loaded('json'),
            'mbstring' => extension_loaded('mbstring'),
            'zip' => extension_loaded('zip') || class_exists(\ZipArchive::class),
            'http' => function_exists('curl_init') || (bool) ini_get('allow_url_fopen'),
            'writable' => $rootWritable && $storageWritable && $parentWritable,
        ];

        return [
            'ok' => !in_array(false, $checks, true),
            'phpVersion' => PHP_VERSION,
            'checks' => $checks,
        ];
    }

    /**
     * @param array<string, mixed> $db
     * @return array{ok: bool, error?: string}
     */
    public function testConnection(array $db): array
    {
        $config = $this->normalizeDb($db);

        try {
            Connection::connect($config, $config['database'] !== '');
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true];
    }

    /**
     * Place public assets under the hosting document root and keep the rest above it when needed.
     */
    public function preparePublicLayout(string $publicDir): Paths
    {
        $publicDir = Paths::normalizePublicDir($publicDir);
        $rootName = basename(rtrim($this->paths->root, '/\\'));

        // Already sitting in a hosting docroot → always flatten using that folder name.
        if (Paths::isKnownWebRootName($rootName)) {
            $publicDir = $rootName;
        }

        $this->ensurePublicDir($publicDir);

        return $this->paths;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function complete(array $payload): void
    {
        if ($this->isInstalled()) {
            throw new RuntimeException('Already installed');
        }

        $db = $this->normalizeDb(isset($payload['database']) && is_array($payload['database']) ? $payload['database'] : []);
        $app = isset($payload['application']) && is_array($payload['application']) ? $payload['application'] : [];
        $admin = isset($payload['administrator']) && is_array($payload['administrator']) ? $payload['administrator'] : [];

        $name = isset($admin['name']) && is_string($admin['name']) ? trim($admin['name']) : '';
        $email = isset($admin['email']) && is_string($admin['email']) ? trim($admin['email']) : '';
        $password = isset($admin['password']) && is_string($admin['password']) ? $admin['password'] : '';
        $confirm = isset($admin['passwordConfirm']) && is_string($admin['passwordConfirm']) ? $admin['passwordConfirm'] : '';

        $fields = [];
        if ($name === '') {
            $fields['name'] = ['Name is required'];
        }
        if ($email === '') {
            $fields['email'] = ['Email is required'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fields['email'] = ['Enter a valid email'];
        }
        if ($password === '') {
            $fields['password'] = ['Password is required'];
        } elseif (strlen($password) < 8) {
            $fields['password'] = ['Password must be at least 8 characters'];
        }
        if ($confirm === '') {
            $fields['passwordConfirm'] = ['Please confirm the password'];
        } elseif ($password !== $confirm) {
            $fields['passwordConfirm'] = ['Passwords do not match'];
        }
        if ($fields !== []) {
            throw new ValidationException($fields);
        }

        $appName = isset($app['name']) && is_string($app['name']) ? trim($app['name']) : 'HCMS';
        $appUrl = isset($app['url']) && is_string($app['url']) ? rtrim(trim($app['url']), '/') : 'http://localhost';
        $timezone = isset($app['timezone']) && is_string($app['timezone']) ? $app['timezone'] : 'UTC';
        $language = Locale::normalize(
            isset($app['language']) && is_string($app['language']) ? $app['language'] : 'en',
        );
        $publicDir = Paths::normalizePublicDir(
            isset($app['publicDir']) && is_string($app['publicDir']) && $app['publicDir'] !== ''
                ? $app['publicDir']
                : 'public',
        );

        $paths = $this->preparePublicLayout($publicDir);
        $publicDir = $paths->publicDir;

        $connection = Connection::connect($db);
        // Lock absent but tables may remain from a partial / cleaned install.
        $this->dropCmsTables($connection);
        $this->runMigrations($connection);
        $secret = bin2hex(random_bytes(32));

        $now = date('Y-m-d H:i:s');
        $connection->execute(
            'INSERT INTO cms_users (name, email, password_hash, status, created_at, updated_at)
             VALUES (:name, :email, :hash, :status, :created_at, :updated_at)',
            [
                'name' => $name,
                'email' => $email,
                'hash' => Password::hash($password),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $settings = [
            'app.name' => $appName,
            'app.url' => $appUrl,
            'app.timezone' => $timezone,
            'app.language' => $language,
            'app.version' => Version::current(),
            'app.public_dir' => $publicDir,
            'api.base_url' => $appUrl . '/api',
            'api.access' => [
                'unrestricted' => true,
                'allowedOrigins' => [],
            ],
            'auth.admin_token_ttl_hours' => 12,
            'security.login_max_attempts' => 5,
            'security.login_window_seconds' => 900,
            'security.rate_limit_ip_per_minute' => 120,
            'security.rate_limit_token_per_minute' => 300,
            'security.rate_limit_api_token_per_minute' => 120,
            'integrations.email' => [
                'provider' => 'resend',
                'enabled' => false,
                'fromEmail' => '',
                'fromName' => '',
                'resend' => ['apiKey' => ''],
                'postmark' => ['apiKey' => ''],
                'mailgun' => ['apiKey' => '', 'domain' => '', 'region' => 'us'],
            ],
            'db.migrations' => $this->listMigrationFiles(),
        ];
        foreach ($settings as $key => $value) {
            $connection->execute(
                'INSERT INTO cms_settings (`key`, value_json, updated_at) VALUES (?, ?, ?)',
                [
                    $key,
                    json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    $now,
                ],
            );
        }

        $this->writeEnv($db, $appUrl, $secret, $publicDir);
        $this->writeRootHtaccess($publicDir);
        $this->writeWebHtaccess($publicDir);
        $this->writeLock();
    }

    public function runMigrations(Connection $connection): void
    {
        $dir = $this->paths->migrations();
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        if ($files === []) {
            throw new RuntimeException('Migration files not found');
        }

        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);
            $statements = preg_split('/;\s*\n/', $sql) ?: [];
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }
                $connection->execRaw($statement);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function listMigrationFiles(): array
    {
        $dir = $this->paths->migrations();
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);

        return array_values(array_map('basename', $files));
    }

    /**
     * Drop leftover cms_* / res_* tables so a re-install without lock can seed cleanly.
     */
    private function dropCmsTables(Connection $connection): void
    {
        $connection->execRaw('SET FOREIGN_KEY_CHECKS=0');
        $rows = $connection->select(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND (TABLE_NAME LIKE 'cms_%' OR TABLE_NAME LIKE 'res_%')",
        );
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '' || preg_match('/^(cms|res)_[A-Za-z0-9_]+$/', $name) !== 1) {
                continue;
            }
            $connection->execRaw('DROP TABLE IF EXISTS `' . str_replace('`', '``', $name) . '`');
        }
        $connection->execRaw('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * @param array<string, mixed> $db
     * @return array{host: string, port: int, database: string, username: string, password: string, charset: string}
     */
    private function normalizeDb(array $db): array
    {
        return [
            'host' => isset($db['host']) && is_string($db['host']) ? $db['host'] : '127.0.0.1',
            'port' => isset($db['port']) ? (int) $db['port'] : 3306,
            'database' => isset($db['name']) && is_string($db['name']) ? $db['name'] : (isset($db['database']) && is_string($db['database']) ? $db['database'] : ''),
            'username' => isset($db['user']) && is_string($db['user']) ? $db['user'] : (isset($db['username']) && is_string($db['username']) ? $db['username'] : ''),
            'password' => isset($db['password']) && is_string($db['password']) ? $db['password'] : '',
            'charset' => isset($db['charset']) && is_string($db['charset']) ? $db['charset'] : 'utf8mb4',
        ];
    }

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string, charset: string} $db
     */
    private function writeEnv(array $db, string $appUrl, string $secret, string $publicDir): void
    {
        $contents = implode("\n", [
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL=' . $appUrl,
            'APP_SECRET=' . $secret,
            '',
            'DB_HOST=' . $db['host'],
            'DB_PORT=' . $db['port'],
            'DB_DATABASE=' . $db['database'],
            'DB_USERNAME=' . $db['username'],
            'DB_PASSWORD=' . $db['password'],
            'DB_CHARSET=' . $db['charset'],
            '',
            'CMS_GITHUB_REPO=lnked/hcms',
            'CMS_PUBLIC_DIR=' . $publicDir,
            '',
        ]);

        if (file_put_contents($this->paths->envFile(), $contents) === false) {
            throw new RuntimeException('Unable to write .env');
        }
    }

    private function ensurePublicDir(string $publicDir): void
    {
        $root = rtrim($this->paths->root, '/\\');

        // Already inside hosting docroot (…/public_html): keep public scripts here,
        // lift src/vendor/storage/… one level above — never create public_html/public_html.
        if (basename($root) === $publicDir) {
            $this->paths = $this->flattenIntoHostingWebRoot($publicDir);

            return;
        }

        $target = $root . '/' . $publicDir;
        $default = $root . '/public';

        if ($publicDir === 'public') {
            if (!is_dir($default)) {
                throw new RuntimeException('Missing public/ directory (download CMS files first)');
            }

            return;
        }

        if (is_dir($target)) {
            if (is_dir($default) && realpath($default) !== realpath($target)) {
                $this->mergeDirectoryContents($default, $target);
                $this->removeDirectory($default);
            }

            return;
        }

        if (is_dir($default)) {
            if (!@rename($default, $target)) {
                throw new RuntimeException('Unable to rename public/ → ' . $publicDir . '/');
            }

            return;
        }

        throw new RuntimeException('Missing web root directory (expected public/ or ' . $publicDir . '/)');
    }

    /**
     * Shared-hosting layout: install ran from …/public_html (or public).
     * Document root stays the current folder; project root becomes its parent.
     */
    private function flattenIntoHostingWebRoot(string $publicDir): Paths
    {
        $webRoot = rtrim($this->paths->root, '/\\');
        $projectRoot = dirname($webRoot);

        if ($projectRoot === $webRoot || $projectRoot === '/' || $projectRoot === '.') {
            throw new RuntimeException('Cannot place project files above ' . $publicDir . ' (no parent directory)');
        }
        if (!is_dir($projectRoot) || !is_writable($projectRoot)) {
            throw new RuntimeException(
                'Parent of ' . $publicDir . ' must be writable to store non-public CMS files',
            );
        }

        $nestedPublic = $webRoot . '/public';
        if (is_dir($nestedPublic)) {
            $this->mergeDirectoryContents($nestedPublic, $webRoot);
            $this->removeDirectory($nestedPublic);
        }

        $nestedSame = $webRoot . '/' . $publicDir;
        if (is_dir($nestedSame)) {
            $sameReal = realpath($nestedSame);
            $webReal = realpath($webRoot);
            if ($sameReal !== false && $webReal !== false && $sameReal !== $webReal) {
                $this->mergeDirectoryContents($nestedSame, $webRoot);
                $this->removeDirectory($nestedSame);
            }
        }

        foreach (self::PROJECT_ROOT_ENTRIES as $name) {
            $from = $webRoot . '/' . $name;
            if (!file_exists($from)) {
                continue;
            }
            $this->moveEntry($from, $projectRoot . '/' . $name);
        }

        if (!is_file($webRoot . '/index.php')) {
            throw new RuntimeException(
                'Missing index.php in ' . $publicDir . ' after layout flatten (download CMS files first)',
            );
        }

        return new Paths($projectRoot, $publicDir);
    }

    private function mergeDirectoryContents(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            return;
        }
        if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new RuntimeException('Unable to create directory: ' . $destination);
        }

        $items = scandir($source);
        if ($items === false) {
            throw new RuntimeException('Unable to read directory: ' . $source);
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->moveEntry($source . '/' . $item, $destination . '/' . $item);
        }
    }

    private function moveEntry(string $from, string $to): void
    {
        if (!file_exists($from)) {
            return;
        }

        if (!file_exists($to)) {
            $parent = dirname($to);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('Unable to create directory: ' . $parent);
            }
            if (!@rename($from, $to)) {
                throw new RuntimeException('Unable to move ' . basename($from) . ' → ' . $to);
            }

            return;
        }

        if (is_dir($from) && is_dir($to)) {
            $this->mergeDirectoryContents($from, $to);
            $this->removeDirectory($from);

            return;
        }

        if (is_file($from) && is_file($to)) {
            if (!@unlink($to) || !@rename($from, $to)) {
                throw new RuntimeException('Unable to replace ' . $to);
            }

            return;
        }

        throw new RuntimeException('Cannot move ' . $from . ' over conflicting path ' . $to);
    }

    private function removeDirectory(string $path): void
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
                $this->removeDirectory($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }

    private function writeRootHtaccess(string $publicDir): void
    {
        $path = $this->paths->root . '/.htaccess';
        $contents = <<<HTACCESS
<IfModule mod_rewrite.c>
    RewriteEngine On
    DirectorySlash Off

    RewriteCond %{HTTP:Authorization} .
    RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    RewriteRule ^install\\.php\$ - [L]
    RewriteRule ^clean\\.php\$ - [L]
    RewriteRule ^fix\\.php\$ - [L]
    RewriteRule ^fix2\\.php\$ - [L]
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
            throw new RuntimeException('Unable to write root .htaccess');
        }
    }

    private function writeWebHtaccess(string $publicDir): void
    {
        $path = $this->paths->root . '/' . $publicDir . '/.htaccess';
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
            throw new RuntimeException('Unable to write web root .htaccess');
        }
    }

    private function writeLock(): void
    {
        $dir = $this->paths->storage();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create storage directory');
        }

        file_put_contents(
            $this->paths->installedLock(),
            json_encode(['installedAt' => date('c'), 'version' => Version::current()], JSON_UNESCAPED_SLASHES),
        );
    }
}
