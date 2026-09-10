<?php

declare(strict_types=1);

namespace Cms\Hooks;

use InvalidArgumentException;

final class HookRejectedException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'HOOK_REJECTED',
    ) {
        parent::__construct($message, 422);
    }
}
