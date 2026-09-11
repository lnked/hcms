<?php

declare(strict_types=1);

namespace Cms\Resources;

use Cms\Content\ContentTypeRepository;
use Cms\Content\Slug;
use Cms\Core\MetadataCache;
use Cms\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

final class ResourceService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ContentTypeRepository $contentTypes,
        private readonly ResourceRepository $resources,
        private readonly ?MetadataCache $metadata = null,
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
        $name = isset($payload['name']) && \is_string($payload['name']) ? trim($payload['name']) : '';
        $label = isset($payload['label']) && \is_string($payload['label']) ? trim($payload['label']) : '';
        $description = isset($payload['description']) && \is_string($payload['description'])
            ? trim($payload['description'])
            : null;
        $slug = isset($payload['slug']) && \is_string($payload['slug']) && $payload['slug'] !== ''
            ? trim($payload['slug'])
            : Slug::fromName($name !== '' ? $name : $label);
        $endpoint = isset($payload['endpoint']) && \is_string($payload['endpoint']) && $payload['endpoint'] !== ''
            ? self::normalizeEndpoint($payload['endpoint'])
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
        if ($this->resources->findPublicKeyConflict($slug) !== null) {
            throw new InvalidArgumentException('Slug conflicts with an existing endpoint');
        }
        $this->assertEndpointAvailable($endpoint);

        $settings = self::defaultSettings(
            isset($payload['settings']) && \is_array($payload['settings']) ? $payload['settings'] : [],
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

        $this->metadata?->invalidate();

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
        if (isset($payload['endpoint']) && \is_string($payload['endpoint'])) {
            $endpoint = self::normalizeEndpoint($payload['endpoint']);
            $this->assertEndpointAvailable($endpoint, $id);
            $update['endpoint'] = $endpoint;
        }
        if (isset($payload['status']) && \is_string($payload['status'])) {
            $status = $payload['status'];
            if (!\in_array($status, ['draft', 'published', 'archived'], true)) {
                throw new InvalidArgumentException('Invalid status');
            }
            $update['status'] = $status;
        }
        if (isset($payload['settings']) && \is_array($payload['settings'])) {
            $existingSettings = $existing['settings_json'] ?? [];
            if (\is_string($existingSettings)) {
                $decoded = json_decode($existingSettings, true);
                $existingSettings = \is_array($decoded) ? $decoded : [];
            }
            if (!\is_array($existingSettings)) {
                $existingSettings = [];
            }
            $update['settings'] = self::normalizeSettings(
                self::mergeSettings($existingSettings, $payload['settings']),
            );
        }

        $resource = $this->resources->update($id, $update);

        if (isset($payload['label']) || isset($payload['description']) || isset($payload['name'])) {
            $ctUpdate = [];
            if (isset($payload['label']) && \is_string($payload['label'])) {
                $ctUpdate['label'] = trim($payload['label']);
            }
            if (isset($payload['name']) && \is_string($payload['name'])) {
                $ctUpdate['name'] = trim($payload['name']);
            }
            if (\array_key_exists('description', $payload)) {
                $ctUpdate['description'] = \is_string($payload['description'])
                    ? trim($payload['description'])
                    : null;
            }
            $this->contentTypes->update((int) $existing['content_type_id'], $ctUpdate);
            $resource = $this->resources->find($id);
            if ($resource === null) {
                throw new RuntimeException('Resource not found', 404);
            }
        }

        $this->metadata?->invalidate();

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

        $this->metadata?->invalidate();
    }

    public static function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint !== '' && !str_starts_with($endpoint, '/')) {
            $endpoint = '/' . $endpoint;
        }

        return rtrim($endpoint, '/') ?: '/';
    }

    public static function isValidEndpoint(string $endpoint): bool
    {
        return (bool) preg_match('#^/api(?:/v1)?/[a-z][a-z0-9_-]{0,62}$#', $endpoint);
    }

    public static function publicKeyFromEndpoint(string $endpoint): ?string
    {
        if (preg_match('#^/api(?:/v1)?/([a-z][a-z0-9_-]{0,62})$#', $endpoint, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function assertEndpointAvailable(string $endpoint, ?int $exceptId = null): void
    {
        if (!self::isValidEndpoint($endpoint)) {
            throw new InvalidArgumentException(
                'Invalid endpoint. Use /api/{slug} or /api/v1/{slug} with a-z, 0-9, underscore or hyphen.',
            );
        }
        $key = self::publicKeyFromEndpoint($endpoint);
        if ($key === null) {
            throw new InvalidArgumentException('Invalid endpoint');
        }
        if ($this->resources->findByEndpoint($endpoint, $exceptId) !== null) {
            throw new InvalidArgumentException('Endpoint already exists');
        }
        if ($this->resources->findPublicKeyConflict($key, $exceptId) !== null) {
            throw new InvalidArgumentException('Endpoint conflicts with another resource slug or endpoint');
        }
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public static function defaultSettings(array $override = []): array
    {
        return self::normalizeSettings(self::mergeSettings([
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
            'list' => [
                'columns' => [],
            ],
            'deleteStrategy' => 'hard',
            'softDelete' => false,
            'spam' => [
                'honeypotField' => '',
                'minSubmitMs' => 0,
                'rateLimitPerMinute' => 0,
                'requireCaptcha' => false,
                'maxLinks' => 0,
                'blocklist' => [],
                'rejectDuplicates' => true,
            ],
        ], $override));
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function normalizeSettings(array $settings): array
    {
        $public = \is_array($settings['public'] ?? null) ? $settings['public'] : [];
        $strategy = ($settings['deleteStrategy'] ?? 'hard') === 'soft' ? 'soft' : 'hard';
        $softDelete = $strategy === 'soft' || (bool) ($settings['softDelete'] ?? false);
        $list = \is_array($settings['list'] ?? null) ? $settings['list'] : [];
        $spam = \is_array($settings['spam'] ?? null) ? $settings['spam'] : [];
        $blocklist = [];
        if (\is_array($spam['blocklist'] ?? null)) {
            foreach ($spam['blocklist'] as $term) {
                if (\is_string($term) && trim($term) !== '') {
                    $blocklist[] = trim($term);
                }
            }
        }

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
            'list' => [
                'columns' => self::normalizeListColumns($list['columns'] ?? null),
            ],
            'deleteStrategy' => $softDelete ? 'soft' : 'hard',
            'softDelete' => $softDelete,
            'spam' => [
                'honeypotField' => \is_string($spam['honeypotField'] ?? null) ? trim($spam['honeypotField']) : '',
                'minSubmitMs' => max(0, (int) ($spam['minSubmitMs'] ?? 0)),
                'rateLimitPerMinute' => max(0, (int) ($spam['rateLimitPerMinute'] ?? 0)),
                'requireCaptcha' => (bool) ($spam['requireCaptcha'] ?? false),
                'maxLinks' => max(0, (int) ($spam['maxLinks'] ?? 0)),
                'blocklist' => $blocklist,
                'rejectDuplicates' => (bool) ($spam['rejectDuplicates'] ?? true),
            ],
        ];
    }

    /**
     * Deep-merge for settings where lists (columns, blocklist) replace the stored
     * value instead of merging index by index, which would keep a stale tail.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function mergeSettings(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $current = $base[$key] ?? null;
            $base[$key] = \is_array($value) && \is_array($current) && !array_is_list($value)
                ? self::mergeSettings($current, $value)
                : $value;
        }

        return $base;
    }

    /**
     * Admin table layout: field order plus per-column visibility, label and width.
     * An empty list means "no layout saved yet" and the UI falls back to the schema.
     *
     * @return list<array{field: string, visible: bool, label: ?string, width: ?int}>
     */
    private static function normalizeListColumns(mixed $columns): array
    {
        if (!\is_array($columns)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($columns as $column) {
            if (!\is_array($column)) {
                continue;
            }
            $field = \is_string($column['field'] ?? null) ? trim($column['field']) : '';
            if ($field === '' || isset($seen[$field])) {
                continue;
            }
            $seen[$field] = true;
            $label = \is_string($column['label'] ?? null) ? trim($column['label']) : '';
            $width = isset($column['width']) && is_numeric($column['width'])
                ? (int) $column['width']
                : 0;
            $out[] = [
                'field' => $field,
                'visible' => (bool) ($column['visible'] ?? true),
                'label' => $label === '' ? null : $label,
                'width' => $width > 0 ? min(2000, $width) : null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        $settings = $row['settings_json'] ?? [];
        if (\is_string($settings)) {
            $decoded = json_decode($settings, true);
            $settings = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($settings)) {
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
