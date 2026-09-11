<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

use InvalidArgumentException;

final class ImageType extends AbstractFieldType
{
    public function name(): string
    {
        return 'image';
    }

    public function defaultConfig(): array
    {
        return [
            'multiple' => false,
            'formats' => [],
            'encodeFormat' => null,
            'sizes' => [],
        ];
    }

    public function validateConfig(array $config): void
    {
        if (\array_key_exists('multiple', $config) && !\is_bool($config['multiple'])) {
            throw new InvalidArgumentException('image.multiple must be boolean');
        }
        $formats = $config['formats'] ?? [];
        if (!\is_array($formats)) {
            throw new InvalidArgumentException('image.formats must be an array');
        }
        MediaFieldConfig::normalizeFormats($formats, MediaFieldConfig::IMAGE_FORMATS);
        MediaFieldConfig::normalizeEncodeFormat($config['encodeFormat'] ?? null);
        MediaFieldConfig::normalizeSizes($config['sizes'] ?? []);
    }
}
