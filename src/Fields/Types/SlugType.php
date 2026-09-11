<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

use Cms\Content\Slug;
use InvalidArgumentException;

final class SlugType extends AbstractFieldType
{
    public function name(): string
    {
        return 'slug';
    }

    public function defaultConfig(): array
    {
        return [
            'associatedWith' => '',
            'maxLength' => 255,
        ];
    }

    public function validateConfig(array $config): void
    {
        $associated = $config['associatedWith'] ?? '';
        if (!\is_string($associated) || $associated === '' || !Slug::isValid($associated)) {
            throw new InvalidArgumentException('Slug fields require associatedWith field name');
        }
    }
}
