<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Core\Config;
use Cms\Core\Exception\HttpException;
use Cms\Core\Exception\ValidationFailedException;
use Cms\Core\Paths;
use InvalidArgumentException;
use RuntimeException;
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
        if ($e instanceof HttpException) {
            if ($e instanceof ValidationFailedException) {
                $fields = $e->fields() ?? [];

                return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $fields);
            }

            return Response::error($e->errorCode(), $e->getMessage(), $e->status());
        }

        if ($e instanceof InvalidArgumentException) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }

        // Legacy: services encoded HTTP status in RuntimeException::$code (400–499).
        if ($e instanceof RuntimeException) {
            $code = $e->getCode();
            if (\is_int($code) && $code >= 400 && $code < 500) {
                $errorCode = match ($code) {
                    404 => 'NOT_FOUND',
                    409 => 'CONFLICT',
                    422 => 'VALIDATION_ERROR',
                    403 => 'FORBIDDEN',
                    401 => 'UNAUTHORIZED',
                    default => 'ERROR',
                };

                return Response::error($errorCode, $e->getMessage(), $code);
            }
        }

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

        $line = \sprintf(
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
