<?php

declare(strict_types=1);

namespace Cms\Database;

final class ColumnDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $sqlType,
        public readonly bool $nullable = true,
        public readonly bool $unique = false,
        public readonly bool $indexed = false,
        public readonly ?string $defaultSql = null,
    ) {
    }
}
