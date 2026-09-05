<?php

declare(strict_types=1);

namespace Cms\Install;

use Cms\Auth\Password;
use Cms\Core\Paths;
use Cms\Core\Version;
use Cms\Database\Connection;
use RuntimeException;

final class Installer
{
    public function __construct(private readonly Paths $paths)
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

        return [
            'installed' => $this->isInstalled(),
            'srcReady' => $srcReady,
            'version' => Version::current(),
            'requirements' => $this->requirements(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function requirements(): array
    {
        $rootWritable = is_writable($this->paths->root);
        $storageWritable = is_dir($this->paths->storage())
            ? is_writable($this->paths->storage())
            : is_writable($this->paths->root);

        $checks = [
            'php' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'json' => extension_loaded('json'),
            'mbstring' => extension_loaded('mbstring'),
            'zip' => extension_loaded('zip') || class_exists(\ZipArchive::class),
            'http' => function_exists('curl_init') || (bool) ini_get('allow_url_fopen'),
            'writable' => $rootWritable && $storageWritable,
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

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || $password !== $confirm) {
            throw new RuntimeException('Invalid administrator data');
        }

        $appName = isset($app['name']) && is_string($app['name']) ? trim($app['name']) : 'HCMS';
        $appUrl = isset($app['url']) && is_string($app['url']) ? rtrim(trim($app['url']), '/') : 'http://localhost';
        $timezone = isset($app['timezone']) && is_string($app['timezone']) ? $app['timezone'] : 'UTC';
        $language = isset($app['language']) && is_string($app['language']) ? $app['language'] : 'en';

        $connection = Connection::connect($db);
        $this->runMigrations($connection);
        $secret = bin2hex(random_bytes(32));

        $connection->execute(
            'INSERT INTO cms_users (name, email, password_hash, status, created_at, updated_at)
             VALUES (:name, :email, :hash, :status, :now, :now)',
            [
                'name' => $name,
                'email' => $email,
                'hash' => Password::hash($password),
                'status' => 'active',
                'now' => date('Y-m-d H:i:s'),
            ],
        );

        $settings = [
            'app.name' => $appName,
            'app.url' => $appUrl,
            'app.timezone' => $timezone,
            'app.language' => $language,
            'app.version' => Version::current(),
            'api.base_url' => $appUrl . '/api',
        ];
        foreach ($settings as $key => $value) {
            $connection->execute(
                'INSERT INTO cms_settings (`key`, value_json, updated_at) VALUES (:key, :value, :now)',
                [
                    'key' => $key,
                    'value' => json_encode($value, JSON_UNESCAPED_SLASHES),
                    'now' => date('Y-m-d H:i:s'),
                ],
            );
        }

        $this->writeEnv($db, $appUrl, $secret);
        $this->writeLock();
    }

    public function runMigrations(Connection $connection): void
    {
        $file = $this->paths->migrations() . '/001_cms_foundation.sql';
        if (!is_file($file)) {
            throw new RuntimeException('Migration file not found');
        }

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
    private function writeEnv(array $db, string $appUrl, string $secret): void
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
            '',
        ]);

        if (file_put_contents($this->paths->envFile(), $contents) === false) {
            throw new RuntimeException('Unable to write .env');
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
