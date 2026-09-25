<?php

declare(strict_types=1);

namespace Cms\Api;

use Cms\Core\Exception\NotFoundException;
use Cms\Database\Connection;
use Cms\Database\MigrationService;
use Cms\Fields\FieldRepository;
use Cms\Media\MediaRefService;
use Cms\Media\MediaService;
use Cms\Media\MediaValue;
use Cms\Resources\ResourceApiRepository;
use Cms\Resources\ResourceApiService;
use Cms\Resources\ResourceRepository;
use Cms\Resources\ResourceService;
use InvalidArgumentException;
use RuntimeException;

final class QueryEngine
{
    public function __construct(
        private readonly Connection $db,
        private readonly ResourceRepository $resources,
        private readonly FieldRepository $fields,
        private readonly ?ResourceApiRepository $apis = null,
        private readonly ?MediaRefService $mediaRefs = null,
        private readonly string $appUrl = 'http://localhost',
    ) {
    }

    /**
     * @param array<string, string> $query
     * @param array{public?: bool} $options
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(string $slug, array $query, array $options = []): array
    {
        [$resource, $table, $fieldMap] = $this->resolve($slug, $options);
        $settings = $this->settingsOf($resource);
        $public = (bool) ($options['public'] ?? false);

        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? 20)));
        if ($public && !($settings['pagination'] ?? true)) {
            $page = 1;
            $limit = 100;
        }
        $offset = ($page - 1) * $limit;

        $where = ['`deleted_at` IS NULL'];
        $params = [];
        $this->applyFeatureFilters($query, $settings, $public, $where, $params);
        if (!$public || ($settings['filtering'] ?? true)) {
            $this->applyFilters($query, $fieldMap, $where, $params);
        } elseif ($this->hasFilterParams($query)) {
            throw new InvalidArgumentException('Filtering is disabled for this resource');
        }
        $searchScoreSql = null;
        if (!$public || ($settings['search'] ?? true)) {
            $searchScoreSql = $this->applySearch($query, $fieldMap, $where, $params);
        } elseif (($query['search'] ?? '') !== '') {
            throw new InvalidArgumentException('Search is disabled for this resource');
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        if ($public && !($settings['sorting'] ?? true)) {
            if (isset($query['sort']) && $query['sort'] !== '' && $query['sort'] !== 'id') {
                throw new InvalidArgumentException('Sorting is disabled for this resource');
            }
            $orderSql = $this->orderSqlWithSearchScore('`id` ASC', $searchScoreSql);
        } else {
            $orderSql = $this->orderSql($query, $fieldMap, $searchScoreSql);
        }

        $countRow = $this->db->selectOne('SELECT COUNT(*) AS c FROM `' . $table . '`' . $whereSql, $params);
        $total = $countRow === null ? 0 : (int) $countRow['c'];

        $rows = $this->db->select(
            'SELECT * FROM `' . $table . '`' . $whereSql . $orderSql . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );

        return [
            'data' => array_map(fn (array $row): array => $this->serialize($row, $fieldMap, $slug), $rows),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) max(1, (int) ceil($total / $limit)),
            ],
        ];
    }

    /**
     * Resolve manyToOne relation targets for a set of stored ids, so the admin table
     * can label the ids it already has without loading the whole related resource.
     *
     * @param list<int> $ids
     * @return array{resourceId: int, slug: string, labelField: string, labels: array<int, string>}
     */
    public function relationLabels(string $slug, string $field, array $ids): array
    {
        [, , $fieldMap] = $this->resolve($slug);
        $meta = $fieldMap[$field] ?? null;
        if ($meta === null || ($meta['type'] ?? '') !== 'relation') {
            throw new InvalidArgumentException('Not a relation field: ' . $field);
        }
        $config = \is_array($meta['spec']['config'] ?? null) ? $meta['spec']['config'] : [];
        if (($config['cardinality'] ?? 'manyToOne') !== 'manyToOne') {
            throw new InvalidArgumentException('Only manyToOne relations have stored ids: ' . $field);
        }

        $relatedSlug = (string) ($config['relatedSlug'] ?? '');
        if ($relatedSlug === '') {
            throw new InvalidArgumentException('Relation has no related resource: ' . $field);
        }
        $related = $this->resources->findByPublicKey($relatedSlug)
            ?? $this->resources->findBySlug($relatedSlug);
        if ($related === null || ($related['status'] ?? '') !== 'published') {
            throw new NotFoundException('Related resource not found: ' . $relatedSlug);
        }

        $relatedFieldMap = $this->fieldMapFromResource($related);
        $labelField = (string) ($config['labelField'] ?? 'id');
        if (!isset($relatedFieldMap[$labelField])) {
            // The schema default is `id`, which says nothing in a list: fall back to the
            // first textual field so the column is readable without reconfiguring.
            $labelField = $this->guessLabelField($relatedFieldMap) ?? 'id';
        }

        $out = [
            'resourceId' => (int) $related['id'],
            'slug' => (string) $related['slug'],
            'labelField' => $labelField,
            'labels' => [],
        ];

        $unique = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($unique === [] || $labelField === 'id') {
            return $out;
        }

        $placeholders = [];
        $params = [];
        foreach ($unique as $i => $id) {
            $param = 'r_' . $i;
            $placeholders[] = ':' . $param;
            $params[$param] = $id;
        }
        $rows = $this->db->select(
            'SELECT `id`, `' . $labelField . '` AS `label` FROM `'
            . MigrationService::tableName((string) $related['content_type_slug']) . '`
             WHERE `id` IN (' . implode(', ', $placeholders) . ') AND `deleted_at` IS NULL',
            $params,
        );
        foreach ($rows as $row) {
            $label = $row['label'] ?? null;
            if ($label === null || \is_array($label)) {
                continue;
            }
            $out['labels'][(int) $row['id']] = (string) $label;
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $fieldMap
     */
    private function guessLabelField(array $fieldMap): ?string
    {
        foreach (['string', 'slug', 'email', 'text', 'url'] as $type) {
            foreach ($fieldMap as $name => $meta) {
                if (($meta['type'] ?? '') !== $type) {
                    continue;
                }
                if (!($meta['spec']['readable'] ?? true) || ($meta['spec']['hidden'] ?? false)) {
                    continue;
                }

                return (string) $name;
            }
        }

        return null;
    }

    /**
     * @param array{public?: bool} $options
     * @return list<array<string, mixed>>
     */
    public function listAll(string $slug, array $options = []): array
    {
        [, $table, $fieldMap] = $this->resolve($slug, $options);
        $rows = $this->db->select(
            'SELECT * FROM `' . $table . '` WHERE `deleted_at` IS NULL ORDER BY `id` ASC',
        );

        return array_map(fn (array $row): array => $this->serialize($row, $fieldMap, $slug), $rows);
    }

    /**
     * @param array{public?: bool} $options
     * @return array<string, mixed>
     */
    public function find(string $slug, int $id, array $options = []): array
    {
        [$resource, $table, $fieldMap] = $this->resolve($slug, $options);
        $row = $this->db->selectOne(
            'SELECT * FROM `' . $table . '` WHERE id = :id AND `deleted_at` IS NULL',
            ['id' => $id],
        );
        if ($row === null) {
            throw new NotFoundException('Resource not found');
        }
        $settings = $this->settingsOf($resource);
        $public = (bool) ($options['public'] ?? false);
        $workflow = \is_array($settings['workflow'] ?? null) ? $settings['workflow'] : [];
        if ($public && (bool) ($workflow['enabled'] ?? false) && ($row['status'] ?? '') !== 'published') {
            throw new NotFoundException('Resource not found');
        }

        return $this->serialize($row, $fieldMap, $slug);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{public?: bool, actorUserId?: int|null} $options
     * @return array<string, mixed>
     */
    public function create(string $slug, array $payload, array $options = []): array
    {
        [$resource, $table, $fieldMap] = $this->resolve($slug, $options);
        $this->ensureActorColumns($table);
        $settings = $this->settingsOf($resource);
        [$payload, $system] = $this->extractSystemFields($payload, $settings);
        $validated = $this->validatePayload($payload, $fieldMap, false);
        [$data, $m2m] = $this->extractManyToMany($validated, $fieldMap);
        $data = [...$data, ...$system];
        $actorId = $this->actorUserId($options);
        if ($actorId !== null) {
            $data['created_by'] = $actorId;
            $data['updated_by'] = $actorId;
        }
        $id = $this->insertRow($table, $data);
        $this->syncManyToMany($slug, $id, $m2m);
        $this->syncMediaRefs($resource, $table, $id, $fieldMap);

        return $this->find($slug, $id, $options);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{public?: bool, actorUserId?: int|null} $options
     * @return array<string, mixed>
     */
    public function patch(string $slug, int $id, array $payload, array $options = []): array
    {
        [$resource, $table, $fieldMap] = $this->resolve($slug, $options);
        $this->requireRow($table, $id);
        $this->ensureActorColumns($table);
        $settings = $this->settingsOf($resource);
        [$payload, $system] = $this->extractSystemFields($payload, $settings, true);
        $validated = $this->validatePayload($payload, $fieldMap, true);
        [$data, $m2m] = $this->extractManyToMany($validated, $fieldMap);
        $data = [...$data, ...$system];
        $actorId = $this->actorUserId($options);
        if ($actorId !== null) {
            $data['updated_by'] = $actorId;
        }
        if ($data !== []) {
            $this->updateRow($table, $id, $data);
        }
        $this->syncManyToMany($slug, $id, $m2m);
        $this->syncMediaRefs($resource, $table, $id, $fieldMap);

        return $this->find($slug, $id, $options);
    }

    /**
     * @param array{public?: bool} $options
     */
    public function delete(string $slug, int $id, array $options = []): void
    {
        [$resource, $table, $fieldMap] = $this->resolve($slug, $options);
        $settings = $this->settingsOf($resource);
        $hard = !(($settings['softDelete'] ?? false) === true || ($settings['deleteStrategy'] ?? 'hard') === 'soft');
        $this->deleteRow($table, $id, $settings);
        if ($hard) {
            $this->clearMediaRefs($resource, $id);
            $this->clearManyToMany($slug, $id, $fieldMap);
        }
    }

    /**
     * @param array<string, string> $query
     * @param array{public?: bool} $options
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function listCustom(string $slug, string $apiSlug, array $query, array $options = []): array
    {
        [$resource, $table, $fieldMap, $api] = $this->resolveCustom($slug, $apiSlug, 'GET', $options);
        $settings = $this->mergedSettings($resource, $api);

        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? 20)));
        $public = (bool) ($options['public'] ?? false);
        if ($public && !($settings['pagination'] ?? true)) {
            $page = 1;
            $limit = 100;
        }
        $offset = ($page - 1) * $limit;

        $where = ['`deleted_at` IS NULL'];
        $params = [];
        if (!$public || ($settings['filtering'] ?? true)) {
            $this->applyFilters($query, $fieldMap, $where, $params);
        } elseif ($this->hasFilterParams($query)) {
            throw new InvalidArgumentException('Filtering is disabled for this resource');
        }
        $searchScoreSql = null;
        if (!$public || ($settings['search'] ?? true)) {
            $searchScoreSql = $this->applySearch($query, $fieldMap, $where, $params);
        } elseif (($query['search'] ?? '') !== '') {
            throw new InvalidArgumentException('Search is disabled for this resource');
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        if ($public && !($settings['sorting'] ?? true)) {
            if (isset($query['sort']) && $query['sort'] !== '' && $query['sort'] !== 'id') {
                throw new InvalidArgumentException('Sorting is disabled for this resource');
            }
            $orderSql = $this->orderSqlWithSearchScore('`id` ASC', $searchScoreSql);
        } else {
            $orderSql = $this->orderSql($query, $fieldMap, $searchScoreSql);
        }

        $countRow = $this->db->selectOne('SELECT COUNT(*) AS c FROM `' . $table . '`' . $whereSql, $params);
        $total = $countRow === null ? 0 : (int) $countRow['c'];

        $selectSql = $this->selectSql($fieldMap, $api);
        $rows = $this->db->select(
            'SELECT ' . $selectSql . ' FROM `' . $table . '`' . $whereSql . $orderSql
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );

        $data = array_map(
            fn (array $row): array => $this->serializeCustom($row, $fieldMap, $api),
            $rows,
        );
        $this->attachJoins($data, $rows, $api);

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) max(1, (int) ceil($total / $limit)),
            ],
        ];
    }

    /**
     * @param array{public?: bool} $options
     * @return array<string, mixed>
     */
    public function findCustom(string $slug, string $apiSlug, int $id, array $options = []): array
    {
        [, $table, $fieldMap, $api] = $this->resolveCustom($slug, $apiSlug, 'GET', $options);

        return $this->fetchCustom($table, $id, $fieldMap, $api);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{public?: bool} $options
     * @return array<string, mixed>
     */
    public function createCustom(string $slug, string $apiSlug, array $payload, array $options = []): array
    {
        [$resource, $table, $fieldMap, $api] = $this->resolveCustom($slug, $apiSlug, 'POST', $options);
        $validated = $this->validatePayload($this->maskPayload($payload, $api, $fieldMap), $fieldMap, false);
        [$data, $m2m] = $this->extractManyToMany($validated, $fieldMap);
        $id = $this->insertRow($table, $data);
        $this->syncManyToMany($slug, $id, $m2m);
        $this->syncMediaRefs($resource, $table, $id, $fieldMap);

        return $this->fetchCustom($table, $id, $fieldMap, $api);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{public?: bool} $options
     * @return array<string, mixed>
     */
    public function patchCustom(string $slug, string $apiSlug, int $id, array $payload, array $options = []): array
    {
        [$resource, $table, $fieldMap, $api] = $this->resolveCustom($slug, $apiSlug, 'PATCH', $options);
        $this->requireRow($table, $id);
        $validated = $this->validatePayload($this->maskPayload($payload, $api, $fieldMap), $fieldMap, true);
        [$data, $m2m] = $this->extractManyToMany($validated, $fieldMap);
        if ($data !== []) {
            $this->updateRow($table, $id, $data);
        }
        $this->syncManyToMany($slug, $id, $m2m);
        $this->syncMediaRefs($resource, $table, $id, $fieldMap);

        return $this->fetchCustom($table, $id, $fieldMap, $api);
    }

    /**
     * @param array{public?: bool} $options
     */
    public function deleteCustom(string $slug, string $apiSlug, int $id, array $options = []): void
    {
        [$resource, $table, $fieldMap] = $this->resolveCustom($slug, $apiSlug, 'DELETE', $options);
        $settings = $this->settingsOf($resource);
        $hard = !(($settings['softDelete'] ?? false) === true || ($settings['deleteStrategy'] ?? 'hard') === 'soft');
        $this->deleteRow($table, $id, $settings);
        if ($hard) {
            $this->clearMediaRefs($resource, $id);
            $this->clearManyToMany($slug, $id, $fieldMap);
        }
    }

    /**
     * Restricts an incoming body to the fields the custom API projects.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $api
     * @param array<string, array<string, mixed>> $fieldMap
     * @return array<string, mixed>
     */
    private function maskPayload(array $payload, array $api, array $fieldMap): array
    {
        $whitelist = $api['fields'];
        if (!\is_array($whitelist)) {
            return $payload;
        }

        $allowed = array_intersect($whitelist, ResourceApiService::writableFieldNames($fieldMap));
        $rejected = array_values(array_diff(array_keys($payload), $allowed));
        if ($rejected !== []) {
            throw new InvalidArgumentException(
                'Fields not writable through this API: ' . implode(', ', $rejected),
            );
        }

        return $payload;
    }

    /**
     * @param array<string, array<string, mixed>> $fieldMap
     * @param array<string, mixed> $api
     * @return array<string, mixed>
     */
    private function fetchCustom(string $table, int $id, array $fieldMap, array $api): array
    {
        $row = $this->db->selectOne(
            'SELECT ' . $this->selectSql($fieldMap, $api) . ' FROM `' . $table . '`
             WHERE id = :id AND `deleted_at` IS NULL',
            ['id' => $id],
        );
        if ($row === null) {
            throw new NotFoundException('Resource not found');
        }

        $items = [$this->serializeCustom($row, $fieldMap, $api)];
        $this->attachJoins($items, [$row], $api);

        return $items[0];
    }

    /**
     * @param array{public?: bool} $options
     * @return array{0: array<string, mixed>, 1: string, 2: array<string, array<string, mixed>>, 3: array<string, mixed>}
     */
    private function resolveCustom(string $slug, string $apiSlug, string $method, array $options = []): array
    {
        if ($this->apis === null) {
            throw new NotFoundException('Custom APIs are not available');
        }
        [$resource, $table, $fieldMap] = $this->resolve($slug, $options);
        $apiRow = $this->apis->findByResourceAndSlug((int) $resource['id'], $apiSlug);
        if ($apiRow === null || !(bool) (int) ($apiRow['enabled'] ?? 0)) {
            throw new NotFoundException('Resource API not found');
        }

        $methods = ResourceApiService::normalizeMethods(
            \is_string($apiRow['methods_json'])
                ? json_decode((string) $apiRow['methods_json'], true)
                : $apiRow['methods_json'],
        );
        if (!\in_array($method, $methods, true)) {
            throw new RuntimeException('Method not allowed', 405);
        }

        $fields = $apiRow['fields_json'];
        if (\is_string($fields)) {
            $fields = json_decode($fields, true);
        }
        $joins = \is_string($apiRow['joins_json'])
            ? json_decode((string) $apiRow['joins_json'], true)
            : $apiRow['joins_json'];
        $apiSettings = \is_string($apiRow['settings_json'])
            ? json_decode((string) $apiRow['settings_json'], true)
            : $apiRow['settings_json'];

        $api = [
            'id' => (int) $apiRow['id'],
            'slug' => (string) $apiRow['slug'],
            'methods' => $methods,
            'fields' => \is_array($fields) ? array_values(array_map('strval', $fields)) : null,
            'joins' => \is_array($joins) ? array_values($joins) : [],
            'settings' => ResourceApiService::normalizeSettings(\is_array($apiSettings) ? $apiSettings : []),
        ];

        return [$resource, $table, $fieldMap, $api];
    }

    /**
     * @param array<string, mixed> $resource
     * @param array<string, mixed> $api
     * @return array<string, mixed>
     */
    private function mergedSettings(array $resource, array $api): array
    {
        $base = $this->settingsOf($resource);
        $override = \is_array($api['settings'] ?? null) ? $api['settings'] : [];
        foreach (['pagination', 'search', 'sorting', 'filtering'] as $key) {
            if (\array_key_exists($key, $override)) {
                $base[$key] = (bool) $override[$key];
            }
        }

        return $base;
    }

    /**
     * @param array<string, array<string, mixed>> $fieldMap
     * @param array<string, mixed> $api
     */
    private function selectSql(array $fieldMap, array $api): string
    {
        $cols = ['`id`', '`created_at`', '`updated_at`'];
        $needed = [];
        if ($api['fields'] === null) {
            foreach ($fieldMap as $name => $meta) {
                $config = \is_array($meta['spec']['config'] ?? null) ? $meta['spec']['config'] : [];
                if (($meta['type'] ?? '') === 'relation' && \in_array(($config['cardinality'] ?? 'manyToOne'), ['oneToMany', 'manyToMany'], true)) {
                    continue;
                }
                $needed[$name] = true;
            }
        } else {
            foreach ($api['fields'] as $name) {
                if (\is_string($name) && isset($fieldMap[$name])) {
                    $needed[$name] = true;
                }
            }
        }
        foreach ($api['joins'] as $join) {
            if (!\is_array($join)) {
                continue;
            }
            $local = (string) ($join['localField'] ?? '');
            if ($local !== '' && isset($fieldMap[$local])) {
                $needed[$local] = true;
            }
        }
        foreach (array_keys($needed) as $name) {
            $cols[] = '`' . $name . '`';
        }

        return implode(', ', array_values(array_unique($cols)));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $fieldMap
     * @param array<string, mixed> $api
     * @return array<string, mixed>
     */
    private function serializeCustom(array $row, array $fieldMap, array $api): array
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
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $api
     */
    private function attachJoins(array &$items, array $rows, array $api): void
    {
        foreach ($api['joins'] as $join) {
            if (!\is_array($join) || ($join['type'] ?? 'manyToOne') !== 'manyToOne') {
                continue;
            }
            $as = (string) ($join['as'] ?? '');
            $relatedSlug = (string) ($join['relatedSlug'] ?? '');
            $localField = (string) ($join['localField'] ?? '');
            $foreignField = (string) ($join['foreignField'] ?? 'id');
            if ($as === '' || $relatedSlug === '' || $localField === '') {
                continue;
            }
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $foreignField)) {
                continue;
            }

            $related = $this->resources->findBySlug($relatedSlug);
            if ($related === null || ($related['status'] ?? '') !== 'published') {
                foreach ($items as $i => $_) {
                    $items[$i][$as] = null;
                }
                continue;
            }

            $relatedTable = MigrationService::tableName((string) $related['content_type_slug']);
            $relatedFieldMap = $this->fieldMapFromResource($related);
            $ids = [];
            foreach ($rows as $row) {
                $fk = $row[$localField] ?? null;
                if ($fk !== null && $fk !== '') {
                    $ids[(string) $fk] = $fk;
                }
            }

            $relatedByKey = [];
            if ($ids !== []) {
                $placeholders = [];
                $params = [];
                $i = 0;
                foreach ($ids as $key => $value) {
                    $param = 'j_' . $i++;
                    $placeholders[] = ':' . $param;
                    $params[$param] = $value;
                }
                $relatedRows = $this->db->select(
                    'SELECT * FROM `' . $relatedTable . '`
                     WHERE `' . $foreignField . '` IN (' . implode(', ', $placeholders) . ')
                       AND `deleted_at` IS NULL',
                    $params,
                );
                foreach ($relatedRows as $relatedRow) {
                    $key = (string) ($relatedRow[$foreignField] ?? '');
                    $relatedByKey[$key] = $this->serializeRelated($relatedRow, $relatedFieldMap, $join['fields'] ?? null);
                }
            }

            foreach ($rows as $i => $row) {
                $fk = $row[$localField] ?? null;
                $items[$i][$as] = ($fk === null || $fk === '')
                    ? null
                    : ($relatedByKey[(string) $fk] ?? null);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $fieldMap
     * @param list<string>|null $fields
     * @return array<string, mixed>
     */
    private function serializeRelated(array $row, array $fieldMap, ?array $fields): array
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
     * @param array<string, mixed> $resource
     * @return array<string, array<string, mixed>>
     */
    private function fieldMapFromResource(array $resource): array
    {
        $fields = $this->fields->forContentType((int) $resource['content_type_id']);
        $map = [];
        foreach ($fields as $field) {
            $spec = \is_string($field['spec_json'])
                ? json_decode((string) $field['spec_json'], true)
                : $field['spec_json'];
            $map[(string) $field['name']] = [
                'type' => $field['type'],
                'spec' => \is_array($spec) ? $spec : [],
            ];
        }

        return $map;
    }

    /**
     * @param array{public?: bool} $options
     * @return array{0: array<string, mixed>, 1: string, 2: array<string, array<string, mixed>>}
     */
    private function resolve(string $slug, array $options = []): array
    {
        $public = (bool) ($options['public'] ?? false);
        $resource = $this->resources->findByPublicKey($slug);
        if ($resource === null || ($resource['status'] ?? '') !== 'published') {
            throw new NotFoundException('Resource not found');
        }
        $settings = $this->settingsOf($resource);
        if ($public && ($settings['apiEnabled'] ?? true) === false) {
            throw new RuntimeException('API disabled for resource', 403);
        }

        $table = MigrationService::tableName((string) $resource['content_type_slug']);
        $fields = $this->fields->forContentType((int) $resource['content_type_id']);
        $map = [];
        foreach ($fields as $field) {
            $spec = \is_string($field['spec_json']) ? json_decode((string) $field['spec_json'], true) : $field['spec_json'];
            $map[(string) $field['name']] = [
                'type' => $field['type'],
                'spec' => \is_array($spec) ? $spec : [],
            ];
        }

        return [$resource, $table, $map];
    }

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private function settingsOf(array $resource): array
    {
        $settings = $resource['settings_json'] ?? [];
        if (\is_string($settings)) {
            $decoded = json_decode($settings, true);
            $settings = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($settings)) {
            $settings = [];
        }

        return ResourceService::normalizeSettings($settings);
    }

    /**
     * @param array<string, mixed> $resource
     * @param array<string, array<string, mixed>> $fieldMap
     */
    private function syncMediaRefs(array $resource, string $table, int $entryId, array $fieldMap): void
    {
        if ($this->mediaRefs === null) {
            return;
        }
        $this->mediaRefs->syncEntry((int) $resource['id'], $entryId, $table, $fieldMap);
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function clearMediaRefs(array $resource, int $entryId): void
    {
        if ($this->mediaRefs === null) {
            return;
        }
        $this->mediaRefs->clearEntry((int) $resource['id'], $entryId);
    }

    /**
     * @param array<string, string> $query
     */
    private function hasFilterParams(array $query): bool
    {
        foreach ($query as $key => $_) {
            if (str_starts_with($key, 'filter[')) {
                return true;
            }
        }

        return false;
    }

    private function requireRow(string $table, int $id): void
    {
        $row = $this->db->selectOne(
            'SELECT id FROM `' . $table . '` WHERE id = :id AND `deleted_at` IS NULL',
            ['id' => $id],
        );
        if ($row === null) {
            throw new NotFoundException('Resource not found');
        }
    }

    /**
     * @param array{public?: bool, actorUserId?: int|null} $options
     */
    private function actorUserId(array $options): ?int
    {
        if (!\array_key_exists('actorUserId', $options) || $options['actorUserId'] === null) {
            return null;
        }
        $id = (int) $options['actorUserId'];

        return $id > 0 ? $id : null;
    }

    private function ensureActorColumns(string $table): void
    {
        static $ready = [];
        if (isset($ready[$table])) {
            return;
        }
        foreach (['created_by', 'updated_by'] as $col) {
            $rows = $this->db->select('SHOW COLUMNS FROM `' . $table . "` LIKE '" . $col . "'");
            if ($rows === []) {
                $this->db->execRaw(
                    'ALTER TABLE `' . $table . '` ADD COLUMN `' . $col . '` BIGINT UNSIGNED NULL',
                );
            }
        }
        $ready[$table] = true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertRow(string $table, array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $cols = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $cols);
        $this->db->execute(
            'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`)
             VALUES (' . implode(', ', $placeholders) . ')',
            $data,
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updateRow(string $table, int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = '`' . $col . '` = :' . $col;
        }
        $data['id'] = $id;
        $this->db->execute(
            'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $data,
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function deleteRow(string $table, int $id, array $settings): void
    {
        if (($settings['softDelete'] ?? false) === true || ($settings['deleteStrategy'] ?? 'hard') === 'soft') {
            $now = date('Y-m-d H:i:s');
            $affected = $this->db->execute(
                'UPDATE `' . $table . '` SET `deleted_at` = :now, `updated_at` = :now
                 WHERE id = :id AND `deleted_at` IS NULL',
                ['now' => $now, 'id' => $id],
            );
        } else {
            $affected = $this->db->execute(
                'DELETE FROM `' . $table . '` WHERE id = :id AND `deleted_at` IS NULL',
                ['id' => $id],
            );
        }
        if ($affected === 0) {
            throw new NotFoundException('Resource not found');
        }
    }

    /**
     * @param array<string, array<string, mixed>> $fieldMap
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload, array $fieldMap, bool $partial): array
    {
        return (new PayloadValidator())->validate($payload, $fieldMap, $partial);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, array<string, mixed>> $fieldMap
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    private function applyFilters(array $query, array $fieldMap, array &$where, array &$params): void
    {
        foreach ($query as $key => $value) {
            if (!str_starts_with($key, 'filter[') || !str_ends_with($key, ']')) {
                continue;
            }
            $inner = substr($key, 7, -1);
            if (str_contains($inner, '][')) {
                [$field, $op] = explode('][', $inner, 2);
            } else {
                $field = $inner;
                $op = 'eq';
            }
            if (!isset($fieldMap[$field]) || !($fieldMap[$field]['spec']['filterable'] ?? false)) {
                throw new InvalidArgumentException('Field not filterable: ' . $field);
            }
            $param = 'f_' . \count($params);
            match ($op) {
                'eq' => [$where[], $params[$param]] = ['`' . $field . '` = :' . $param, $value],
                'neq' => [$where[], $params[$param]] = ['`' . $field . '` <> :' . $param, $value],
                'gt' => [$where[], $params[$param]] = ['`' . $field . '` > :' . $param, $value],
                'gte' => [$where[], $params[$param]] = ['`' . $field . '` >= :' . $param, $value],
                'lt' => [$where[], $params[$param]] = ['`' . $field . '` < :' . $param, $value],
                'lte' => [$where[], $params[$param]] = ['`' . $field . '` <= :' . $param, $value],
                'contains' => [$where[], $params[$param]] = ['`' . $field . '` LIKE :' . $param, '%' . $value . '%'],
                'startsWith' => [$where[], $params[$param]] = ['`' . $field . '` LIKE :' . $param, $value . '%'],
                'endsWith' => [$where[], $params[$param]] = ['`' . $field . '` LIKE :' . $param, '%' . $value],
                'in' => $this->applyIn($field, $value, $where, $params),
                default => throw new InvalidArgumentException('Unknown filter operator: ' . $op),
            };
        }
    }

    /**
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    private function applyIn(string $field, string $value, array &$where, array &$params): void
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
        if ($parts === []) {
            throw new InvalidArgumentException('Empty in filter');
        }
        $placeholders = [];
        foreach ($parts as $i => $part) {
            $param = 'in_' . \count($params) . '_' . $i;
            $placeholders[] = ':' . $param;
            $params[$param] = $part;
        }
        $where[] = '`' . $field . '` IN (' . implode(', ', $placeholders) . ')';
    }

    /**
     * Tokenized OR search across searchable fields; returns relevance score SQL (or null).
     *
     * @param array<string, string> $query
     * @param array<string, array<string, mixed>> $fieldMap
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    private function applySearch(array $query, array $fieldMap, array &$where, array &$params): ?string
    {
        $search = trim((string) ($query['search'] ?? ''));
        if ($search === '') {
            return null;
        }

        $fields = [];
        foreach ($fieldMap as $name => $meta) {
            if ($meta['spec']['searchable'] ?? false) {
                $fields[] = $name;
            }
        }
        if ($fields === []) {
            return null;
        }

        $tokens = SearchTokenizer::tokens($search);
        if ($tokens === []) {
            $tokens = [mb_strtolower($search, 'UTF-8')];
        }

        $tokenMatches = [];
        foreach ($tokens as $token) {
            $fieldParts = [];
            foreach ($fields as $name) {
                $param = 's_' . \count($params);
                $fieldParts[] = '`' . $name . '` LIKE :' . $param . " ESCAPE '\\\\'";
                $params[$param] = $this->likeContains($token);
            }
            $tokenMatches[] = '(' . implode(' OR ', $fieldParts) . ')';
        }

        $where[] = '(' . implode(' OR ', $tokenMatches) . ')';

        $scoreParts = [];
        foreach ($tokenMatches as $matchSql) {
            $scoreParts[] = '(CASE WHEN ' . $matchSql . ' THEN 1 ELSE 0 END)';
        }

        return '(' . implode(' + ', $scoreParts) . ')';
    }

    private function likeContains(string $value): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        return '%' . $escaped . '%';
    }

    /**
     * @param array<string, string> $query
     * @param array<string, array<string, mixed>> $fieldMap
     */
    private function orderSql(array $query, array $fieldMap, ?string $searchScoreSql = null): string
    {
        $sort = $query['sort'] ?? 'id';
        $desc = str_starts_with($sort, '-');
        $field = ltrim($sort, '-');
        if ($field === 'id') {
            return $this->orderSqlWithSearchScore('`id` ' . ($desc ? 'DESC' : 'ASC'), $searchScoreSql);
        }
        if (!isset($fieldMap[$field]) || !($fieldMap[$field]['spec']['sortable'] ?? false)) {
            throw new InvalidArgumentException('Field not sortable: ' . $field);
        }

        return $this->orderSqlWithSearchScore(
            '`' . $field . '` ' . ($desc ? 'DESC' : 'ASC'),
            $searchScoreSql,
        );
    }

    private function orderSqlWithSearchScore(string $secondaryOrder, ?string $searchScoreSql): string
    {
        if ($searchScoreSql === null) {
            return ' ORDER BY ' . $secondaryOrder;
        }

        return ' ORDER BY ' . $searchScoreSql . ' DESC, ' . $secondaryOrder;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $fieldMap
     * @return array<string, mixed>
     */
    private function serialize(array $row, array $fieldMap, string $slug = ''): array
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
                    $out[$name] = $slug === ''
                        ? []
                        : $this->loadManyToManyIds($slug, $name, (int) $row['id']);
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
     * @param array<string, mixed> $validated
     * @param array<string, array<string, mixed>> $fieldMap
     * @return array{0: array<string, mixed>, 1: array<string, list<int>>}
     */
    private function extractManyToMany(array $validated, array $fieldMap): array
    {
        $m2m = [];
        $data = $validated;
        foreach ($fieldMap as $name => $meta) {
            $config = \is_array($meta['spec']['config'] ?? null) ? $meta['spec']['config'] : [];
            if (($meta['type'] ?? '') !== 'relation' || ($config['cardinality'] ?? '') !== 'manyToMany') {
                continue;
            }
            if (!\array_key_exists($name, $data)) {
                continue;
            }
            /** @var list<int> $ids */
            $ids = \is_array($data[$name]) ? $data[$name] : [];
            $m2m[$name] = $ids;
            unset($data[$name]);
        }

        return [$data, $m2m];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $settings
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function extractSystemFields(array $payload, array $settings, bool $partial = false): array
    {
        $system = [];
        $localization = \is_array($settings['localization'] ?? null) ? $settings['localization'] : [];
        $workflow = \is_array($settings['workflow'] ?? null) ? $settings['workflow'] : [];

        if ((bool) ($localization['enabled'] ?? false)) {
            if (\array_key_exists('locale', $payload)) {
                $locale = $payload['locale'];
                unset($payload['locale']);
                if (\is_string($locale) && preg_match('/^[a-z]{2}(-[A-Za-z0-9]+)?$/', $locale)) {
                    $system['locale'] = $locale;
                } elseif ($locale !== null) {
                    throw new InvalidArgumentException('Invalid locale');
                }
            } elseif (!$partial) {
                $system['locale'] = $this->defaultLocaleCode();
            }
            if (\array_key_exists('translationGroupId', $payload) || \array_key_exists('translation_group_id', $payload)) {
                $group = $payload['translationGroupId'] ?? $payload['translation_group_id'];
                unset($payload['translationGroupId'], $payload['translation_group_id']);
                if (\is_string($group) && $group !== '') {
                    $system['translation_group_id'] = $group;
                }
            } elseif (!$partial) {
                $system['translation_group_id'] = $this->newUuid();
            }
        }

        if ((bool) ($workflow['enabled'] ?? false)) {
            if (\array_key_exists('status', $payload)) {
                $status = $payload['status'];
                unset($payload['status']);
                if (!\is_string($status) || !\in_array($status, ['draft', 'in_review', 'published'], true)) {
                    throw new InvalidArgumentException('Invalid entry status');
                }
                $system['status'] = $status;
            } elseif (!$partial) {
                $system['status'] = 'draft';
            }
        }

        return [$payload, $system];
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $settings
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    private function applyFeatureFilters(
        array $query,
        array $settings,
        bool $public,
        array &$where,
        array &$params,
    ): void {
        $workflow = \is_array($settings['workflow'] ?? null) ? $settings['workflow'] : [];
        if ($public && (bool) ($workflow['enabled'] ?? false)) {
            $where[] = '`status` = :wf_status';
            $params['wf_status'] = 'published';
        }

        $localization = \is_array($settings['localization'] ?? null) ? $settings['localization'] : [];
        if ((bool) ($localization['enabled'] ?? false)) {
            $locale = isset($query['locale']) && \is_string($query['locale']) ? trim($query['locale']) : '';
            if ($locale !== '') {
                $where[] = '`locale` = :feat_locale';
                $params['feat_locale'] = $locale;
            } elseif ($public) {
                $where[] = '`locale` = :feat_locale';
                $params['feat_locale'] = $this->defaultLocaleCode();
            }
        }
    }

    private function defaultLocaleCode(): string
    {
        try {
            $row = $this->db->selectOne(
                'SELECT code FROM cms_locales WHERE is_default = 1 AND enabled = 1 LIMIT 1',
            );
            if ($row !== null && isset($row['code']) && \is_string($row['code']) && $row['code'] !== '') {
                return $row['code'];
            }
        } catch (\Throwable) {
            // Locales table may be missing on older installs mid-migrate.
        }

        return 'en';
    }

    /**
     * @param array<string, list<int>> $m2m
     */
    private function syncManyToMany(string $slug, int $leftId, array $m2m): void
    {
        foreach ($m2m as $field => $ids) {
            $join = MigrationService::manyToManyJoinTable($slug, $field);
            $this->db->execute('DELETE FROM `' . $join . '` WHERE `left_id` = :id', ['id' => $leftId]);
            foreach ($ids as $rightId) {
                $this->db->execute(
                    'INSERT INTO `' . $join . '` (`left_id`, `right_id`) VALUES (:l, :r)',
                    ['l' => $leftId, 'r' => $rightId],
                );
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $fieldMap
     */
    private function clearManyToMany(string $slug, int $leftId, array $fieldMap): void
    {
        foreach ($fieldMap as $name => $meta) {
            $config = \is_array($meta['spec']['config'] ?? null) ? $meta['spec']['config'] : [];
            if (($meta['type'] ?? '') !== 'relation' || ($config['cardinality'] ?? '') !== 'manyToMany') {
                continue;
            }
            $join = MigrationService::manyToManyJoinTable($slug, $name);
            try {
                $this->db->execute('DELETE FROM `' . $join . '` WHERE `left_id` = :id', ['id' => $leftId]);
            } catch (\Throwable) {
                // Join table may not exist yet.
            }
        }
    }

    /** @return list<int> */
    private function loadManyToManyIds(string $slug, string $field, int $leftId): array
    {
        $join = MigrationService::manyToManyJoinTable($slug, $field);
        try {
            $rows = $this->db->select(
                'SELECT `right_id` FROM `' . $join . '` WHERE `left_id` = :id ORDER BY `right_id` ASC',
                ['id' => $leftId],
            );
        } catch (\Throwable) {
            return [];
        }
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['right_id'];
        }

        return $ids;
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
