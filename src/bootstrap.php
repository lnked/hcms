<?php

declare(strict_types=1);

use Cms\Http\Kernel;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":{"code":"MISSING_VENDOR","message":"Run composer install"}}';
    exit(1);
}

require $autoload;

return Kernel::boot($root);
