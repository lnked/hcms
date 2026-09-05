<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

final class TextType extends AbstractFieldType
{
    public function name(): string
    {
        return 'text';
    }
}
