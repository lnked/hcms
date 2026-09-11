<?php

declare(strict_types=1);

namespace Cms\Core\Exception;

final class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Unauthorized', string $errorCode = 'UNAUTHORIZED')
    {
        parent::__construct($message, 401, $errorCode);
    }
}
