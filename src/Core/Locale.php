<?php

declare(strict_types=1);

namespace Cms\Core;

final class Locale
{
    /** @var list<string> */
    public const SUPPORTED = ['en', 'ru'];

    public static function normalize(string $language): string
    {
        $language = strtolower(trim($language));

        return in_array($language, self::SUPPORTED, true) ? $language : 'en';
    }

    public static function isSupported(string $language): bool
    {
        return in_array(strtolower(trim($language)), self::SUPPORTED, true);
    }
}
