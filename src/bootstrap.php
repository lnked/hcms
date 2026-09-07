<?php

declare(strict_types=1);

use Cms\Http\Kernel;
use Cms\System\UpdateJournal;

$root = dirname(__DIR__);
require __DIR__ . '/autoload.php';
require __DIR__ . '/fatal_log.php';

// An update whose worker was killed mid-swap is undone here, on the first
// request after the crash, before anything tries to use the mixed tree.
UpdateJournal::revertInterrupted($root);

if (!class_exists(Kernel::class)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":{"code":"BROKEN_INSTALL","message":"src/ is incomplete — run php scripts/restore.php diagnose"}}';
    exit(1);
}

return Kernel::boot($root);
