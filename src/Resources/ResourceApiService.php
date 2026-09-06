<?php

declare(strict_types=1);

namespace Cms\Resources;

use Cms\Content\Slug;
use Cms\Core\MetadataCache;
use Cms\Fields\FieldRepository;
use InvalidArgumentException;
use RuntimeException;

final class ResourceApiService
{
    public function __construct(
        private readonly ResourceRepository $resources,
        private readonly ResourceApiRepository $apis,
        private readonly FieldRepository $fields,
        private readonly ?MetadataCache $metadata = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $resourceId): array
    {
        $resource = $this->requireResource($resourceId);

        return array_map(
            fn (array $row): array => $this->serialize($row, $resource),
            $this->apis->forResource($resourceId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $resourceId, int $apiId): array
    {
        $resource = $this->requireResource($resourceId);
        $row = $this->apis->find($apiId);
        if ($row === null || (int) $row['resource_id'] !== $resourceId) {
            throw new RuntimeException('Resource API not found', 404);
        }

        return $this->serialize($row, $resource);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(int $resourceId, array $payload): array
    {
        $resource = $this->requireResource($resourceId);
        $normalized = $this->normalizePayload($payload, $resource, null);
        $row = $this->apis->create([
            'resource_id' => $resourceId,
            ...$normalized,
        ]);
        $this->metadata?->invalidate();

        return $this->serialize($row, $resource);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $resourceId, int $apiId, array $payload): array
    {
        $resource = $this->requireResource($resourceId);
        $existing = $this->apis->find($apiId);
        if ($existing === null || (int) $existing['resource_id'] !== $resourceId) {
            throw new RuntimeException('Resource API not found', 404);
        }

        $normalized = $this->normalizePayload($payload, $resource, $existing);
        $row = $this->apis->update($apiId, $normalized);
        $this->metadata?->invalidate();

        return $this->serialize($row, $resource);
    }

    public function delete(int $resourceId, int $apiId): void
    {
        $this->requireResource($resourceId);
        $existing = $this->apis->find($apiId);
        if ($existing === null || (int) $existing['resource_id'] !== $resourceId) {
            throw new RuntimeException('Resource API not found', 404);
        }
        $this->apis->delete($apiId);
        $this->metadata?->invalidate();
    }

    /**
     * @return array<string, mixed>
     */
    private function requireResource(int $resourceId): array
    {
        $resource = $this->resources->find($resourceId);
        if ($resource === null) {
            throw new RuntimeException('Resource not found', 404);
        }

        return $resource;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $resource
     * @param array<string, mixed>|null $existing
     * @return array{
     *   slug: string,
     *   label: string,
     *   enabled: bool,
     *   methods: list<string>,
     *   fields: list<string>|null,
     *   joins: list<array<string, mixed>>,
     *   settings: array<string, mixed>
     * }
     */
    private function normalizePayload(array $payload, array $resource, ?array $existing): array
    {
        $slug = isset($payload['slug']) && is_string($payload['slug'])
            ? trim($payload['slug'])
            : (string) ($existing['slug'] ?? '');
        $label = isset($payload['label']) && is_string($payload['label'])
            ? trim($payload['label'])
            : (string) ($existing['label'] ?? '');

        if ($label === '') {
            throw new InvalidArgumentException('Label is required');
        }
        if (!self::isValidApiSlug($slug)) {
            throw new InvalidArgumentException(
                'Invalid API slug. Use a-z, 0-9, underscore or hyphen; start with a letter; not numeric-only.',
            );
        }

        $resourceId = (int) $resource['id'];
        $duplicate = $this->apis->findByResourceAndSlug($resourceId, $slug);
        if ($duplicate !== null && ($existing === null || (int) $duplicate['id'] !== (int) $existing['id'])) {
            throw new InvalidArgumentException('API slug already exists for this resource');
        }

        $enabled = array_key_exists('enabled', $payload)
            ? (bool) $payload['enabled']
            : ($existing !== null ? (bool) (int) $existing['enabled'] : true);

        $methods = ['GET'];
        if (isset($payload['methods']) && is_array($payload['methods'])) {
            $methods = [];
            foreach ($payload['methods'] as $method) {
                if (!is_string($method)) {
                    continue;
                }
                $method = strtoupper(trim($method));
                if ($method === 'GET') {
                    $methods[] = 'GET';
                }
            }
            $methods = array_values(array_unique($methods));
        } elseif ($existing !== null) {
            $decoded = is_string($existing['methods_json'])
                ? json_decode((string) $existing['methods_json'], true)
                : $existing['methods_json'];
            $methods = is_array($decoded) ? array_values(array_map('strval', $decoded)) : ['GET'];
        }
        if ($methods === []) {
            $methods = ['GET'];
        }

        $fieldMap = $this->fieldMapForResource($resource);
        $fields = $this->normalizeFields(
            array_key_exists('fields', $payload) ? $payload['fields'] : ($existing['fields_json'] ?? null),
            $fieldMap,
        );

        $joinsRaw = array_key_exists('joins', $payload)
            ? $payload['joins']
            : ($existing['joins_json'] ?? []);
        if (is_string($joinsRaw)) {
            $decodedJoins = json_decode($joinsRaw, true);
            $joinsRaw = is_array($decodedJoins) ? $decodedJoins : [];
        }
        if (!is_array($joinsRaw)) {
            $joinsRaw = [];
        }
        $joins = $this->normalizeJoins($joinsRaw, $fieldMap);

        $settingsRaw = array_key_exists('settings', $payload)
            ? $payload['settings']
            : ($existing['settings_json'] ?? []);
        if (is_string($settingsRaw)) {
            $decodedSettings = json_decode($settingsRaw, true);
            $settingsRaw = is_array($decodedSettings) ? $decodedSettings : [];
        }
        if (!is_array($settingsRaw)) {
            $settingsRaw = [];
        }
        $settings = self::normalizeSettings($settingsRaw);

        return [
            'slug' => $slug,
            'label' => $label,
            'enabled' => $enabled,
            'methods' => $methods,
            'fields' => $fields,
            'joins' => $joins,
            'settings' => $settings,
        ];
    }

    /**
     * @param mixed $fields
     * @param array<string, array<string, mixed>> $fieldMap
     * @return list<string>|null
     */
    private function normalizeFields(mixed $fields, array $fieldMap): ?array
    {
        if ($fields === null) {
            return null;
        }
        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            $fields = is_array($decoded) ? $decoded : null;
            if ($fields === null) {
                return null;
            }
        }
        if (!is_array($fields)) {
            throw new InvalidArgumentException('fields must be an array or null');
        }
        if ($fields === []) {
            return [];
        }

        $out = [];
        foreach ($fields as $name) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Invalid field name in fields list');
            }
            if ($name === 'id' || $name === 'createdAt' || $name === 'updatedAt'
                || $name === 'created_at' || $name === 'updated_at') {
                continue;
            }
            if (!isset($fieldMap[$name])) {
                throw new InvalidArgumentException('Unknown field: ' . $name);
            }
            $config = is_array($fieldMap[$name]['spec']['config'] ?? null)
                ? $fieldMap[$name]['spec']['config']
                : [];
            if (($fieldMap[$name]['type'] ?? '') === 'relation'
                && ($config['cardinality'] ?? 'manyToOne') === 'oneToMany') {
                throw new InvalidArgumentException('oneToMany relation cannot be projected: ' . $name);
            }
            $out[] = $name;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<mixed> $joins
     * @param array<string, array<string, mixed>> $fieldMap
     * @return list<array{
     *   as: string,
     *   relatedSlug: string,
     *   localField: string,
     *   foreignField: string,
     *   fields: list<string>|null,
     *   type: string
     * }>
     */
    private function normalizeJoins(array $joins, array $fieldMap): array
    {
        $out = [];
        $aliases = [];
        foreach ($joins as $join) {
            if (!is_array($join)) {
                throw new InvalidArgumentException('Invalid join definition');
            }
            $as = isset($join['as']) && is_string($join['as']) ? trim($join['as']) : '';
            $relatedSlug = isset($join['relatedSlug']) && is_string($join['relatedSlug'])
                ? trim($join['relatedSlug'])
                : '';
            $localField = isset($join['localField']) && is_string($join['localField'])
                ? trim($join['localField'])
                : '';
            $foreignField = isset($join['foreignField']) && is_string($join['foreignField'])
                ? trim($join['foreignField'])
                : 'id';
            $type = isset($join['type']) && is_string($join['type'])
                ? trim($join['type'])
                : 'manyToOne';

            if ($as === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $as)) {
                throw new InvalidArgumentException('Invalid join alias');
            }
            if (isset($aliases[$as])) {
                throw new InvalidArgumentException('Duplicate join alias: ' . $as);
            }
            if ($relatedSlug === '' || !Slug::isValid($relatedSlug)) {
                throw new InvalidArgumentException('Invalid relatedSlug in join');
            }
            if ($localField === '' || !isset($fieldMap[$localField])) {
                throw new InvalidArgumentException('Unknown localField: ' . $localField);
            }
            if ($foreignField === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $foreignField)) {
                throw new InvalidArgumentException('Invalid foreignField');
            }
            if ($type !== 'manyToOne') {
                throw new InvalidArgumentException('Only manyToOne joins are supported');
            }

            $related = $this->resources->findBySlug($relatedSlug);
            if ($related === null || ($related['status'] ?? '') !== 'published') {
                throw new InvalidArgumentException('Related resource must be published: ' . $relatedSlug);
            }

            $relatedFieldMap = $this->fieldMapForResource($related);
            $joinFields = null;
            if (array_key_exists('fields', $join)) {
                if ($join['fields'] === null) {
                    $joinFields = null;
                } elseif (!is_array($join['fields'])) {
                    throw new InvalidArgumentException('join.fields must be an array or null');
                } else {
                    $joinFields = [];
                    foreach ($join['fields'] as $name) {
                        if (!is_string($name) || $name === '') {
                            throw new InvalidArgumentException('Invalid join field name');
                        }
                        if ($name === 'id') {
                            continue;
                        }
                        if (!isset($relatedFieldMap[$name])) {
                            throw new InvalidArgumentException(
                                'Unknown field on related resource ' . $relatedSlug . ': ' . $name,
                            );
                        }
                        $joinFields[] = $name;
                    }
                    $joinFields = array_values(array_unique($joinFields));
                }
            }

            if ($foreignField !== 'id' && !isset($relatedFieldMap[$foreignField])) {
                throw new InvalidArgumentException(
                    'Unknown foreignField on related resource ' . $relatedSlug . ': ' . $foreignField,
                );
            }

            $aliases[$as] = true;
            $out[] = [
                'as' => $as,
                'relatedSlug' => $relatedSlug,
                'localField' => $localField,
                'foreignField' => $foreignField,
                'fields' => $joinFields,
                'type' => 'manyToOne',
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $resource
     * @return array<string, array<string, mixed>>
     */
    private function fieldMapForResource(array $resource): array
    {
        $rows = $this->fields->forContentType((int) $resource['content_type_id']);
        $map = [];
        foreach ($rows as $field) {
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
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function normalizeSettings(array $settings): array
    {
        $public = is_array($settings['public'] ?? null) ? $settings['public'] : [];

        return [
            'pagination' => array_key_exists('pagination', $settings)
                ? (bool) $settings['pagination']
                : true,
            'search' => array_key_exists('search', $settings) ? (bool) $settings['search'] : true,
            'sorting' => array_key_exists('sorting', $settings) ? (bool) $settings['sorting'] : true,
            'filtering' => array_key_exists('filtering', $settings) ? (bool) $settings['filtering'] : true,
            'public' => [
                'read' => array_key_exists('read', $public) ? (bool) $public['read'] : null,
            ],
        ];
    }

    public static function isValidApiSlug(string $slug): bool
    {
        if ($slug === '' || ctype_digit($slug)) {
            return false;
        }

        return (bool) preg_match('/^[a-z][a-z0-9_-]{0,62}$/', $slug);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private function serialize(array $row, array $resource): array
    {
        $methods = is_string($row['methods_json'])
            ? json_decode((string) $row['methods_json'], true)
            : $row['methods_json'];
        $fields = $row['fields_json'];
        if (is_string($fields)) {
            $fields = json_decode($fields, true);
        }
        $joins = is_string($row['joins_json'])
            ? json_decode((string) $row['joins_json'], true)
            : $row['joins_json'];
        $settings = is_string($row['settings_json'])
            ? json_decode((string) $row['settings_json'], true)
            : $row['settings_json'];

        $resourceSlug = (string) $resource['slug'];
        $apiSlug = (string) $row['slug'];
        $base = rtrim((string) ($resource['endpoint'] ?? ('/api/' . $resourceSlug)), '/');

        return [
            'id' => (int) $row['id'],
            'resourceId' => (int) $row['resource_id'],
            'slug' => $apiSlug,
            'label' => (string) $row['label'],
            'enabled' => (bool) (int) $row['enabled'],
            'methods' => is_array($methods) ? array_values(array_map('strval', $methods)) : ['GET'],
            'fields' => is_array($fields) ? array_values(array_map('strval', $fields)) : null,
            'joins' => is_array($joins) ? array_values($joins) : [],
            'settings' => self::normalizeSettings(is_array($settings) ? $settings : []),
            'path' => $base . '/' . $apiSlug,
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }
}
