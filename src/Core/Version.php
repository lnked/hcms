<?php

declare(strict_types=1);

namespace Cms\Core;

final class Version
{
    public static function current(): string
    {
        $path = \dirname(__DIR__, 2) . '/VERSION';
        if (!is_file($path)) {
            return '0.0.0';
        }

        $value = trim((string) file_get_contents($path));

        return $value !== '' ? $value : '0.0.0';
    }

    public static function compare(string $a, string $b): int
    {
        return version_compare(self::normalize($a), self::normalize($b));
    }

    public static function isGreater(string $a, string $b): bool
    {
        return self::compare($a, $b) > 0;
    }

    private static function normalize(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }
}
