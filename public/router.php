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
    // Never execute arbitrary PHP under the public tree (uploads must stay outside).
    if (preg_match('/\.(?:php|phtml|phar)$/i', $path) === 1) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Forbidden';

        return true;
    }

    return false;
}

require __DIR__ . '/index.php';
