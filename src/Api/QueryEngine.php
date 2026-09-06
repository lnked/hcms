<?php

declare(strict_types=1);

namespace Cms\Api;

use Cms\Content\UrlSlug;
use Cms\Database\Connection;
use Cms\Database\MigrationService;
use Cms\Fields\FieldRepository;
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
        if (!$public || ($settings['filtering'] ?? true)) {
            $this->applyFilters($query, $fieldMap, $where, $params);
        } elseif ($this->hasFilterParams($query)) {
            throw new InvalidArgumentException('Filtering is disabled for this resource');
        }
        if (!$public || ($settings['search'] ?? true)) {
            $this->applySearch($query, $fieldMap, $where, $params);
        } elseif (($query['search'] ?? '') !== '') {
            throw new InvalidArgumentException('Search is disabled for this resource');
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        if ($public && !($settings['sorting'] ?? true)) {
            if (isset($query['sort']) && $query['sort'] !== '' && $query['sort'] !== 'id') {
                throw new InvalidArgumentException('Sorting is disabled for this resource');
            }
            $orderSql = ' ORDER BY `id` ASC';
        } else {
            $orderSql = $this->orderSql($query, $fieldMap);
        }

        $countRow = $this->db->selectOne('SELECT COUNT(*) AS c FROM `' . $table . '`' . $whereSql, $params);
        $total = $countRow === null ? 0 : (int) $countRow['c'];

        $rows = $this->db->select(
            'SELECT * FROM `' . $table . '`' . $whereSql . $orderSql . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );

        return [
            'data' => array_map(fn (array $row): array => $this->serialize($row, $fieldMap), $rows),
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
    public function find(string $slug, int $id, array $options = []): array
    {
        [, $table, $fieldMap] = $this->resolve($slug, $options);
        $row = $this->db->selectOne(
            'SELECT * FROM `' . $table . '` WHERE id = :id AND `deleted_at` IS NULL',
            ['id' => $id],
        );
        if ($row === null) {
            throw new RuntimeException('Resource not found', 404);
        }

        return $this->serialize($row, $fieldMap);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{public?: bool} $options
     * @return array<string, mixed>
     */
    public function create(string $slug, array $payload, array $options = []): array
    {
        [, $table, $fieldMap] = $this->resolve($slug, $options);
        $data = $this->validatePayload($payload, $fieldMap, false);
        $now = date('Y-m-d H:i:s');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $cols = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $cols);
        $this->db->execute(
            'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', $placeholders) . ')',
            $data,
        );

        return $this->find($slug, (int) $this->db->lastInsertId(), $options);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{public?: bool} $options
     * @return array<string, mixed>
     */
    public function patch(string $slug, int $id, array $payload, array $options = []): array
    {
        [, $table, $fieldMap] = $this->resolve($slug, $options);
        $existing = $this->db->selectOne(
            'SELECT id FROM `' . $table . '` WHERE id = :id AND `deleted_at` IS NULL',
            ['id' => $id],
        );
        if ($existing === null) {
            throw new RuntimeException('Resource not found', 404);
        }

        $data = $this->validatePayload($payload, $fieldMap, true);
        if ($data === []) {
            return $this->find($slug, $id, $options);
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

        return $this->find($slug, $id, $options);
    }

    /**
     * @param array{public?: bool} $options
     */
    public function delete(string $slug, int $id, array $options = []): void
    {
        [$resource, $table] = $this->resolve($slug, $options);
        $settings = $this->settingsOf($resource);
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
            throw new RuntimeException('Resource not found', 404);
        }
    }

    /**
     * @param array<string, string> $query
     * @param array{public?: bool} $options
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function listCustom(string $slug, string $apiSlug, array $query, array $options = []): array
    {
        [$resource, $table, $fieldMap, $api] = $this->resolveCustom($slug, $apiSlug, $options);
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
        if (!$public || ($settings['search'] ?? true)) {
            $this->applySearch($query, $fieldMap, $where, $params);
        } elseif (($query['search'] ?? '') !== '') {
            throw new InvalidArgumentException('Search is disabled for this resource');
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        if ($public && !($settings['sorting'] ?? true)) {
            if (isset($query['sort']) && $query['sort'] !== '' && $query['sort'] !== 'id') {
                throw new InvalidArgumentException('Sorting is disabled for this resource');
            }
            $orderSql = ' ORDER BY `id` ASC';
        } else {
            $orderSql = $this->orderSql($query, $fieldMap);
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
        [, $table, $fieldMap, $api] = $this->resolveCustom($slug, $apiSlug, $options);
        $selectSql = $this->selectSql($fieldMap, $api);
        $row = $this->db->selectOne(
            'SELECT ' . $selectSql . ' FROM `' . $table . '` WHERE id = :id AND `deleted_at` IS NULL',
            ['id' => $id],
        );
        if ($row === null) {
            throw new RuntimeException('Resource not found', 404);
        }

        $item = $this->serializeCustom($row, $fieldMap, $api);
        $items = [$item];
        $this->attachJoins($items, [$row], $api);

        return $items[0];
    }

    /**
     * @param array{public?: bool} $options
     * @return array{0: array<string, mixed>, 1: string, 2: array<string, array<string, mixed>>, 3: array<string, mixed>}
     */
    private function resolveCustom(string $slug, string $apiSlug, array $options = []): array
    {
        if ($this->apis === null) {
            throw new RuntimeException('Custom APIs are not available', 404);
        }
        [$resource, $table, $fieldMap] = $this->resolve($slug, $options);
        $apiRow = $this->apis->findByResourceAndSlug((int) $resource['id'], $apiSlug);
        if ($apiRow === null || !(bool) (int) ($apiRow['enabled'] ?? 0)) {
            throw new RuntimeException('Resource API not found', 404);
        }

        $methods = is_string($apiRow['methods_json'])
            ? json_decode((string) $apiRow['methods_json'], true)
            : $apiRow['methods_json'];
        $methods = is_array($methods) ? array_map('strval', $methods) : [];
        if (!in_array('GET', $methods, true)) {
            throw new RuntimeException('Method not allowed', 405);
        }

        $fields = $apiRow['fields_json'];
        if (is_string($fields)) {
            $fields = json_decode($fields, true);
        }
        $joins = is_string($apiRow['joins_json'])
            ? json_decode((string) $apiRow['joins_json'], true)
            : $apiRow['joins_json'];
        $apiSettings = is_string($apiRow['settings_json'])
            ? json_decode((string) $apiRow['settings_json'], true)
            : $apiRow['settings_json'];

        $api = [
            'id' => (int) $apiRow['id'],
            'slug' => (string) $apiRow['slug'],
            'fields' => is_array($fields) ? array_values(array_map('strval', $fields)) : null,
            'joins' => is_array($joins) ? array_values($joins) : [],
            'settings' => ResourceApiService::normalizeSettings(is_array($apiSettings) ? $apiSettings : []),
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
        $override = is_array($api['settings'] ?? null) ? $api['settings'] : [];
        foreach (['pagination', 'search', 'sorting', 'filtering'] as $key) {
            if (array_key_exists($key, $override)) {
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
                $config = is_array($meta['spec']['config'] ?? null) ? $meta['spec']['config'] : [];
                if (($meta['type'] ?? '') === 'relation' && ($config['cardinality'] ?? 'manyToOne') === 'oneToMany') {
                    continue;
                }
                $needed[$name] = true;
            }
        } else {
            foreach ($api['fields'] as $name) {
                if (is_string($name) && isset($fieldMap[$name])) {
                    $needed[$name] = true;
                }
            }
        }
        foreach ($api['joins'] as $join) {
            if (!is_array($join)) {
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
        if ($whitelist === null) {
            $out['createdAt'] = $row['created_at'] ?? null;
            $out['updatedAt'] = $row['updated_at'] ?? null;
            foreach ($fieldMap as $name => $meta) {
                $config = is_array($meta['spec']['config'] ?? null) ? $meta['spec']['config'] : [];
                if (($meta['type'] ?? '') === 'relation' && ($config['cardinality'] ?? 'manyToOne') === 'oneToMany') {
                    continue;
                }
                if (!($meta['spec']['readable'] ?? true) || ($meta['spec']['hidden'] ?? false)) {
                    continue;
                }
                $value = $row[$name] ?? null;
                if (($meta['type'] ?? '') === 'relation' && $value !== null) {
                    $value = (int) $value;
                }
                $out[$name] = $value;
            }

            return $out;
        }

        $allowed = array_fill_keys($whitelist, true);
        foreach ($whitelist as $name) {
            if (!isset($fieldMap[$name])) {
                continue;
            }
            $meta = $fieldMap[$name];
            $value = $row[$name] ?? null;
            if (($meta['type'] ?? '') === 'relation' && $value !== null) {
                $value = (int) $value;
            }
            $out[$name] = $value;
        }
        unset($allowed);

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $api
     */
    private function attachJoins(array &$items, array $rows, array $api): void
    {
        foreach ($api['joins'] as $join) {
            if (!is_array($join) || ($join['type'] ?? 'manyToOne') !== 'manyToOne') {
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
        if ($fields === null) {
            $out['createdAt'] = $row['created_at'] ?? null;
            $out['updatedAt'] = $row['updated_at'] ?? null;
            foreach ($fieldMap as $name => $meta) {
                $config = is_array($meta['spec']['config'] ?? null) ? $meta['spec']['config'] : [];
                if (($meta['type'] ?? '') === 'relation' && ($config['cardinality'] ?? 'manyToOne') === 'oneToMany') {
                    continue;
                }
                if (!($meta['spec']['readable'] ?? true) || ($meta['spec']['hidden'] ?? false)) {
                    continue;
                }
                $value = $row[$name] ?? null;
                if (($meta['type'] ?? '') === 'relation' && $value !== null) {
                    $value = (int) $value;
                }
                $out[$name] = $value;
            }

            return $out;
        }

        foreach ($fields as $name) {
            if (!isset($fieldMap[$name])) {
                continue;
            }
            $meta = $fieldMap[$name];
            $value = $row[$name] ?? null;
            if (($meta['type'] ?? '') === 'relation' && $value !== null) {
                $value = (int) $value;
            }
            $out[$name] = $value;
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
            $spec = is_string($field['spec_json'])
                ? json_decode((string) $field['spec_json'], true)
                : $field['spec_json'];
            $map[(string) $field['name']] = [
                'type' => $field['type'],
                'spec' => is_array($spec) ? $spec : [],
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
            throw new RuntimeException('Resource not found', 404);
        }
        $settings = $this->settingsOf($resource);
        if ($public && ($settings['apiEnabled'] ?? true) === false) {
            throw new RuntimeException('API disabled for resource', 403);
        }

        $table = MigrationService::tableName((string) $resource['content_type_slug']);
        $fields = $this->fields->forContentType((int) $resource['content_type_id']);
        $map = [];
        foreach ($fields as $field) {
            $spec = is_string($field['spec_json']) ? json_decode((string) $field['spec_json'], true) : $field['spec_json'];
            $map[(string) $field['name']] = [
                'type' => $field['type'],
                'spec' => is_array($spec) ? $spec : [],
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
        if (is_string($settings)) {
            $decoded = json_decode($settings, true);
            $settings = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($settings)) {
            $settings = [];
        }

        return ResourceService::normalizeSettings($settings);
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

    /**
     * @param array<string, array<string, mixed>> $fieldMap
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload, array $fieldMap, bool $partial): array
    {
        $out = [];
        foreach ($fieldMap as $name => $meta) {
            $spec = $meta['spec'];
            $type = (string) $meta['type'];
            $config = is_array($spec['config'] ?? null) ? $spec['config'] : [];
            if ($type === 'relation' && ($config['cardinality'] ?? 'manyToOne') === 'oneToMany') {
                continue;
            }
            if (!($spec['writable'] ?? true)) {
                continue;
            }
            if (!array_key_exists($name, $payload)) {
                if ($type === 'slug') {
                    continue;
                }
                if (!$partial && ($spec['required'] ?? false)) {
                    throw new InvalidArgumentException('Field required: ' . $name);
                }
                continue;
            }
            $value = $payload[$name];
            if ($value === null) {
                if (!($spec['nullable'] ?? true)) {
                    throw new InvalidArgumentException('Field not nullable: ' . $name);
                }
                $out[$name] = null;
                continue;
            }
            $out[$name] = $this->castValue($value, $type, $name);
        }

        foreach ($fieldMap as $name => $meta) {
            if ((string) $meta['type'] !== 'slug') {
                continue;
            }
            $spec = $meta['spec'];
            if (!($spec['writable'] ?? true)) {
                continue;
            }
            $config = is_array($spec['config'] ?? null) ? $spec['config'] : [];
            $associated = is_string($config['associatedWith'] ?? null) ? $config['associatedWith'] : '';
            $maxLength = (int) ($config['maxLength'] ?? 255);
            $current = $out[$name] ?? null;
            if (($current === null || $current === '') && $associated !== '') {
                $source = $out[$associated] ?? $payload[$associated] ?? null;
                if (is_scalar($source) && (string) $source !== '') {
                    $out[$name] = UrlSlug::from((string) $source, $maxLength);
                }
            } elseif (is_string($current) && $current !== '') {
                $out[$name] = UrlSlug::from($current, $maxLength);
            }

            if (
                !$partial
                && ($spec['required'] ?? false)
                && (!array_key_exists($name, $out) || $out[$name] === null || $out[$name] === '')
            ) {
                throw new InvalidArgumentException('Field required: ' . $name);
            }
        }

        return $out;
    }

    private function castValue(mixed $value, string $type, string $name): mixed
    {
        return match ($type) {
            'integer', 'relation', 'image', 'file' => is_numeric($value)
                ? (int) $value
                : throw new InvalidArgumentException('Invalid integer: ' . $name),
            'float' => is_numeric($value) ? (float) $value : throw new InvalidArgumentException('Invalid float: ' . $name),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw new InvalidArgumentException('Invalid boolean: ' . $name),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)
                ? $value
                : throw new InvalidArgumentException('Invalid email: ' . $name),
            'json' => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES),
            'slug' => is_scalar($value)
                ? UrlSlug::from((string) $value)
                : throw new InvalidArgumentException('Invalid value: ' . $name),
            default => is_scalar($value) ? (string) $value : throw new InvalidArgumentException('Invalid value: ' . $name),
        };
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
            $param = 'f_' . count($params);
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
            $param = 'in_' . count($params) . '_' . $i;
            $placeholders[] = ':' . $param;
            $params[$param] = $part;
        }
        $where[] = '`' . $field . '` IN (' . implode(', ', $placeholders) . ')';
    }

    /**
     * @param array<string, string> $query
     * @param array<string, array<string, mixed>> $fieldMap
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    private function applySearch(array $query, array $fieldMap, array &$where, array &$params): void
    {
        $search = $query['search'] ?? '';
        if ($search === '') {
            return;
        }
        $parts = [];
        foreach ($fieldMap as $name => $meta) {
            if ($meta['spec']['searchable'] ?? false) {
                $param = 's_' . count($params);
                $parts[] = '`' . $name . '` LIKE :' . $param;
                $params[$param] = '%' . $search . '%';
            }
        }
        if ($parts !== []) {
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }

    /**
     * @param array<string, string> $query
     * @param array<string, array<string, mixed>> $fieldMap
     */
    private function orderSql(array $query, array $fieldMap): string
    {
        $sort = $query['sort'] ?? 'id';
        $desc = str_starts_with($sort, '-');
        $field = ltrim($sort, '-');
        if ($field === 'id') {
            return ' ORDER BY `id` ' . ($desc ? 'DESC' : 'ASC');
        }
        if (!isset($fieldMap[$field]) || !($fieldMap[$field]['spec']['sortable'] ?? false)) {
            throw new InvalidArgumentException('Field not sortable: ' . $field);
        }

        return ' ORDER BY `' . $field . '` ' . ($desc ? 'DESC' : 'ASC');
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $fieldMap
     * @return array<string, mixed>
     */
    private function serialize(array $row, array $fieldMap): array
    {
        $out = [
            'id' => (int) $row['id'],
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
        foreach ($fieldMap as $name => $meta) {
            $config = is_array($meta['spec']['config'] ?? null) ? $meta['spec']['config'] : [];
            if (($meta['type'] ?? '') === 'relation' && ($config['cardinality'] ?? 'manyToOne') === 'oneToMany') {
                continue;
            }
            if (!($meta['spec']['readable'] ?? true) || ($meta['spec']['hidden'] ?? false)) {
                continue;
            }
            $value = $row[$name] ?? null;
            if (($meta['type'] ?? '') === 'relation' && $value !== null) {
                $value = (int) $value;
            }
            $out[$name] = $value;
        }

        return $out;
    }
}
