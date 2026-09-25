<?php

declare(strict_types=1);

namespace Cms\Fields;

interface FieldType
{
    public function name(): string;

    public function label(): string;

    /**
     * Admin widget hint: text|textarea|number|boolean|json|media|relation|enum|richtext|date|datetime|blocks
     */
    public function widget(): string;

    /**
     * @return array<string, mixed>
     */
    public function defaultConfig(): array;

    /**
     * @param array<string, mixed> $config
     */
    public function validateConfig(array $config): void;

    /**
     * SQL type fragment (e.g. VARCHAR(255)) or null for virtual / no column.
     * When null and type is a built-in, SqlTypeMapper may use its legacy map.
     *
     * @param array<string, mixed> $config
     */
    public function sqlType(array $config): ?string;

    /**
     * When true, PayloadValidator calls castValue() instead of the built-in match.
     */
    public function usesCustomCast(): bool;

    /**
     * @param array<string, mixed> $config
     */
    public function castValue(mixed $value, string $name, array $config): mixed;

    /**
     * JSON Schema-ish config editor hints for the admin (optional keys).
     *
     * @return array<string, mixed>
     */
    public function configSchema(): array;
}
