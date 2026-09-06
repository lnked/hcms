<?php

declare(strict_types=1);

namespace Cms\Resources;

use Cms\Content\ContentTypeRepository;
use Cms\Content\Slug;
use Cms\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

final class ResourceService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ContentTypeRepository $contentTypes,
        private readonly ResourceRepository $resources,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_map([$this, 'serialize'], $this->resources->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $row = $this->resources->find($id);
        if ($row === null) {
            throw new RuntimeException('Resource not found', 404);
        }

        return $this->serialize($row);
    }

    /**
     * Create ContentType + Resource atomically (MVP 1:1).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $name = isset($payload['name']) && is_string($payload['name']) ? trim($payload['name']) : '';
        $label = isset($payload['label']) && is_string($payload['label']) ? trim($payload['label']) : '';
        $description = isset($payload['description']) && is_string($payload['description'])
            ? trim($payload['description'])
            : null;
        $slug = isset($payload['slug']) && is_string($payload['slug']) && $payload['slug'] !== ''
            ? trim($payload['slug'])
            : Slug::fromName($name !== '' ? $name : $label);
        $endpoint = isset($payload['endpoint']) && is_string($payload['endpoint']) && $payload['endpoint'] !== ''
            ? trim($payload['endpoint'])
            : '/api/' . $slug;

        if ($label === '') {
            throw new InvalidArgumentException('Label is required');
        }
        if ($name === '') {
            $name = $slug;
        }
        if (!Slug::isValid($slug)) {
            throw new InvalidArgumentException('Invalid slug. Use a-z, 0-9, underscore; start with a letter.');
        }
        if ($this->contentTypes->findBySlug($slug) !== null || $this->resources->findBySlug($slug) !== null) {
            throw new InvalidArgumentException('Slug already exists');
        }
        if (!str_starts_with($endpoint, '/')) {
            $endpoint = '/' . $endpoint;
        }

        $settings = self::defaultSettings(
            isset($payload['settings']) && is_array($payload['settings']) ? $payload['settings'] : [],
        );

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $contentType = $this->contentTypes->create([
                'name' => $name,
                'slug' => $slug,
                'label' => $label,
                'description' => $description === '' ? null : $description,
            ]);
            $resource = $this->resources->create([
                'content_type_id' => (int) $contentType['id'],
                'slug' => $slug,
                'endpoint' => $endpoint,
                'api_version' => 'v1',
                'status' => 'draft',
                'settings' => $settings,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $this->serialize($resource);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $existing = $this->resources->find($id);
        if ($existing === null) {
            throw new RuntimeException('Resource not found', 404);
        }

        $update = [];
        if (isset($payload['endpoint']) && is_string($payload['endpoint'])) {
            $endpoint = trim($payload['endpoint']);
            $update['endpoint'] = str_starts_with($endpoint, '/') ? $endpoint : '/' . $endpoint;
        }
        if (isset($payload['status']) && is_string($payload['status'])) {
            $status = $payload['status'];
            if (!in_array($status, ['draft', 'published', 'archived'], true)) {
                throw new InvalidArgumentException('Invalid status');
            }
            $update['status'] = $status;
        }
        if (isset($payload['settings']) && is_array($payload['settings'])) {
            $existingSettings = $existing['settings_json'] ?? [];
            if (is_string($existingSettings)) {
                $decoded = json_decode($existingSettings, true);
                $existingSettings = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($existingSettings)) {
                $existingSettings = [];
            }
            $update['settings'] = self::normalizeSettings(
                array_replace_recursive($existingSettings, $payload['settings']),
            );
        }

        $resource = $this->resources->update($id, $update);

        if (isset($payload['label']) || isset($payload['description']) || isset($payload['name'])) {
            $ctUpdate = [];
            if (isset($payload['label']) && is_string($payload['label'])) {
                $ctUpdate['label'] = trim($payload['label']);
            }
            if (isset($payload['name']) && is_string($payload['name'])) {
                $ctUpdate['name'] = trim($payload['name']);
            }
            if (array_key_exists('description', $payload)) {
                $ctUpdate['description'] = is_string($payload['description'])
                    ? trim($payload['description'])
                    : null;
            }
            $this->contentTypes->update((int) $existing['content_type_id'], $ctUpdate);
            $resource = $this->resources->find($id);
            if ($resource === null) {
                throw new RuntimeException('Resource not found', 404);
            }
        }

        return $this->serialize($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function publish(int $id): array
    {
        return $this->update($id, ['status' => 'published']);
    }

    public function delete(int $id): void
    {
        $existing = $this->resources->find($id);
        if ($existing === null) {
            throw new RuntimeException('Resource not found', 404);
        }
        if ((int) ($existing['is_system'] ?? 0) === 1) {
            throw new InvalidArgumentException('System resources cannot be deleted');
        }

        $contentTypeId = (int) $existing['content_type_id'];
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->resources->delete($id);
            $this->contentTypes->delete($contentTypeId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public static function defaultSettings(array $override = []): array
    {
        return self::normalizeSettings(array_replace_recursive([
            'apiEnabled' => true,
            'public' => [
                'read' => false,
                'create' => false,
                'update' => false,
                'delete' => false,
            ],
            'pagination' => true,
            'search' => true,
            'sorting' => true,
            'filtering' => true,
            'deleteStrategy' => 'hard',
            'softDelete' => false,
        ], $override));
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function normalizeSettings(array $settings): array
    {
        $public = is_array($settings['public'] ?? null) ? $settings['public'] : [];
        $strategy = ($settings['deleteStrategy'] ?? 'hard') === 'soft' ? 'soft' : 'hard';
        $softDelete = $strategy === 'soft' || (bool) ($settings['softDelete'] ?? false);

        return [
            'apiEnabled' => (bool) ($settings['apiEnabled'] ?? true),
            'public' => [
                'read' => (bool) ($public['read'] ?? false),
                'create' => (bool) ($public['create'] ?? false),
                'update' => (bool) ($public['update'] ?? false),
                'delete' => (bool) ($public['delete'] ?? false),
            ],
            'pagination' => (bool) ($settings['pagination'] ?? true),
            'search' => (bool) ($settings['search'] ?? true),
            'sorting' => (bool) ($settings['sorting'] ?? true),
            'filtering' => (bool) ($settings['filtering'] ?? true),
            'deleteStrategy' => $softDelete ? 'soft' : 'hard',
            'softDelete' => $softDelete,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        $settings = $row['settings_json'] ?? [];
        if (is_string($settings)) {
            $decoded = json_decode($settings, true);
            $settings = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($settings)) {
            $settings = [];
        }

        return [
            'id' => (int) $row['id'],
            'contentTypeId' => (int) $row['content_type_id'],
            'slug' => $row['slug'],
            'endpoint' => $row['endpoint'],
            'apiVersion' => $row['api_version'],
            'status' => $row['status'],
            'schemaVersion' => (int) $row['schema_version'],
            'settings' => self::normalizeSettings($settings),
            'label' => $row['content_type_label'] ?? $row['slug'],
            'contentTypeSlug' => $row['content_type_slug'] ?? $row['slug'],
            'isSystem' => (int) ($row['is_system'] ?? 0) === 1,
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }
}
