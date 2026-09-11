<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

final class EnumType extends AbstractFieldType
{
    public function name(): string
    {
        return 'enum';
    }

    public function defaultConfig(): array
    {
        return ['options' => []];
    }

    public function validateConfig(array $config): void
    {
        if (!isset($config['options']) || !\is_array($config['options']) || $config['options'] === []) {
            throw new \InvalidArgumentException('Enum fields require non-empty options');
        }
    }
}
