<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

final class BooleanType extends AbstractFieldType
{
    public function name(): string
    {
        return 'boolean';
    }
}
