<?php

declare(strict_types=1);

namespace Cms\Media;

use Cms\Fields\Types\MediaFieldConfig;
use InvalidArgumentException;

/**
 * Encodes/decodes image|file field values stored as JSON.
 *
 * Shape (single): { id, rotation, positions, variants }
 * Shape (multiple): list of single
 */
final class MediaValue
{
    /**
     * @return array{id: int, rotation: int, positions: array<string, string>, variants: array<string, int>}|list<array{id: int, rotation: int, positions: array<string, string>, variants: array<string, int>}>|null
     */
    public static function normalize(mixed $value, bool $multiple = false): array|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            } elseif (is_numeric($value)) {
                $value = (int) $value;
            } else {
                throw new InvalidArgumentException('Invalid media value JSON');
            }
        }

        // Legacy BIGINT / bare id
        if (is_numeric($value) && !is_array($value)) {
            $item = self::itemFromId((int) $value);
            return $multiple ? [$item] : $item;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('Invalid media value');
        }

        // Detect list vs single object
        $isList = array_is_list($value);
        if ($multiple) {
            if (!$isList) {
                // Single object written into multiple field — wrap
                if (isset($value['id'])) {
                    return [self::normalizeItem($value)];
                }
                throw new InvalidArgumentException('multiple media value must be an array');
            }
            $items = [];
            foreach ($value as $entry) {
                $items[] = self::normalizeItem($entry);
            }

            return $items;
        }

        if ($isList) {
            if ($value === []) {
                return null;
            }
            // Accidental array for single field — take first
            return self::normalizeItem($value[0]);
        }

        return self::normalizeItem($value);
    }

    /**
     * @param array{id: int, rotation: int, positions: array<string, string>, variants: array<string, int>}|list<array{id: int, rotation: int, positions: array<string, string>, variants: array<string, int>}>|null $value
     */
    public static function encode(array|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{id: int, rotation: int, positions: array<string, string>, variants: array<string, int>}
     */
    public static function itemFromId(int $id, int $rotation = 0, array $positions = [], array $variants = []): array
    {
        if ($id < 1) {
            throw new InvalidArgumentException('media id must be positive');
        }

        return [
            'id' => $id,
            'rotation' => MediaFieldConfig::normalizeRotation($rotation),
            'positions' => MediaFieldConfig::normalizePositions($positions),
            'variants' => self::normalizeVariants($variants),
        ];
    }

    /**
     * Collect all media ids (original + variants) from a stored value.
     *
     * @return list<int>
     */
    public static function collectIds(mixed $value): array
    {
        $normalized = null;
        try {
            if ($value === null || $value === '') {
                return [];
            }
            if (is_numeric($value) && !is_array($value)) {
                return [(int) $value];
            }
            $normalized = self::normalize($value, is_array($value) && array_is_list($value));
        } catch (InvalidArgumentException) {
            return [];
        }
        if ($normalized === null) {
            return [];
        }
        $items = array_is_list($normalized) ? $normalized : [$normalized];
        $ids = [];
        foreach ($items as $item) {
            $ids[(int) $item['id']] = true;
            foreach ($item['variants'] as $vid) {
                $ids[(int) $vid] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * Remap media ids inside a stored value using old=>new map.
     */
    public static function remapIds(mixed $value, array $mediaMap): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (is_numeric($value) && !is_array($value)) {
            $old = (int) $value;

            return $mediaMap[$old] ?? $old;
        }

        $multiple = is_array($value) && array_is_list($value);
        try {
            $normalized = self::normalize($value, $multiple || (is_array($value) && isset($value[0])));
        } catch (InvalidArgumentException) {
            return $value;
        }
        if ($normalized === null) {
            return null;
        }

        $remapItem = static function (array $item) use ($mediaMap): array {
            $id = (int) $item['id'];
            $item['id'] = $mediaMap[$id] ?? $id;
            $variants = [];
            foreach ($item['variants'] as $key => $vid) {
                $vid = (int) $vid;
                $variants[$key] = $mediaMap[$vid] ?? $vid;
            }
            $item['variants'] = $variants;

            return $item;
        };

        if (array_is_list($normalized)) {
            return array_map($remapItem, $normalized);
        }

        return $remapItem($normalized);
    }

    /**
     * @param mixed $entry
     * @return array{id: int, rotation: int, positions: array<string, string>, variants: array<string, int>}
     */
    private static function normalizeItem(mixed $entry): array
    {
        if (is_numeric($entry) && !is_array($entry)) {
            return self::itemFromId((int) $entry);
        }
        if (!is_array($entry)) {
            throw new InvalidArgumentException('Invalid media item');
        }
        $id = isset($entry['id']) && is_numeric($entry['id']) ? (int) $entry['id'] : 0;
        if ($id < 1) {
            throw new InvalidArgumentException('media id must be positive');
        }

        return self::itemFromId(
            $id,
            $entry['rotation'] ?? 0,
            is_array($entry['positions'] ?? null) ? $entry['positions'] : [],
            is_array($entry['variants'] ?? null) ? $entry['variants'] : [],
        );
    }

    /**
     * @param array<mixed, mixed> $variants
     * @return array<string, int>
     */
    private static function normalizeVariants(array $variants): array
    {
        $out = [];
        foreach ($variants as $key => $id) {
            if (!is_string($key) || $key === '' || !is_numeric($id)) {
                continue;
            }
            $vid = (int) $id;
            if ($vid > 0) {
                $out[$key] = $vid;
            }
        }

        return $out;
    }
}
