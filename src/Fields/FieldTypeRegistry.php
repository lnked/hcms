<?php

declare(strict_types=1);

namespace Cms\Fields;

use InvalidArgumentException;

final class FieldTypeRegistry
{
    /** @var array<string, FieldType> */
    private array $types = [];

    public function __construct()
    {
        foreach ([
            new Types\StringType(),
            new Types\TextType(),
            new Types\IntegerType(),
            new Types\FloatType(),
            new Types\BooleanType(),
            new Types\DateType(),
            new Types\DateTimeType(),
            new Types\EmailType(),
            new Types\UrlType(),
            new Types\UuidType(),
            new Types\JsonType(),
            new Types\EnumType(),
            new Types\ImageType(),
            new Types\FileType(),
            new Types\RelationType(),
        ] as $type) {
            $this->register($type);
        }
    }

    public function register(FieldType $type): void
    {
        $this->types[$type->name()] = $type;
    }

    public function get(string $name): FieldType
    {
        if (!isset($this->types[$name])) {
            throw new InvalidArgumentException('Unknown field type: ' . $name);
        }

        return $this->types[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->types);
    }
}
