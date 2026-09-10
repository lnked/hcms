<?php

declare(strict_types=1);

namespace Cms\Core\Exception;

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not found', string $errorCode = 'NOT_FOUND')
    {
        parent::__construct($message, 404, $errorCode);
    }
}
