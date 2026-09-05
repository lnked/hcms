<?php

declare(strict_types=1);

namespace Cms\Api;

use Cms\Database\Connection;
use Cms\Database\MigrationService;
use Cms\Fields\FieldRepository;
use Cms\Resources\ResourceRepository;
use InvalidArgumentException;
use RuntimeException;

final class QueryEngine
{
    public function __construct(
        private readonly Connection $db,
        private readonly ResourceRepository $resources,
        private readonly FieldRepository $fields,
    ) {
    }

    /**
     * @param array<string, string> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(string $slug, array $query): array
    {
        [$resource, $table, $fieldMap] = $this->resolve($slug);
        unset($resource);

        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        $this->applyFilters($query, $fieldMap, $where, $params);
        $this->applySearch($query, $fieldMap, $where, $params);

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $orderSql = $this->orderSql($query, $fieldMap);

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
     * @return array<string, mixed>
     */
    public function find(string $slug, int $id): array
    {
        [, $table, $fieldMap] = $this->resolve($slug);
        $row = $this->db->selectOne('SELECT * FROM `' . $table . '` WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw new RuntimeException('Resource not found', 404);
        }

        return $this->serialize($row, $fieldMap);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(string $slug, array $payload): array
    {
        [, $table, $fieldMap] = $this->resolve($slug);
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

        return $this->find($slug, (int) $this->db->lastInsertId());
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function patch(string $slug, int $id, array $payload): array
    {
        [, $table, $fieldMap] = $this->resolve($slug);
        $existing = $this->db->selectOne('SELECT id FROM `' . $table . '` WHERE id = :id', ['id' => $id]);
        if ($existing === null) {
            throw new RuntimeException('Resource not found', 404);
        }

        $data = $this->validatePayload($payload, $fieldMap, true);
        if ($data === []) {
            return $this->find($slug, $id);
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

        return $this->find($slug, $id);
    }

    public function delete(string $slug, int $id): void
    {
        [, $table] = $this->resolve($slug);
        $affected = $this->db->execute('DELETE FROM `' . $table . '` WHERE id = :id', ['id' => $id]);
        if ($affected === 0) {
            throw new RuntimeException('Resource not found', 404);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: array<string, array<string, mixed>>}
     */
    private function resolve(string $slug): array
    {
        $resource = $this->resources->findBySlug($slug);
        if ($resource === null || ($resource['status'] ?? '') !== 'published') {
            throw new RuntimeException('Resource not found', 404);
        }
        $settings = is_string($resource['settings_json'])
            ? json_decode((string) $resource['settings_json'], true)
            : $resource['settings_json'];
        if (is_array($settings) && ($settings['apiEnabled'] ?? true) === false) {
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
     * @param array<string, array<string, mixed>> $fieldMap
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload, array $fieldMap, bool $partial): array
    {
        $out = [];
        foreach ($fieldMap as $name => $meta) {
            $spec = $meta['spec'];
            if (!($spec['writable'] ?? true)) {
                continue;
            }
            if (!array_key_exists($name, $payload)) {
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
            $out[$name] = $this->castValue($value, (string) $meta['type'], $name);
        }

        return $out;
    }

    private function castValue(mixed $value, string $type, string $name): mixed
    {
        return match ($type) {
            'integer' => is_numeric($value) ? (int) $value : throw new InvalidArgumentException('Invalid integer: ' . $name),
            'float' => is_numeric($value) ? (float) $value : throw new InvalidArgumentException('Invalid float: ' . $name),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw new InvalidArgumentException('Invalid boolean: ' . $name),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)
                ? $value
                : throw new InvalidArgumentException('Invalid email: ' . $name),
            'json' => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES),
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
            if (!($meta['spec']['readable'] ?? true) || ($meta['spec']['hidden'] ?? false)) {
                continue;
            }
            $out[$name] = $row[$name] ?? null;
        }

        return $out;
    }
}
