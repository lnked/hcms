<?php

declare(strict_types=1);

namespace Cms\Core\Exception;

use RuntimeException;
use Throwable;

/**
 * Domain exception with an HTTP status and machine-readable error code.
 * Controllers may catch these, or let Kernel → ExceptionHandler map them.
 */
class HttpException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly string $errorCode = 'ERROR',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
