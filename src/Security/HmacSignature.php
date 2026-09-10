<?php

declare(strict_types=1);

namespace Cms\Security;

final class HmacSignature
{
    public static function header(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public static function verify(string $body, string $secret, string $header): bool
    {
        $expected = self::header($body, $secret);

        return hash_equals($expected, $header);
    }
}
