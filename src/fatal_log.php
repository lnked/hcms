<?php

declare(strict_types=1);

/**
 * Leaves a trace when PHP dies before the Kernel can answer.
 *
 * A broken update used to surface as an empty HTTP 500 with nothing on disk to
 * explain it. Fatals are appended to storage/logs/php-fatal.log (rotated at
 * 2 MB) and warnings go to storage/logs/php-error.log; errors are never shown
 * to the client. Dependency-free and best-effort on purpose: this must run
 * inside a half-updated install.
 */

$cmsLogDir = dirname(__DIR__) . '/storage/logs';

if (PHP_SAPI !== 'cli' && is_dir($cmsLogDir) && is_writable($cmsLogDir)) {
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    @ini_set('error_log', $cmsLogDir . '/php-error.log');

    register_shutdown_function(static function () use ($cmsLogDir): void {
        $error = error_get_last();
        if ($error === null || ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) === 0) {
            return;
        }

        $file = $cmsLogDir . '/php-fatal.log';
        if (is_file($file) && filesize($file) > 2 * 1024 * 1024) {
            @rename($file, $file . '.1');
        }

        @file_put_contents(
            $file,
            sprintf(
                "[%s] %s %s — %s in %s:%d\n",
                date('c'),
                is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '-',
                is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '-',
                $error['message'],
                $error['file'],
                $error['line'],
            ),
            FILE_APPEND | LOCK_EX,
        );
    });
}
