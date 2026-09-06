<?php

declare(strict_types=1);

namespace Cms\Content;

/**
 * URL-safe slug for entry content (hyphenated), unlike {@see Slug} identifier slugs.
 */
final class UrlSlug
{
    private const TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    public static function from(string $value, int $maxLength = 255): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $out = '';
        $len = mb_strlen($value, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($value, $i, 1, 'UTF-8');
            $out .= self::TRANSLIT[$ch] ?? $ch;
        }
        $out = preg_replace('/[^a-z0-9]+/', '-', $out) ?? '';
        $out = trim($out, '-');
        if ($maxLength < 1) {
            $maxLength = 255;
        }

        return substr($out, 0, $maxLength);
    }
}
