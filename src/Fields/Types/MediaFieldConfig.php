<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

use InvalidArgumentException;

/**
 * Shared validation helpers for file/image field config.
 */
final class MediaFieldConfig
{
    public const POSITIONS = ['nw', 'n', 'ne', 'w', 'c', 'e', 'sw', 's', 'se'];

    public const MODES = ['crop', 'resize'];

    /** @var list<string> */
    public const FILE_FORMATS = [
        'pdf', 'txt', 'csv', 'mp4', 'webm', 'doc', 'docx', 'xls', 'xlsx', 'zip',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
    ];

    /** @var list<string> */
    public const IMAGE_FORMATS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * @param list<mixed> $formats
     * @param list<string> $allowed
     * @return list<string>
     */
    public static function normalizeFormats(array $formats, array $allowed): array
    {
        $out = [];
        foreach ($formats as $format) {
            if (!is_string($format) && !is_numeric($format)) {
                throw new InvalidArgumentException('formats entries must be strings');
            }
            $ext = strtolower(ltrim(trim((string) $format), '.'));
            if ($ext === '') {
                continue;
            }
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
            if (!in_array($ext, $allowed, true)) {
                throw new InvalidArgumentException('Unsupported format: ' . $ext);
            }
            $out[$ext] = true;
        }

        return array_keys($out);
    }

    /**
     * @param mixed $sizes
     * @return list<array{prefix: string, width: int, height: int, mode: string, position: string}>
     */
    public static function normalizeSizes(mixed $sizes): array
    {
        if ($sizes === null) {
            return [];
        }
        if (!is_array($sizes)) {
            throw new InvalidArgumentException('sizes must be an array');
        }

        $out = [];
        $seen = [];
        foreach ($sizes as $size) {
            if (!is_array($size)) {
                throw new InvalidArgumentException('Each size must be an object');
            }
            $prefix = isset($size['prefix']) && is_string($size['prefix'])
                ? trim($size['prefix'])
                : '';
            if ($prefix === '' || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $prefix)) {
                throw new InvalidArgumentException('size.prefix must match /^[a-z][a-z0-9_]{0,31}$/');
            }
            if (isset($seen[$prefix])) {
                throw new InvalidArgumentException('Duplicate size prefix: ' . $prefix);
            }
            $seen[$prefix] = true;

            $width = isset($size['width']) && is_numeric($size['width']) ? (int) $size['width'] : 0;
            $height = isset($size['height']) && is_numeric($size['height']) ? (int) $size['height'] : 0;
            if ($width < 1 || $height < 1 || $width > 10000 || $height > 10000) {
                throw new InvalidArgumentException('size width/height must be 1..10000');
            }

            $mode = isset($size['mode']) && is_string($size['mode']) ? strtolower(trim($size['mode'])) : 'crop';
            if (!in_array($mode, self::MODES, true)) {
                throw new InvalidArgumentException('size.mode must be crop or resize');
            }

            $position = isset($size['position']) && is_string($size['position'])
                ? strtolower(trim($size['position']))
                : 'c';
            if (!in_array($position, self::POSITIONS, true)) {
                throw new InvalidArgumentException('size.position must be a 9-cell anchor');
            }

            $out[] = [
                'prefix' => $prefix,
                'width' => $width,
                'height' => $height,
                'mode' => $mode,
                'position' => $position,
            ];
        }

        return $out;
    }

    public static function normalizeRotation(mixed $rotation): int
    {
        if ($rotation === null || $rotation === '') {
            return 0;
        }
        if (!is_numeric($rotation)) {
            throw new InvalidArgumentException('rotation must be 0, 90, 180, or 270');
        }
        $deg = ((int) $rotation) % 360;
        if ($deg < 0) {
            $deg += 360;
        }
        if (!in_array($deg, [0, 90, 180, 270], true)) {
            throw new InvalidArgumentException('rotation must be 0, 90, 180, or 270');
        }

        return $deg;
    }

    /**
     * @param mixed $positions
     * @return array<string, string>
     */
    public static function normalizePositions(mixed $positions): array
    {
        if ($positions === null) {
            return [];
        }
        if (!is_array($positions)) {
            throw new InvalidArgumentException('positions must be an object');
        }
        $out = [];
        foreach ($positions as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException('position values must be strings');
            }
            $pos = strtolower(trim($value));
            if (!in_array($pos, self::POSITIONS, true)) {
                throw new InvalidArgumentException('Invalid position: ' . $pos);
            }
            $out[$key] = $pos;
        }

        return $out;
    }
}
