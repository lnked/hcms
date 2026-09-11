<?php

declare(strict_types=1);

namespace Cms\Auth;

final class Password
{
    public const MIN_LENGTH = 8;

    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function meetsPolicy(string $plain): bool
    {
        if (\strlen($plain) < self::MIN_LENGTH) {
            return false;
        }

        return (bool) preg_match('/[A-Za-z]/', $plain) && (bool) preg_match('/\d/', $plain);
    }

    public static function policyMessage(): string
    {
        return 'Password must be at least ' . self::MIN_LENGTH . ' characters and include a letter and a number';
    }
}
