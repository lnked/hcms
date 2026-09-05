<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($uri) ? $uri : '/';

if ($path === '/install.php' || str_starts_with($path, '/install.php')) {
    require dirname(__DIR__) . '/install.php';

    return true;
}

$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
