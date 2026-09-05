<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

final class JsonType extends AbstractFieldType
{
    public function name(): string
    {
        return 'json';
    }
}
