<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

final class StringType extends AbstractFieldType
{
    public function name(): string
    {
        return 'string';
    }

    public function defaultConfig(): array
    {
        return ['maxLength' => 255];
    }
}
