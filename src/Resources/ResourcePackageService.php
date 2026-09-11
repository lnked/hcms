<?php

declare(strict_types=1);

namespace Cms\Resources;

use Cms\Api\QueryEngine;
use Cms\Content\ContentTypeRepository;
use Cms\Content\Slug;
use Cms\Database\MigrationService;
use Cms\Fields\FieldService;
use Cms\Media\MediaService;
use Cms\Media\MediaValue;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ResourcePackageService
{
    public const KIND = 'cms.resource.package';
    public const FORMAT_VERSION = 1;
    public const MAX_BYTES = 50 * 1024 * 1024;
    public const MAX_ENTRIES = 5000;

    private const SYSTEM_ENTRY_KEYS = [
        'id',
        'createdAt',
        'updatedAt',
        'created_at',
        'updated_at',
        'deletedAt',
        'deleted_at',
    ];

    public function __construct(
        private readonly ResourceRepository $resources,
        private readonly ContentTypeRepository $contentTypes,
        private readonly FieldService $fields,
        private readonly ResourceApiService $apis,
        private readonly ResourceService $resourceService,
        private readonly QueryEngine $query,
        private readonly MediaService $media,
        private readonly MigrationService $migrations,
    ) {
    }

    /**
     * @return array{body: string, contentType: string, filename: string}
     */
    public function export(int $resourceId, bool $includeData): array
    {
        $resource = $this->resources->find($resourceId);
        if ($resource === null) {
            throw new RuntimeException('Resource not found', 404);
        }

        $contentType = $this->contentTypes->find((int) $resource['content_type_id']);
        if ($contentType === null) {
            throw new RuntimeException('Content type not found', 404);
        }

        $fieldRows = $this->fields->listForResource($resourceId);
        $fields = array_map([$this, 'exportField'], $fieldRows);

        $apiRows = $this->apis->list($resourceId);
        $apis = array_map([$this, 'exportApi'], $apiRows);

        $settings = $resource['settings_json'] ?? [];
        if (\is_string($settings)) {
            $decoded = json_decode($settings, true);
            $settings = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($settings)) {
            $settings = [];
        }

        $package = [
            'formatVersion' => self::FORMAT_VERSION,
            'kind' => self::KIND,
            'exportedAt' => gmdate('c'),
            'contentType' => [
                'name' => (string) $contentType['name'],
                'slug' => (string) $contentType['slug'],
                'label' => (string) $contentType['label'],
                'description' => $contentType['description'] !== null
                    ? (string) $contentType['description']
                    : null,
            ],
            'resource' => [
                'slug' => (string) $resource['slug'],
                'endpoint' => (string) $resource['endpoint'],
                'apiVersion' => (string) $resource['api_version'],
                'status' => (string) $resource['status'],
                'settings' => ResourceService::normalizeSettings($settings),
            ],
            'fields' => $fields,
            'apis' => $apis,
        ];

        if ($includeData) {
            if ((string) $resource['status'] !== 'published') {
                throw new InvalidArgumentException('Resource must be published to export data');
            }
            $slug = (string) $resource['slug'];
            $entries = $this->query->listAll($slug);
            if (\count($entries) > self::MAX_ENTRIES) {
                throw new InvalidArgumentException('Export exceeds ' . self::MAX_ENTRIES . ' entries limit');
            }

            $mediaFieldNames = $this->mediaFieldNames($fieldRows);
            $mediaIds = self::collectMediaIds($entries, $mediaFieldNames);
            $media = [];
            foreach ($mediaIds as $mediaId) {
                $item = $this->media->exportForPackage($mediaId);
                if ($item !== null) {
                    $media[] = $item;
                }
            }

            $package['entries'] = $entries;
            $package['media'] = $media;
        }

        $body = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($body === false) {
            throw new InvalidArgumentException('Failed to encode package JSON');
        }
        $body .= "\n";
        if (\strlen($body) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Export package exceeds 50MB limit');
        }

        return [
            'body' => $body,
            'contentType' => 'application/json; charset=utf-8',
            'filename' => (string) $resource['slug'] . '.cms-resource.json',
        ];
    }

    /**
     * @param array<string, mixed> $package
     * @return array{
     *   resource: array<string, mixed>,
     *   slugResolved: string,
     *   mediaRemapped: int,
     *   entries: array{created: int, failed: int, errors: list<array{row: int, message: string}>},
     *   warnings: list<string>
     * }
     */
    public function import(array $package, ?string $slugOverride = null): array
    {
        self::validatePackage($package);

        $warnings = [];
        $desiredSlug = $slugOverride !== null && trim($slugOverride) !== ''
            ? trim($slugOverride)
            : (string) $package['contentType']['slug'];
        $slug = $this->resolveAvailableSlug($desiredSlug);
        if ($slug !== (string) $package['contentType']['slug'] && ($slugOverride === null || trim((string) $slugOverride) === '')) {
            $warnings[] = 'Slug "' . $package['contentType']['slug'] . '" was taken; imported as "' . $slug . '"';
        }

        /** @var array<string, mixed> $contentType */
        $contentType = $package['contentType'];
        /** @var array<string, mixed> $resourceMeta */
        $resourceMeta = $package['resource'];
        /** @var list<array<string, mixed>> $fields */
        $fields = $package['fields'];
        /** @var list<array<string, mixed>> $apis */
        $apis = \is_array($package['apis'] ?? null) ? $package['apis'] : [];
        /** @var list<array<string, mixed>> $entries */
        $entries = \is_array($package['entries'] ?? null) ? $package['entries'] : [];
        /** @var list<array<string, mixed>> $mediaItems */
        $mediaItems = \is_array($package['media'] ?? null) ? $package['media'] : [];

        if (\count($entries) > self::MAX_ENTRIES) {
            throw new InvalidArgumentException('Import exceeds ' . self::MAX_ENTRIES . ' entries limit');
        }

        $endpoint = $this->endpointForSlug(
            \is_string($resourceMeta['endpoint'] ?? null) ? (string) $resourceMeta['endpoint'] : '/api/' . $slug,
            (string) ($resourceMeta['slug'] ?? $contentType['slug']),
            $slug,
        );

        $settings = \is_array($resourceMeta['settings'] ?? null) ? $resourceMeta['settings'] : [];
        $label = \is_string($contentType['label'] ?? null) && trim((string) $contentType['label']) !== ''
            ? trim((string) $contentType['label'])
            : $slug;
        $name = \is_string($contentType['name'] ?? null) && trim((string) $contentType['name']) !== ''
            ? trim((string) $contentType['name'])
            : $slug;
        $description = isset($contentType['description']) && \is_string($contentType['description'])
            ? $contentType['description']
            : null;

        $resource = $this->resourceService->create([
            'name' => $name,
            'label' => $label,
            'description' => $description,
            'slug' => $slug,
            'endpoint' => $endpoint,
            'settings' => $settings,
        ]);
        $resourceId = (int) $resource['id'];

        $importFields = array_map(static function (array $field): array {
            unset($field['id'], $field['contentTypeId'], $field['createdAt'], $field['updatedAt']);

            return $field;
        }, $fields);
        $this->fields->replaceSchema($resourceId, $importFields);

        foreach ($fields as $field) {
            if (($field['type'] ?? '') !== 'relation') {
                continue;
            }
            $config = \is_array($field['config'] ?? null) ? $field['config'] : [];
            $relatedSlug = isset($config['relatedSlug']) && \is_string($config['relatedSlug'])
                ? trim($config['relatedSlug'])
                : '';
            if ($relatedSlug === '') {
                continue;
            }
            $related = $this->resources->findBySlug($relatedSlug);
            if ($related === null) {
                $warnings[] = 'Related resource "' . $relatedSlug . '" is missing for field "'
                    . ($field['name'] ?? '') . '"';
            }
        }

        foreach ($apis as $apiPayload) {
            if (!\is_array($apiPayload)) {
                continue;
            }
            $payload = $apiPayload;
            unset($payload['id'], $payload['resourceId'], $payload['path'], $payload['createdAt'], $payload['updatedAt']);
            $joins = \is_array($payload['joins'] ?? null) ? $payload['joins'] : [];
            $keptJoins = [];
            foreach ($joins as $join) {
                if (!\is_array($join)) {
                    continue;
                }
                $relatedSlug = isset($join['relatedSlug']) && \is_string($join['relatedSlug'])
                    ? trim($join['relatedSlug'])
                    : '';
                $related = $relatedSlug !== '' ? $this->resources->findBySlug($relatedSlug) : null;
                if ($related === null || ($related['status'] ?? '') !== 'published') {
                    $warnings[] = 'Skipped join to missing/unpublished resource "' . $relatedSlug
                        . '" in API "' . ($payload['slug'] ?? '') . '"';
                    continue;
                }
                $keptJoins[] = $join;
            }
            $payload['joins'] = $keptJoins;
            try {
                $this->apis->create($resourceId, $payload);
            } catch (Throwable $e) {
                $warnings[] = 'Failed to import API "' . ($payload['slug'] ?? '') . '": ' . $e->getMessage();
            }
        }

        $mediaMap = [];
        foreach ($mediaItems as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $oldId = isset($item['id']) ? (int) $item['id'] : 0;
            $b64 = isset($item['contentBase64']) && \is_string($item['contentBase64'])
                ? $item['contentBase64']
                : '';
            if ($oldId <= 0 || $b64 === '') {
                $warnings[] = 'Skipped media item without id or content';
                continue;
            }
            $bytes = base64_decode($b64, true);
            if ($bytes === false) {
                $warnings[] = 'Skipped media id ' . $oldId . ': invalid base64';
                continue;
            }
            try {
                $created = $this->media->storeFromBytes(
                    $bytes,
                    \is_string($item['originalName'] ?? null) ? (string) $item['originalName'] : 'file.bin',
                    \is_string($item['mime'] ?? null) ? (string) $item['mime'] : 'application/octet-stream',
                );
                $mediaMap[$oldId] = (int) $created['id'];
            } catch (Throwable $e) {
                $warnings[] = 'Failed to import media id ' . $oldId . ': ' . $e->getMessage();
            }
        }

        $entryStats = ['created' => 0, 'failed' => 0, 'errors' => []];
        $shouldMaterialize = $entries !== []
            || (\is_string($resourceMeta['status'] ?? null) && $resourceMeta['status'] === 'published');

        if ($shouldMaterialize) {
            $this->migrations->applyForResource($resourceId, ['confirmDestructive' => true]);
            $resource = $this->resourceService->publish($resourceId);
        }

        if ($entries !== []) {
            $mediaFieldNames = [];
            foreach ($importFields as $field) {
                $type = (string) ($field['type'] ?? '');
                if ($type === 'image' || $type === 'file') {
                    $mediaFieldNames[] = (string) $field['name'];
                }
            }
            $remappedEntries = self::remapMediaInEntries($entries, $mediaFieldNames, $mediaMap);
            foreach ($remappedEntries as $index => $row) {
                $rowNumber = $index + 1;
                try {
                    $payload = self::prepareEntryPayload($row);
                    $this->query->create($slug, $payload);
                    $entryStats['created']++;
                } catch (Throwable $e) {
                    $entryStats['failed']++;
                    $entryStats['errors'][] = [
                        'row' => $rowNumber,
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        return [
            'resource' => $this->resourceService->get($resourceId),
            'slugResolved' => $slug,
            'mediaRemapped' => \count($mediaMap),
            'entries' => $entryStats,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $package
     */
    public static function validatePackage(array $package): void
    {
        if (($package['kind'] ?? null) !== self::KIND) {
            throw new InvalidArgumentException('Invalid package kind');
        }
        $version = (int) ($package['formatVersion'] ?? 0);
        if ($version !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException('Unsupported package formatVersion');
        }
        if (!\is_array($package['contentType'] ?? null)) {
            throw new InvalidArgumentException('Package contentType is required');
        }
        if (!\is_array($package['resource'] ?? null)) {
            throw new InvalidArgumentException('Package resource is required');
        }
        if (!\is_array($package['fields'] ?? null) || !array_is_list($package['fields'])) {
            throw new InvalidArgumentException('Package fields must be a list');
        }
        $slug = $package['contentType']['slug'] ?? null;
        if (!\is_string($slug) || !Slug::isValid($slug)) {
            throw new InvalidArgumentException('Package contentType.slug is invalid');
        }
        $label = $package['contentType']['label'] ?? null;
        if (!\is_string($label) || trim($label) === '') {
            throw new InvalidArgumentException('Package contentType.label is required');
        }
    }

    public function resolveAvailableSlug(string $desired): string
    {
        return self::nextAvailableSlug($desired, fn (string $slug): bool => $this->slugTaken($slug));
    }

    /**
     * @param callable(string): bool $isTaken
     */
    public static function nextAvailableSlug(string $desired, callable $isTaken): string
    {
        $desired = trim($desired);
        if (!Slug::isValid($desired)) {
            throw new InvalidArgumentException('Invalid slug. Use a-z, 0-9, underscore; start with a letter.');
        }
        if (!$isTaken($desired)) {
            return $desired;
        }

        for ($i = 2; $i <= 999; $i++) {
            $suffix = '_' . $i;
            $base = substr($desired, 0, max(1, 48 - \strlen($suffix)));
            $candidate = $base . $suffix;
            if (!Slug::isValid($candidate)) {
                continue;
            }
            if (!$isTaken($candidate)) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException('Unable to resolve a free slug for "' . $desired . '"');
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<string> $mediaFieldNames
     * @return list<int>
     */
    public static function collectMediaIds(array $entries, array $mediaFieldNames): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            foreach ($mediaFieldNames as $name) {
                if (!\array_key_exists($name, $entry) || $entry[$name] === null || $entry[$name] === '') {
                    continue;
                }
                foreach (MediaValue::collectIds($entry[$name]) as $id) {
                    $ids[$id] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<string> $mediaFieldNames
     * @param array<int, int> $mediaMap oldId => newId
     * @return list<array<string, mixed>>
     */
    public static function remapMediaInEntries(array $entries, array $mediaFieldNames, array $mediaMap): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $row = $entry;
            foreach ($mediaFieldNames as $name) {
                if (!\array_key_exists($name, $row) || $row[$name] === null || $row[$name] === '') {
                    continue;
                }
                $row[$name] = MediaValue::remapIds($row[$name], $mediaMap);
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function prepareEntryPayload(array $row): array
    {
        $payload = [];
        foreach ($row as $key => $value) {
            $name = (string) $key;
            if (\in_array($name, self::SYSTEM_ENTRY_KEYS, true)) {
                continue;
            }
            $payload[$name] = $value;
        }

        return $payload;
    }

    private function slugTaken(string $slug): bool
    {
        return $this->contentTypes->findBySlug($slug) !== null
            || $this->resources->findBySlug($slug) !== null
            || $this->resources->findPublicKeyConflict($slug) !== null;
    }

    private function endpointForSlug(string $originalEndpoint, string $originalSlug, string $newSlug): string
    {
        $endpoint = ResourceService::normalizeEndpoint($originalEndpoint);
        if (str_ends_with($endpoint, '/' . $originalSlug)) {
            $endpoint = substr($endpoint, 0, -\strlen($originalSlug)) . $newSlug;
        } elseif (preg_match('#^/api(?:/v1)?/#', $endpoint) === 1) {
            $prefix = str_starts_with($endpoint, '/api/v1/') ? '/api/v1/' : '/api/';
            $endpoint = $prefix . $newSlug;
        } else {
            $endpoint = '/api/' . $newSlug;
        }

        return ResourceService::normalizeEndpoint($endpoint);
    }

    /**
     * @param list<array<string, mixed>> $fieldRows
     * @return list<string>
     */
    private function mediaFieldNames(array $fieldRows): array
    {
        $names = [];
        foreach ($fieldRows as $field) {
            $type = (string) ($field['type'] ?? '');
            if ($type === 'image' || $type === 'file') {
                $names[] = (string) $field['name'];
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function exportField(array $field): array
    {
        unset($field['id'], $field['contentTypeId'], $field['createdAt'], $field['updatedAt']);

        return $field;
    }

    /**
     * @param array<string, mixed> $api
     * @return array<string, mixed>
     */
    private function exportApi(array $api): array
    {
        unset($api['id'], $api['resourceId'], $api['path'], $api['createdAt'], $api['updatedAt']);

        return $api;
    }
}
