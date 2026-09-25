<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

use Cms\Fields\FieldType;
use LogicException;

abstract class AbstractFieldType implements FieldType
{
    public function label(): string
    {
        return $this->name();
    }

    public function widget(): string
    {
        return 'text';
    }

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

    /**
     * @param array<string, mixed> $config
     */
    public function sqlType(array $config): ?string
    {
        unset($config);

        return null;
    }

    public function usesCustomCast(): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function castValue(mixed $value, string $name, array $config): mixed
    {
        unset($value, $name, $config);

        throw new LogicException('Field type ' . $this->name() . ' has no custom cast');
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return [];
    }
}
