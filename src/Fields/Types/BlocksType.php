<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

use InvalidArgumentException;

/**
 * Ordered list of typed blocks: [{ "type": "hero", ...fields }].
 * Storage is JSON TEXT; nested specs live in config.components.
 *
 * components[type] may be a FieldSpec[] (legacy) or
 * { label?: string, description?: string, fields: FieldSpec[] }.
 */
final class BlocksType extends AbstractFieldType
{
    public function name(): string
    {
        return 'blocks';
    }

    public function widget(): string
    {
        return 'blocks';
    }

    public function defaultConfig(): array
    {
        return [
            'components' => [],
        ];
    }

    /**
     * @param array<string, mixed> $components
     * @return list<array<string, mixed>>|null
     */
    public static function fieldsForComponent(array $components, string $type): ?array
    {
        if (!isset($components[$type]) || !\is_array($components[$type])) {
            return null;
        }
        $raw = $components[$type];
        if (isset($raw['fields']) && \is_array($raw['fields'])) {
            /** @var list<array<string, mixed>> $fields */
            $fields = [];
            foreach ($raw['fields'] as $field) {
                if (\is_array($field)) {
                    $fields[] = $field;
                }
            }

            return $fields;
        }
        if ($raw === [] || array_is_list($raw)) {
            /** @var list<array<string, mixed>> $fields */
            $fields = [];
            foreach ($raw as $field) {
                if (\is_array($field)) {
                    $fields[] = $field;
                }
            }

            return $fields;
        }

        return null;
    }

    public function validateConfig(array $config): void
    {
        $components = $config['components'] ?? [];
        if (!\is_array($components) || $components === []) {
            throw new InvalidArgumentException('blocks fields require a non-empty config.components map');
        }
        foreach ($components as $type => $entry) {
            if (!\is_string($type) || !preg_match('/^[a-z][a-z0-9_]{0,47}$/', $type)) {
                throw new InvalidArgumentException('Invalid blocks component type name');
            }
            if (!\is_array($entry)) {
                throw new InvalidArgumentException('blocks component "' . $type . '" must be an array');
            }
            $fields = self::fieldsForComponent([$type => $entry], $type);
            if ($fields === null || $fields === []) {
                throw new InvalidArgumentException('blocks component "' . $type . '" needs a non-empty fields list');
            }
            foreach ($fields as $field) {
                if (!isset($field['name'], $field['type'])) {
                    throw new InvalidArgumentException('blocks component fields need name and type');
                }
                $name = (string) $field['name'];
                if (!preg_match('/^[a-z][a-z0-9_]{0,47}$/', $name)) {
                    throw new InvalidArgumentException('Invalid nested field name in blocks component "' . $type . '"');
                }
                $nestedType = (string) $field['type'];
                if ($nestedType === 'blocks') {
                    throw new InvalidArgumentException('Nested blocks are not supported');
                }
                $nestedConfig = \is_array($field['config'] ?? null) ? $field['config'] : [];
                if ($nestedType === 'enum') {
                    $options = $nestedConfig['options'] ?? null;
                    if (!\is_array($options) || $options === []) {
                        throw new InvalidArgumentException(
                            'blocks nested enum "' . $name . '" requires non-empty config.options',
                        );
                    }
                }
                if ($nestedType === 'relation') {
                    $related = $nestedConfig['relatedSlug'] ?? null;
                    if (!\is_string($related) || $related === '') {
                        throw new InvalidArgumentException(
                            'blocks nested relation "' . $name . '" requires config.relatedSlug',
                        );
                    }
                }
            }
        }
    }
}
