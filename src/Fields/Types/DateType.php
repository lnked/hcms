<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

final class DateType extends AbstractFieldType
{
    public const DEFAULT_FORMAT = 'DD.MM.YYYY';

    public function name(): string
    {
        return 'date';
    }

    public function defaultConfig(): array
    {
        return ['format' => self::DEFAULT_FORMAT];
    }

    public function validateConfig(array $config): void
    {
        DateFormat::assertValid($config['format'] ?? self::DEFAULT_FORMAT);
    }
}
