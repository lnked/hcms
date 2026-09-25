<?php

declare(strict_types=1);

namespace Cms\Api;

use Cms\Database\Connection;
use Cms\Media\MediaService;
use Cms\Media\MediaValue;
use InvalidArgumentException;

/**
 * Serializes entry rows (incl. media expansion) for REST responses.
 * M2M ids are loaded via an injected callable to avoid a QueryEngine cycle.
 */
final class EntrySerializer
{
    /** @var (callable(string, string, int): list<int>)|null */
    private $loadManyToManyIds;

    /**
     * @param (callable(string, string, int): list<int>)|null $loadManyToManyIds
     */
    public function __construct(
        private readonly Connection $db,
        private readonly string $appUrl = 'http://localhost',
        ?callable $loadManyToManyIds = null,
    ) {
        $this->loadManyToManyIds = $loadManyToManyIds;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $fieldMap
     * @return array<string, mixed>
     */
    public function serialize(array $row, array $fieldMap, string $slug = ''): array
    {
        $out = [
            'id' => (int) $row['id'],
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
            'createdById' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'updatedById' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
        ];
        if (\array_key_exists('locale', $row)) {
            $out['locale'] = $row['locale'];
        }
        if (\array_key_exists('translation_group_id', $row)) {
            $out['translationGroupId'] = $row['translation_group_id'];
        }
        if (\array_key_exists('status', $row)) {
            $out['status'] = $row['status'];
        }
        $mediaCache = [];
        foreach ($fieldMap as $name => $meta) {
            $config = \is_array($meta['spec']['config'] ?? null) ? $meta['spec']['config'] : [];
            if (($meta['type'] ?? '') === 'relation') {
                $cardinality = $config['cardinality'] ?? 'manyToOne';
                if ($cardinality === 'oneToMany') {
                    continue;
                }
                if ($cardinality === 'manyToMany') {
                    if (!($meta['spec']['readable'] ?? true) || ($meta['spec']['hidden'] ?? false)) {
                        continue;
                    }
                    $loader = $this->loadManyToManyIds;
                    $out[$name] = $slug === '' || $loader === null
                        ? []
                        : $loader($slug, $name, (int) $row['id']);
                    continue;
                }
            }
            if (!($meta['spec']['readable'] ?? true) || ($meta['spec']['hidden'] ?? false)) {
                continue;
            }
            $out[$name] = $this->serializeFieldValue($row[$name] ?? null, $meta, $mediaCache);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $fieldMap
     * @param array<string, mixed> $api
     * @return array<string, mixed>
     */
    public function serializeCustom(array $row, array $fieldMap, array $api): array
    {
        $out = ['id' => (int) $row['id']];
        $whitelist = $api['fields'];
        $mediaCache = [];
        if ($whitelist === null) {
            $out['createdAt'] = $row['created_at'] ?? null;
            $out['updatedAt'] = $row['updated_at'] ?? null;
            foreach ($fieldMap as $name => $meta) {
                $config = \is_array($meta['spec']['config'] ?? null) ? $meta['spec']['config'] : [];
                if (($meta['type'] ?? '') === 'relation' && \in_array(($config['cardinality'] ?? 'manyToOne'), ['oneToMany', 'manyToMany'], true)) {
                    continue;
                }
                if (!($meta['spec']['readable'] ?? true) || ($meta['spec']['hidden'] ?? false)) {
                    continue;
                }
                $out[$name] = $this->serializeFieldValue($row[$name] ?? null, $meta, $mediaCache);
            }

            return $out;
        }

        foreach ($whitelist as $name) {
            if (!isset($fieldMap[$name])) {
                continue;
            }
            $out[$name] = $this->serializeFieldValue($row[$name] ?? null, $fieldMap[$name], $mediaCache);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $fieldMap
     * @param list<string>|null $fields
     * @return array<string, mixed>
     */
    public function serializeRelated(array $row, array $fieldMap, ?array $fields): array
    {
        $out = ['id' => (int) $row['id']];
        $mediaCache = [];
        if ($fields === null) {
            $out['createdAt'] = $row['created_at'] ?? null;
            $out['updatedAt'] = $row['updated_at'] ?? null;
            foreach ($fieldMap as $name => $meta) {
                $config = \is_array($meta['spec']['config'] ?? null) ? $meta['spec']['config'] : [];
                if (($meta['type'] ?? '') === 'relation' && \in_array(($config['cardinality'] ?? 'manyToOne'), ['oneToMany', 'manyToMany'], true)) {
                    continue;
                }
                if (!($meta['spec']['readable'] ?? true) || ($meta['spec']['hidden'] ?? false)) {
                    continue;
                }
                $out[$name] = $this->serializeFieldValue($row[$name] ?? null, $meta, $mediaCache);
            }

            return $out;
        }

        foreach ($fields as $name) {
            if (!isset($fieldMap[$name])) {
                continue;
            }
            $out[$name] = $this->serializeFieldValue($row[$name] ?? null, $fieldMap[$name], $mediaCache);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<int, array<string, mixed>> $mediaCache
     */
    private function serializeFieldValue(mixed $value, array $meta, array &$mediaCache): mixed
    {
        $type = (string) ($meta['type'] ?? '');
        $config = \is_array($meta['spec']['config'] ?? null) ? $meta['spec']['config'] : [];
        if ($type === 'relation' && $value !== null) {
            return (int) $value;
        }
        if (($type === 'image' || $type === 'file') && $value !== null && $value !== '') {
            return $this->serializeMediaField($value, (bool) ($config['multiple'] ?? false), $mediaCache);
        }

        return $value;
    }

    /**
     * @param array<int, array<string, mixed>> $mediaCache
     */
    private function serializeMediaField(mixed $raw, bool $multiple, array &$mediaCache): mixed
    {
        try {
            $normalized = MediaValue::normalize($raw, $multiple);
        } catch (InvalidArgumentException) {
            // Legacy/garbage values (e.g. BIGINT 0 from pre-JSON media columns) are not media.
            return null;
        }
        if ($normalized === null) {
            return null;
        }

        $ids = MediaValue::collectIds($normalized);
        $missing = [];
        foreach ($ids as $id) {
            if (!isset($mediaCache[$id])) {
                $missing[] = $id;
            }
        }
        if ($missing !== []) {
            foreach ($this->loadMediaRows($missing) as $id => $item) {
                $mediaCache[$id] = $item;
            }
        }

        $expandItem = function (array $item) use (&$mediaCache): array {
            $variants = [];
            foreach ($item['variants'] as $key => $vid) {
                $vid = (int) $vid;
                $variants[$key] = $mediaCache[$vid] ?? array_merge(
                    ['id' => $vid],
                    MediaService::publicUrls($this->appUrl, $vid),
                );
            }

            $mediaId = (int) $item['id'];

            return [
                'id' => $mediaId,
                'sourceId' => $item['sourceId'] === null ? null : (int) $item['sourceId'],
                'rotation' => (int) $item['rotation'],
                'edit' => $item['edit'],
                'positions' => $item['positions'],
                'overrides' => $item['overrides'],
                'variants' => $variants,
                'media' => $mediaCache[$mediaId] ?? array_merge(
                    ['id' => $mediaId],
                    MediaService::publicUrls($this->appUrl, $mediaId),
                ),
            ];
        };

        if (array_is_list($normalized)) {
            /** @var list<array{id: int, sourceId: int|null, rotation: int, edit: array<string, mixed>|null, positions: array<string, string>, overrides: array<string, array<string, mixed>>, variants: array<string, int>}> $list */
            $list = $normalized;
            $out = [];
            foreach ($list as $item) {
                $out[] = $expandItem($item);
            }

            return $out;
        }

        return $expandItem($normalized);
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function loadMediaRows(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $key = 'm' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $rows = $this->db->select(
            'SELECT id, original_name, mime, size, width, height, created_at FROM cms_media WHERE id IN ('
            . implode(', ', $placeholders) . ')',
            $params,
        );
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $originalName = \is_string($row['original_name'] ?? null) ? (string) $row['original_name'] : null;
            $mime = \is_string($row['mime'] ?? null) ? (string) $row['mime'] : null;
            $urls = MediaService::publicUrls($this->appUrl, $id, $originalName, $mime);
            $out[$id] = [
                'id' => $id,
                'originalName' => $row['original_name'],
                'mime' => $row['mime'],
                'size' => (int) $row['size'],
                'width' => $row['width'] === null ? null : (int) $row['width'],
                'height' => $row['height'] === null ? null : (int) $row['height'],
                'url' => $urls['url'],
                'fullUrl' => $urls['fullUrl'],
                'createdAt' => $row['created_at'],
            ];
        }

        return $out;
    }
}
