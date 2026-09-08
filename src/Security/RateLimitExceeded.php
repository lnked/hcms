<?php

declare(strict_types=1);

namespace Cms\Security;

use RuntimeException;

/**
 * Spam rate limit hit: answered with 429 and Retry-After, not with a validation error.
 */
final class RateLimitExceeded extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfter,
        public readonly int $limit,
        string $message = 'Too many submissions',
    ) {
        parent::__construct($message, 429);
    }
}
