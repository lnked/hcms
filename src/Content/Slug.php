<?php

declare(strict_types=1);

namespace Cms\Content;

final class Slug
{
    public static function isValid(string $slug): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{0,47}$/', $slug);
    }

    public static function fromName(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');
        if ($slug === '' || !preg_match('/^[a-z]/', $slug)) {
            $slug = 'r_' . $slug;
        }

        return substr($slug, 0, 48);
    }
}
