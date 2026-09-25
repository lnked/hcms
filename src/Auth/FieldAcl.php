<?php

declare(strict_types=1);

namespace Cms\Auth;

/**
 * Per-grant field overrides and row ownership for admin ACL.
 */
final class FieldAcl
{
    /**
     * @param mixed $raw
     * @return array<string, array{readable: bool, writable: bool}>
     */
    public static function normalize(mixed $raw): array
    {
        if (\is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $field => $flags) {
            if (!\is_string($field) || !preg_match('/^[a-z][a-z0-9_]{0,47}$/', $field) || !\is_array($flags)) {
                continue;
            }
            $out[$field] = [
                'readable' => \array_key_exists('readable', $flags) ? (bool) $flags['readable'] : true,
                'writable' => \array_key_exists('writable', $flags) ? (bool) $flags['writable'] : true,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, array{readable: bool, writable: bool}> $fieldAcl
     * @return array<string, mixed>
     */
    public static function applyToSpec(array $spec, string $fieldName, array $fieldAcl): array
    {
        if (!isset($fieldAcl[$fieldName])) {
            return $spec;
        }
        $override = $fieldAcl[$fieldName];
        $spec['readable'] = ($spec['readable'] ?? true) && $override['readable'];
        $spec['writable'] = ($spec['writable'] ?? true) && $override['writable'];

        return $spec;
    }
}
