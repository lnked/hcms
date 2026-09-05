<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

final class EmailType extends AbstractFieldType
{
    public function name(): string
    {
        return 'email';
    }
}
