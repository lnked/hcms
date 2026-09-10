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

    /** Each size means one more synchronous GD pass per upload/edit. */
    public const MAX_SIZES = 20;

    /** @var list<string> */
    public const FILE_FORMATS = [
        'pdf', 'txt', 'csv', 'mp4', 'webm', 'doc', 'docx', 'xls', 'xlsx', 'zip',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
    ];

    /** @var list<string> */
    public const IMAGE_FORMATS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Optional storage re-encode targets (not the upload accept-list). */
    public const ENCODE_FORMATS = ['webp', 'jpeg', 'png'];

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
     * Optional convert-on-store format. Empty / null / "keep" = leave source encoding.
     *
     * @return 'webp'|'jpeg'|'png'|null
     */
    public static function normalizeEncodeFormat(mixed $format): ?string
    {
        if ($format === null || $format === '') {
            return null;
        }
        if (!is_string($format)) {
            throw new InvalidArgumentException('encodeFormat must be a string');
        }
        $normalized = strtolower(trim($format));
        if ($normalized === '' || $normalized === 'keep') {
            return null;
        }
        if ($normalized === 'jpg') {
            $normalized = 'jpeg';
        }
        if (!in_array($normalized, self::ENCODE_FORMATS, true)) {
            throw new InvalidArgumentException('encodeFormat must be webp, jpeg, png, or empty');
        }

        return $normalized;
    }

    public static function encodeFormatToMime(?string $format): ?string
    {
        return match ($format) {
            'webp' => 'image/webp',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => null,
        };
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
        if (count($sizes) > self::MAX_SIZES) {
            throw new InvalidArgumentException('sizes must not exceed ' . self::MAX_SIZES . ' entries');
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
                // Dumping the raw bytes makes look-alike characters (e.g. Cyrillic "с") visible.
                throw new InvalidArgumentException(sprintf(
                    'size.prefix must match /^[a-z][a-z0-9_]{0,31}$/, got %s',
                    json_encode($prefix, JSON_UNESCAPED_SLASHES) ?: '""',
                ));
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
     * Normalized crop rect in 0..1 fractions of the rotated image.
     *
     * Returns null for a missing rect or a full-frame one (nothing to cut).
     *
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    public static function normalizeCrop(mixed $crop): ?array
    {
        if ($crop === null || $crop === '') {
            return null;
        }
        if (!is_array($crop)) {
            throw new InvalidArgumentException('crop must be an object');
        }

        $rect = [];
        foreach (['x', 'y', 'w', 'h'] as $key) {
            if (!isset($crop[$key]) || !is_numeric($crop[$key])) {
                throw new InvalidArgumentException('crop.' . $key . ' must be a number');
            }
            $rect[$key] = (float) $crop[$key];
        }
        if ($rect['w'] <= 0.0 || $rect['h'] <= 0.0) {
            throw new InvalidArgumentException('crop width/height must be positive');
        }
        // Round-tripping through JSON floats can overshoot by a hair — clamp instead of rejecting.
        foreach (['x', 'y', 'w', 'h'] as $key) {
            $rect[$key] = max(0.0, min(1.0, $rect[$key]));
        }
        $rect['w'] = min($rect['w'], 1.0 - $rect['x']);
        $rect['h'] = min($rect['h'], 1.0 - $rect['y']);
        if ($rect['w'] <= 0.0 || $rect['h'] <= 0.0) {
            throw new InvalidArgumentException('crop rect is outside the image');
        }
        if ($rect['x'] === 0.0 && $rect['y'] === 0.0 && $rect['w'] === 1.0 && $rect['h'] === 1.0) {
            return null;
        }

        return $rect;
    }

    /**
     * Base edit baked into the master image.
     *
     * @return array{rotation: int, flipH: bool, flipV: bool, crop: array{x: float, y: float, w: float, h: float}|null}|null
     */
    public static function normalizeEdit(mixed $edit): ?array
    {
        if ($edit === null || $edit === '') {
            return null;
        }
        if (!is_array($edit)) {
            throw new InvalidArgumentException('edit must be an object');
        }

        $normalized = [
            'rotation' => self::normalizeRotation($edit['rotation'] ?? 0),
            'flipH' => (bool) ($edit['flipH'] ?? false),
            'flipV' => (bool) ($edit['flipV'] ?? false),
            'crop' => self::normalizeCrop($edit['crop'] ?? null),
        ];
        $isNoop = $normalized['rotation'] === 0
            && !$normalized['flipH']
            && !$normalized['flipV']
            && $normalized['crop'] === null;

        return $isNoop ? null : $normalized;
    }

    /**
     * Per-size crop overrides keyed by size prefix.
     *
     * @return array<string, array{crop: array{x: float, y: float, w: float, h: float}}>
     */
    public static function normalizeOverrides(mixed $overrides): array
    {
        if ($overrides === null || $overrides === '') {
            return [];
        }
        if (!is_array($overrides)) {
            throw new InvalidArgumentException('overrides must be an object');
        }

        $out = [];
        foreach ($overrides as $prefix => $entry) {
            if (!is_string($prefix) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $prefix)) {
                throw new InvalidArgumentException('overrides keys must be size prefixes');
            }
            if (!is_array($entry)) {
                throw new InvalidArgumentException('overrides.' . $prefix . ' must be an object');
            }
            $crop = self::normalizeCrop($entry['crop'] ?? null);
            if ($crop === null) {
                continue;
            }
            $out[$prefix] = ['crop' => $crop];
        }

        return $out;
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
