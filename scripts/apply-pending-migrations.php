<?php

declare(strict_types=1);

/**
 * Apply database/*.sql pending migrations using THIS tree's code.
 *
 * UpdateService must invoke this in a fresh PHP process after swap — the
 * in-request PendingMigrations class may still be the pre-update copy.
 *
 *   php scripts/apply-pending-migrations.php [/path/to/install]
 */

use Cms\Core\Config;
use Cms\Core\Env;
use Cms\Core\Paths;
use Cms\Core\Settings;
use Cms\Database\Connection;
use Cms\Database\PendingMigrations;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("apply-pending-migrations.php is a CLI tool\n");
}

$root = rtrim(str_replace('\\', '/', $argv[1] ?? dirname(__DIR__)), '/');
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, 'FAIL: missing vendor/autoload.php in ' . $root . "\n");
    exit(1);
}

require $root . '/vendor/autoload.php';

$paths = new Paths($root);
if (!is_file($paths->installedLock())) {
    fwrite(STDERR, 'FAIL: CMS is not installed in ' . $root . "\n");
    exit(1);
}

$env = new Env();
$env->load($paths->envFile());
$config = Config::fromEnv($env);
if ($config->dbName === '') {
    fwrite(STDERR, "FAIL: database is not configured\n");
    exit(1);
}

$db = Connection::connect([
    'host' => $config->dbHost,
    'port' => $config->dbPort,
    'database' => $config->dbName,
    'username' => $config->dbUser,
    'password' => $config->dbPassword,
    'charset' => $config->dbCharset,
]);
$settings = new Settings($db);
PendingMigrations::apply($db, $paths, $settings);

fwrite(STDOUT, "OK: pending migrations applied\n");
exit(0);
