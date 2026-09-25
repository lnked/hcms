<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

use InvalidArgumentException;

/**
 * Ordered list of typed blocks: [{ "type": "hero", ...fields }].
 * Storage is JSON TEXT; nested specs live in config.components.
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

    public function validateConfig(array $config): void
    {
        $components = $config['components'] ?? [];
        if (!\is_array($components) || $components === []) {
            throw new InvalidArgumentException('blocks fields require a non-empty config.components map');
        }
        foreach ($components as $type => $fields) {
            if (!\is_string($type) || !preg_match('/^[a-z][a-z0-9_]{0,47}$/', $type)) {
                throw new InvalidArgumentException('Invalid blocks component type name');
            }
            if (!\is_array($fields) || $fields === []) {
                throw new InvalidArgumentException('blocks component "' . $type . '" needs a non-empty fields list');
            }
            foreach ($fields as $field) {
                if (!\is_array($field) || !isset($field['name'], $field['type'])) {
                    throw new InvalidArgumentException('blocks component fields need name and type');
                }
                $name = (string) $field['name'];
                if (!preg_match('/^[a-z][a-z0-9_]{0,47}$/', $name)) {
                    throw new InvalidArgumentException('Invalid nested field name in blocks component "' . $type . '"');
                }
                if ((string) $field['type'] === 'blocks') {
                    throw new InvalidArgumentException('Nested blocks are not supported');
                }
            }
        }
    }
}
