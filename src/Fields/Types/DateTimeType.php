<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

final class DateTimeType extends AbstractFieldType
{
    public const DEFAULT_FORMAT = 'DD.MM.YYYY HH:mm';

    public function name(): string
    {
        return 'datetime';
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
