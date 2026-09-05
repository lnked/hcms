<?php

declare(strict_types=1);

namespace Cms\Fields;

interface FieldType
{
    public function name(): string;

    /**
     * @return array<string, mixed>
     */
    public function defaultConfig(): array;

    /**
     * @param array<string, mixed> $config
     */
    public function validateConfig(array $config): void;
}
