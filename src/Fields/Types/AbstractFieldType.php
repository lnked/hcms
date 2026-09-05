<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

use Cms\Fields\FieldType;

abstract class AbstractFieldType implements FieldType
{
    /**
     * @return array<string, mixed>
     */
    public function defaultConfig(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function validateConfig(array $config): void
    {
        unset($config);
    }
}
