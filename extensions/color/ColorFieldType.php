<?php

declare(strict_types=1);

namespace Cms\Extension\Color;

use Cms\Core\Exception\ValidationFailedException;
use Cms\Fields\Types\AbstractFieldType;

/**
 * Example plugin field: hex color (#RGB / #RRGGBB).
 * Loaded via extensions/color/manifest.php — do not register in core ctor.
 */
final class ColorFieldType extends AbstractFieldType
{
    public function name(): string
    {
        return 'color';
    }

    public function label(): string
    {
        return 'Color';
    }

    public function widget(): string
    {
        return 'text';
    }

    public function sqlType(array $config): ?string
    {
        unset($config);

        return 'VARCHAR(7)';
    }

    public function usesCustomCast(): bool
    {
        return true;
    }

    public function castValue(mixed $value, string $name, array $config): mixed
    {
        unset($config);
        if (!\is_string($value)) {
            throw ValidationFailedException::field($name, 'Color must be a hex string: ' . $name);
        }
        $trimmed = trim($value);
        if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $trimmed)) {
            throw ValidationFailedException::field($name, 'Invalid hex color: ' . $name);
        }

        return strtoupper($trimmed);
    }
}
