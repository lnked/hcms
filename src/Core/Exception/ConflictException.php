<?php

declare(strict_types=1);

namespace Cms\Core\Exception;

final class ConflictException extends HttpException
{
    public function __construct(string $message = 'Conflict', string $errorCode = 'CONFLICT')
    {
        parent::__construct($message, 409, $errorCode);
    }
}
