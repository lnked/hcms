<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

use InvalidArgumentException;

final class FileType extends AbstractFieldType
{
    public function name(): string
    {
        return 'file';
    }

    public function defaultConfig(): array
    {
        return [
            'multiple' => false,
            'formats' => [],
        ];
    }

    public function validateConfig(array $config): void
    {
        if (array_key_exists('multiple', $config) && !is_bool($config['multiple'])) {
            throw new InvalidArgumentException('file.multiple must be boolean');
        }
        $formats = $config['formats'] ?? [];
        if (!is_array($formats)) {
            throw new InvalidArgumentException('file.formats must be an array');
        }
        MediaFieldConfig::normalizeFormats($formats, MediaFieldConfig::FILE_FORMATS);
    }
}
