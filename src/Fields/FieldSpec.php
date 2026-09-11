<?php

declare(strict_types=1);

namespace Cms\Fields;

final class FieldSpec
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly bool $required = false,
        public readonly bool $nullable = true,
        public readonly bool $unique = false,
        public readonly bool $indexed = false,
        public readonly mixed $default = null,
        public readonly bool $readonly = false,
        public readonly bool $hidden = false,
        public readonly bool $searchable = false,
        public readonly bool $sortable = false,
        public readonly bool $filterable = false,
        public readonly bool $readable = true,
        public readonly bool $writable = true,
        public readonly array $config = [],
        public readonly ?string $label = null,
        public readonly ?string $description = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $config = isset($data['config']) && \is_array($data['config']) ? $data['config'] : [];

        return new self(
            required: (bool) ($data['required'] ?? false),
            nullable: (bool) ($data['nullable'] ?? true),
            unique: (bool) ($data['unique'] ?? false),
            indexed: (bool) ($data['indexed'] ?? false),
            default: $data['default'] ?? null,
            readonly: (bool) ($data['readonly'] ?? false),
            hidden: (bool) ($data['hidden'] ?? false),
            searchable: (bool) ($data['searchable'] ?? false),
            sortable: (bool) ($data['sortable'] ?? false),
            filterable: (bool) ($data['filterable'] ?? false),
            readable: (bool) ($data['readable'] ?? true),
            writable: (bool) ($data['writable'] ?? true),
            config: $config,
            label: isset($data['label']) && \is_string($data['label']) ? $data['label'] : null,
            description: isset($data['description']) && \is_string($data['description']) ? $data['description'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'required' => $this->required,
            'nullable' => $this->nullable,
            'unique' => $this->unique,
            'indexed' => $this->indexed,
            'default' => $this->default,
            'readonly' => $this->readonly,
            'hidden' => $this->hidden,
            'searchable' => $this->searchable,
            'sortable' => $this->sortable,
            'filterable' => $this->filterable,
            'readable' => $this->readable,
            'writable' => $this->writable,
            'config' => $this->config,
            'label' => $this->label,
            'description' => $this->description,
        ];
    }
}
