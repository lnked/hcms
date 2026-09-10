<?php

declare(strict_types=1);

namespace Cms\Core\Exception;

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Forbidden', string $errorCode = 'FORBIDDEN')
    {
        parent::__construct($message, 403, $errorCode);
    }
}
