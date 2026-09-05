<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Core\Config;
use Cms\Core\Paths;
use Throwable;

final class ExceptionHandler
{
    public function __construct(
        private readonly Config $config,
        private readonly Paths $paths,
    ) {
    }

    public function handle(Throwable $e): Response
    {
        $this->log($e);

        if ($this->config->debug) {
            return Response::error(
                'INTERNAL_ERROR',
                $e->getMessage(),
                500,
            );
        }

        return Response::error('INTERNAL_ERROR', 'Internal server error', 500);
    }

    private function log(Throwable $e): void
    {
        $dir = $this->paths->logs();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            "[%s] %s in %s:%d\n%s\n",
            date('c'),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        );
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND);
    }
}
