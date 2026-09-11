<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Auth\AuthContext;
use Cms\Core\Exception\UnauthorizedException;

/**
 * Narrows nullable route auth to a concrete AuthContext for admin handlers.
 */
final class RequireAuth
{
    public static function context(?AuthContext $context): AuthContext
    {
        if ($context === null) {
            throw new UnauthorizedException();
        }

        return $context;
    }
}
