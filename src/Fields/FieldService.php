<?php

declare(strict_types=1);

namespace Cms\Fields;

use Cms\Content\Slug;
use Cms\Core\MetadataCache;
use Cms\Database\Connection;
use Cms\Resources\ResourceRepository;
use InvalidArgumentException;
use RuntimeException;

final class FieldService
{
    public function __construct(
        private readonly Connection $db,
        private readonly FieldRepository $fields,
        private readonly ResourceRepository $resources,
        private readonly FieldTypeRegistry $types,
        private readonly ?MetadataCache $metadata = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForResource(int $resourceId): array
    {
        $resource = $this->resources->find($resourceId);
        if ($resource === null) {
            throw new RuntimeException('Resource not found', 404);
        }

        return array_map(
            [$this, 'serialize'],
            $this->fields->forContentType((int) $resource['content_type_id']),
        );
    }

    /**
     * Replace entire schema for a resource's content type.
     *
     * @param list<array<string, mixed>> $incoming
     * @return list<array<string, mixed>>
     */
    public function replaceSchema(int $resourceId, array $incoming): array
    {
        $resource = $this->resources->find($resourceId);
        if ($resource === null) {
            throw new RuntimeException('Resource not found', 404);
        }
        if (($resource['status'] ?? '') === 'published' && (int) $resource['schema_version'] > 0) {
            // Allow schema edits on draft always; published edits are metadata-only until Phase 5 migrations
        }

        $contentTypeId = (int) $resource['content_type_id'];
        $normalized = [];
        $names = [];
        foreach ($incoming as $index => $field) {
            $normalized[] = $this->normalizeIncoming($field, $index, $names);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->fields->deleteForContentType($contentTypeId);
            foreach ($normalized as $field) {
                $this->fields->create([
                    'content_type_id' => $contentTypeId,
                    'name' => $field['name'],
                    'type' => $field['type'],
                    'sort_order' => $field['sort_order'],
                    'spec' => $field['spec'],
                ]);
            }
            $this->db->execute(
                'UPDATE cms_resources SET updated_at = :now WHERE id = :id',
                ['now' => date('Y-m-d H:i:s'), 'id' => $resourceId],
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->metadata?->invalidate();

        return $this->listForResource($resourceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(int $resourceId, array $payload): array
    {
        $resource = $this->resources->find($resourceId);
        if ($resource === null) {
            throw new RuntimeException('Resource not found', 404);
        }
        $contentTypeId = (int) $resource['content_type_id'];
        $existing = $this->fields->forContentType($contentTypeId);
        $names = [];
        foreach ($existing as $row) {
            $names[(string) $row['name']] = true;
        }
        $field = $this->normalizeIncoming($payload, count($existing), $names);
        $created = $this->fields->create([
            'content_type_id' => $contentTypeId,
            'name' => $field['name'],
            'type' => $field['type'],
            'sort_order' => $field['sort_order'],
            'spec' => $field['spec'],
        ]);

        $this->metadata?->invalidate();

        return $this->serialize($created);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $fieldId, array $payload): array
    {
        $existing = $this->fields->find($fieldId);
        if ($existing === null) {
            throw new RuntimeException('Field not found', 404);
        }

        $names = [];
        foreach ($this->fields->forContentType((int) $existing['content_type_id']) as $row) {
            if ((int) $row['id'] !== $fieldId) {
                $names[(string) $row['name']] = true;
            }
        }

        $merged = array_merge($this->serialize($existing), $payload);
        $field = $this->normalizeIncoming($merged, (int) $existing['sort_order'], $names);
        $updated = $this->fields->update($fieldId, [
            'name' => $field['name'],
            'type' => $field['type'],
            'sort_order' => $field['sort_order'],
            'spec' => $field['spec'],
        ]);

        $this->metadata?->invalidate();

        return $this->serialize($updated);
    }

    public function delete(int $fieldId): void
    {
        $existing = $this->fields->find($fieldId);
        if ($existing === null) {
            throw new RuntimeException('Field not found', 404);
        }
        $this->fields->delete($fieldId);
        $this->metadata?->invalidate();
    }

    /**
     * @return list<string>
     */
    public function availableTypes(): array
    {
        return $this->types->names();
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, true> $usedNames
     * @return array{name: string, type: string, sort_order: int, spec: array<string, mixed>}
     */
    private function normalizeIncoming(array $field, int $index, array &$usedNames): array
    {
        $name = isset($field['name']) && is_string($field['name']) ? trim($field['name']) : '';
        $type = isset($field['type']) && is_string($field['type']) ? trim($field['type']) : '';
        if ($name === '' || !Slug::isValid($name)) {
            throw new InvalidArgumentException('Invalid field name: ' . $name);
        }
        if (isset($usedNames[$name])) {
            throw new InvalidArgumentException('Duplicate field name: ' . $name);
        }
        if (!$this->types->has($type)) {
            throw new InvalidArgumentException('Unknown field type: ' . $type);
        }
        $usedNames[$name] = true;

        $fieldType = $this->types->get($type);
        $spec = FieldSpec::fromArray(array_merge(
            [
                'label' => $field['label'] ?? $name,
                'config' => array_merge(
                    $fieldType->defaultConfig(),
                    isset($field['config']) && is_array($field['config']) ? $field['config'] : [],
                ),
            ],
            $field,
        ));
        $fieldType->validateConfig($spec->config);

        return [
            'name' => $name,
            'type' => $type,
            'sort_order' => isset($field['sortOrder']) ? (int) $field['sortOrder'] : $index,
            'spec' => $spec->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        $spec = $row['spec_json'] ?? [];
        if (is_string($spec)) {
            $decoded = json_decode($spec, true);
            $spec = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => (int) $row['id'],
            'contentTypeId' => (int) $row['content_type_id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'sortOrder' => (int) $row['sort_order'],
            'label' => $spec['label'] ?? $row['name'],
            'description' => $spec['description'] ?? null,
            'required' => (bool) ($spec['required'] ?? false),
            'nullable' => (bool) ($spec['nullable'] ?? true),
            'unique' => (bool) ($spec['unique'] ?? false),
            'indexed' => (bool) ($spec['indexed'] ?? false),
            'default' => $spec['default'] ?? null,
            'readonly' => (bool) ($spec['readonly'] ?? false),
            'hidden' => (bool) ($spec['hidden'] ?? false),
            'searchable' => (bool) ($spec['searchable'] ?? false),
            'sortable' => (bool) ($spec['sortable'] ?? false),
            'filterable' => (bool) ($spec['filterable'] ?? false),
            'readable' => (bool) ($spec['readable'] ?? true),
            'writable' => (bool) ($spec['writable'] ?? true),
            'config' => is_array($spec['config'] ?? null) ? $spec['config'] : [],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }
}
