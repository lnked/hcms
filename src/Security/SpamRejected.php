<?php

declare(strict_types=1);

namespace Cms\Security;

use InvalidArgumentException;

/**
 * Internal spam rejection with a machine reason; controllers map to a neutral public message.
 */
final class SpamRejected extends InvalidArgumentException
{
    public function __construct(
        public readonly string $reason,
        string $message = 'Submission rejected',
    ) {
        parent::__construct($message, 422);
    }

    public function isCaptcha(): bool
    {
        return $this->reason === 'captcha';
    }

    public function publicMessage(): string
    {
        return $this->isCaptcha() ? 'Captcha verification failed' : 'Submission rejected';
    }
}
