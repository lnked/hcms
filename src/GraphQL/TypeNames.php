<?php

declare(strict_types=1);

namespace Cms\GraphQL;

final class TypeNames
{
    public static function object(string $slug): string
    {
        return self::pascal($slug);
    }

    public static function connection(string $slug): string
    {
        return self::pascal($slug) . 'Connection';
    }

    public static function input(string $slug): string
    {
        return self::pascal($slug) . 'Input';
    }

    public static function listField(string $slug): string
    {
        return $slug;
    }

    public static function itemField(string $slug): string
    {
        if (str_ends_with($slug, 'ies') && \strlen($slug) > 3) {
            return substr($slug, 0, -3) . 'y';
        }
        if (str_ends_with($slug, 's') && !str_ends_with($slug, 'ss') && \strlen($slug) > 1) {
            return substr($slug, 0, -1);
        }

        return $slug . 'ById';
    }

    public static function createField(string $slug): string
    {
        return 'create' . self::pascal($slug);
    }

    public static function updateField(string $slug): string
    {
        return 'update' . self::pascal($slug);
    }

    public static function deleteField(string $slug): string
    {
        return 'delete' . self::pascal($slug);
    }

    /** Companion nested field for manyToOne (author_id → author). */
    public static function relationNest(string $fieldName): string
    {
        if (str_ends_with($fieldName, '_id') && \strlen($fieldName) > 3) {
            return substr($fieldName, 0, -3);
        }

        return $fieldName . '_entry';
    }

    private static function pascal(string $slug): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $slug)));
    }
}
