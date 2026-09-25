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
            $config = \is_array($spec['config'] ?? null) ? $spec['config'] : [];
            if ($type === 'relation') {
                $cardinality = $config['cardinality'] ?? 'manyToOne';
                if ($cardinality === 'oneToMany') {
                    continue;
                }
                if ($cardinality === 'manyToMany') {
                    if (!\array_key_exists($name, $payload)) {
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
                        $out[$name] = [];
                        continue;
                    }
                    $out[$name] = $this->castRelationIds($value, $name);
                    continue;
                }
            }
            if (!($spec['writable'] ?? true)) {
                continue;
            }
            if (!\array_key_exists($name, $payload)) {
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
            $config = \is_array($spec['config'] ?? null) ? $spec['config'] : [];
            $associated = \is_string($config['associatedWith'] ?? null) ? $config['associatedWith'] : '';
            $maxLength = (int) ($config['maxLength'] ?? 255);
            $current = $out[$name] ?? null;
            if (($current === null || $current === '') && $associated !== '') {
                $source = $out[$associated] ?? $payload[$associated] ?? null;
                if (\is_scalar($source) && (string) $source !== '') {
                    $out[$name] = UrlSlug::from((string) $source, $maxLength);
                }
            } elseif (\is_string($current) && $current !== '') {
                $out[$name] = UrlSlug::from($current, $maxLength);
            }

            if (
                !$partial
                && ($spec['required'] ?? false)
                && (!\array_key_exists($name, $out) || $out[$name] === null || $out[$name] === '')
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
            'blocks' => $this->castBlocksValue($value, $name, $config),
            'float' => is_numeric($value)
                ? (float) $value
                : throw ValidationFailedException::field($name, 'Invalid float: ' . $name),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw ValidationFailedException::field($name, 'Invalid boolean: ' . $name),
            'email' => \is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)
                ? $value
                : throw ValidationFailedException::field($name, 'Invalid email: ' . $name),
            'json' => \is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES),
            'slug' => \is_scalar($value)
                ? UrlSlug::from((string) $value)
                : throw ValidationFailedException::field($name, 'Invalid value: ' . $name),
            default => \is_scalar($value)
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

    /**
     * @return list<int>
     */
    private function castRelationIds(mixed $value, string $name): array
    {
        if (!\is_array($value)) {
            throw ValidationFailedException::field($name, 'manyToMany expects an array of ids: ' . $name);
        }
        $ids = [];
        foreach ($value as $item) {
            if (!is_numeric($item)) {
                throw ValidationFailedException::field($name, 'Invalid relation id in ' . $name);
            }
            $id = (int) $item;
            if ($id < 1) {
                throw ValidationFailedException::field($name, 'Invalid relation id in ' . $name);
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function castBlocksValue(mixed $value, string $name, array $config): string
    {
        if (\is_string($value)) {
            $decoded = json_decode($value, true);
            if (!\is_array($decoded)) {
                throw ValidationFailedException::field($name, 'Invalid blocks JSON: ' . $name);
            }
            $value = $decoded;
        }
        if (!\is_array($value)) {
            throw ValidationFailedException::field($name, 'blocks expects an array: ' . $name);
        }
        $components = \is_array($config['components'] ?? null) ? $config['components'] : [];
        $out = [];
        foreach ($value as $i => $block) {
            if (!\is_array($block)) {
                throw ValidationFailedException::field($name, 'Invalid block at index ' . $i);
            }
            $type = isset($block['type']) && \is_string($block['type']) ? $block['type'] : '';
            if ($type === '' || !isset($components[$type]) || !\is_array($components[$type])) {
                throw ValidationFailedException::field($name . '.' . $i . '.type', 'Unknown block type: ' . $type);
            }
            $normalized = ['type' => $type];
            foreach ($components[$type] as $field) {
                if (!\is_array($field)) {
                    continue;
                }
                $fname = isset($field['name']) && \is_string($field['name']) ? $field['name'] : '';
                if ($fname === '') {
                    continue;
                }
                $required = (bool) ($field['required'] ?? false);
                $nullable = (bool) ($field['nullable'] ?? true);
                if (!\array_key_exists($fname, $block)) {
                    if ($required) {
                        throw ValidationFailedException::field(
                            $name . '.' . $i . '.' . $fname,
                            'Field required: ' . $fname,
                        );
                    }
                    continue;
                }
                $fv = $block[$fname];
                if ($fv === null) {
                    if (!$nullable) {
                        throw ValidationFailedException::field(
                            $name . '.' . $i . '.' . $fname,
                            'Field not nullable: ' . $fname,
                        );
                    }
                    $normalized[$fname] = null;
                    continue;
                }
                $normalized[$fname] = \is_scalar($fv) || \is_array($fv) ? $fv : (string) $fv;
            }
            $out[] = $normalized;
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
}
