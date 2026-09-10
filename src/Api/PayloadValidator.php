<?php

declare(strict_types=1);

namespace Cms\Api;

use Cms\Content\UrlSlug;
use Cms\Core\Exception\ValidationFailedException;
use Cms\Media\MediaValue;
use InvalidArgumentException;

/**
 * Validates and casts entry payloads against a field map.
 * Extracted from QueryEngine so FieldType-adjacent rules live outside SQL orchestration.
 */
final class PayloadValidator
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, array<string, mixed>> $fieldMap
     * @return array<string, mixed>
     */
    public function validate(array $payload, array $fieldMap, bool $partial): array
    {
        $out = [];
        foreach ($fieldMap as $name => $meta) {
            $spec = $meta['spec'];
            $type = (string) $meta['type'];
            $config = is_array($spec['config'] ?? null) ? $spec['config'] : [];
            if ($type === 'relation' && ($config['cardinality'] ?? 'manyToOne') === 'oneToMany') {
                continue;
            }
            if (!($spec['writable'] ?? true)) {
                continue;
            }
            if (!array_key_exists($name, $payload)) {
                if ($type === 'slug') {
                    continue;
                }
                if (!$partial && ($spec['required'] ?? false)) {
                    throw ValidationFailedException::field($name, 'Field required: ' . $name);
                }
                continue;
            }
            $value = $payload[$name];
            if ($value === null) {
                if (!($spec['nullable'] ?? true)) {
                    throw ValidationFailedException::field($name, 'Field not nullable: ' . $name);
                }
                $out[$name] = null;
                continue;
            }
            $out[$name] = $this->castValue($value, $type, $name, $config);
        }

        foreach ($fieldMap as $name => $meta) {
            if ((string) $meta['type'] !== 'slug') {
                continue;
            }
            $spec = $meta['spec'];
            if (!($spec['writable'] ?? true)) {
                continue;
            }
            $config = is_array($spec['config'] ?? null) ? $spec['config'] : [];
            $associated = is_string($config['associatedWith'] ?? null) ? $config['associatedWith'] : '';
            $maxLength = (int) ($config['maxLength'] ?? 255);
            $current = $out[$name] ?? null;
            if (($current === null || $current === '') && $associated !== '') {
                $source = $out[$associated] ?? $payload[$associated] ?? null;
                if (is_scalar($source) && (string) $source !== '') {
                    $out[$name] = UrlSlug::from((string) $source, $maxLength);
                }
            } elseif (is_string($current) && $current !== '') {
                $out[$name] = UrlSlug::from($current, $maxLength);
            }

            if (
                !$partial
                && ($spec['required'] ?? false)
                && (!array_key_exists($name, $out) || $out[$name] === null || $out[$name] === '')
            ) {
                throw ValidationFailedException::field($name, 'Field required: ' . $name);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function castValue(mixed $value, string $type, string $name, array $config = []): mixed
    {
        return match ($type) {
            'integer', 'relation' => is_numeric($value)
                ? (int) $value
                : throw ValidationFailedException::field($name, 'Invalid integer: ' . $name),
            'image', 'file' => $this->castMediaValue($value, $name, (bool) ($config['multiple'] ?? false)),
            'float' => is_numeric($value)
                ? (float) $value
                : throw ValidationFailedException::field($name, 'Invalid float: ' . $name),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw ValidationFailedException::field($name, 'Invalid boolean: ' . $name),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)
                ? $value
                : throw ValidationFailedException::field($name, 'Invalid email: ' . $name),
            'json' => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES),
            'slug' => is_scalar($value)
                ? UrlSlug::from((string) $value)
                : throw ValidationFailedException::field($name, 'Invalid value: ' . $name),
            default => is_scalar($value)
                ? (string) $value
                : throw ValidationFailedException::field($name, 'Invalid value: ' . $name),
        };
    }

    private function castMediaValue(mixed $value, string $name, bool $multiple): string
    {
        try {
            $normalized = MediaValue::normalize($value, $multiple);
        } catch (InvalidArgumentException $e) {
            throw ValidationFailedException::field(
                $name,
                'Invalid media value for ' . $name . ': ' . $e->getMessage(),
            );
        }
        if ($normalized === null) {
            throw ValidationFailedException::field($name, 'Invalid media value: ' . $name);
        }
        if ($multiple && $normalized === []) {
            throw ValidationFailedException::field($name, 'Invalid media value: ' . $name);
        }

        return MediaValue::encode($normalized) ?? 'null';
    }
}
