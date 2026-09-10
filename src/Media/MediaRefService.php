<?php

declare(strict_types=1);

namespace Cms\Media;

use Cms\Database\Connection;
use Cms\Database\MigrationService;
use Cms\Resources\ResourceRepository;

/**
 * Tracks which library-root media ids are used by which resource entries,
 * and evaluates ACL visibility / mutation rights.
 */
final class MediaRefService
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Replace all media refs for an entry from current image/file column values.
     *
     * @param array<string, array<string, mixed>> $fieldMap
     */
    public function syncEntry(int $resourceId, int $entryId, string $table, array $fieldMap): void
    {
        $mediaFields = $this->mediaFieldNames($fieldMap);
        $this->clearEntry($resourceId, $entryId);
        if ($mediaFields === []) {
            return;
        }

        $cols = array_map(static fn (string $name): string => '`' . $name . '`', $mediaFields);
        $row = $this->db->selectOne(
            'SELECT ' . implode(', ', $cols) . ' FROM `' . $table . '` WHERE id = :id',
            ['id' => $entryId],
        );
        if ($row === null) {
            return;
        }

        $rows = [];
        foreach ($mediaFields as $field) {
            foreach (MediaValue::collectIds($row[$field] ?? null) as $mediaId) {
                $rootId = $this->resolveLibraryRootId($mediaId);
                if ($rootId === null) {
                    continue;
                }
                $key = $rootId . ':' . $field;
                $rows[$key] = [
                    'media_id' => $rootId,
                    'resource_id' => $resourceId,
                    'entry_id' => $entryId,
                    'field_name' => $field,
                ];
            }
        }

        foreach ($rows as $ref) {
            $this->db->execute(
                'INSERT INTO cms_media_refs (media_id, resource_id, entry_id, field_name)
                 VALUES (:media_id, :resource_id, :entry_id, :field_name)',
                $ref,
            );
        }
    }

    public function clearEntry(int $resourceId, int $entryId): void
    {
        $this->db->execute(
            'DELETE FROM cms_media_refs WHERE resource_id = :resource_id AND entry_id = :entry_id',
            ['resource_id' => $resourceId, 'entry_id' => $entryId],
        );
    }

    /**
     * Resolve variant / baked master to the library original (parent_id IS NULL, source_id IS NULL).
     */
    public function resolveLibraryRootId(int $mediaId): ?int
    {
        $guard = 0;
        while ($mediaId > 0 && $guard < 8) {
            ++$guard;
            $row = $this->db->selectOne(
                'SELECT id, parent_id, source_id FROM cms_media WHERE id = :id',
                ['id' => $mediaId],
            );
            if ($row === null) {
                return null;
            }
            if ($row['parent_id'] !== null) {
                $mediaId = (int) $row['parent_id'];
                continue;
            }
            if ($row['source_id'] !== null) {
                $mediaId = (int) $row['source_id'];
                continue;
            }

            return (int) $row['id'];
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function resourceIdsForMedia(int $libraryRootId): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT resource_id FROM cms_media_refs WHERE media_id = :media_id',
            ['media_id' => $libraryRootId],
        );

        return array_map(static fn (array $row): int => (int) $row['resource_id'], $rows);
    }

    public function uploadedBy(int $libraryRootId): ?int
    {
        $row = $this->db->selectOne(
            'SELECT uploaded_by FROM cms_media WHERE id = :id',
            ['id' => $libraryRootId],
        );
        if ($row === null || $row['uploaded_by'] === null) {
            return null;
        }

        return (int) $row['uploaded_by'];
    }

    public function isVisible(int $mediaId, MediaAclScope $scope): bool
    {
        $rootId = $this->resolveLibraryRootId($mediaId);
        if ($rootId === null) {
            return false;
        }

        return MediaAccess::isVisible(
            $scope,
            $this->resourceIdsForMedia($rootId),
            $this->uploadedBy($rootId),
        );
    }

    public function canMutate(int $mediaId, MediaAclScope $scope): bool
    {
        $rootId = $this->resolveLibraryRootId($mediaId);
        if ($rootId === null) {
            return false;
        }

        return MediaAccess::canMutate(
            $scope,
            $this->resourceIdsForMedia($rootId),
            $this->uploadedBy($rootId),
        );
    }

    /**
     * Scan published resources and rebuild cms_media_refs from image/file columns.
     */
    public function backfillAll(?ResourceRepository $resources = null): int
    {
        $resources ??= new ResourceRepository($this->db);
        $this->db->execute('DELETE FROM cms_media_refs');

        $count = 0;
        foreach ($resources->all() as $resource) {
            if (($resource['status'] ?? '') !== 'published') {
                continue;
            }
            $resourceId = (int) $resource['id'];
            $contentTypeId = (int) $resource['content_type_id'];
            $slug = (string) ($resource['content_type_slug'] ?? $resource['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $fields = $this->db->select(
                'SELECT name, type FROM cms_fields WHERE content_type_id = :ct',
                ['ct' => $contentTypeId],
            );
            $mediaFields = [];
            foreach ($fields as $field) {
                $type = (string) $field['type'];
                if ($type === 'image' || $type === 'file') {
                    $mediaFields[] = (string) $field['name'];
                }
            }
            if ($mediaFields === []) {
                continue;
            }

            $table = MigrationService::tableName($slug);
            $tableExists = $this->db->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
                ['t' => $table],
            );
            if ($tableExists === null || (int) $tableExists['c'] === 0) {
                continue;
            }

            $cols = array_map(static fn (string $name): string => '`' . $name . '`', $mediaFields);
            $rows = $this->db->select(
                'SELECT `id`, ' . implode(', ', $cols) . ' FROM `' . $table . '`',
            );
            foreach ($rows as $row) {
                $entryId = (int) $row['id'];
                $seen = [];
                foreach ($mediaFields as $field) {
                    foreach (MediaValue::collectIds($row[$field] ?? null) as $mediaId) {
                        $rootId = $this->resolveLibraryRootId($mediaId);
                        if ($rootId === null) {
                            continue;
                        }
                        $key = $rootId . ':' . $field;
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $this->db->execute(
                            'INSERT INTO cms_media_refs (media_id, resource_id, entry_id, field_name)
                             VALUES (:media_id, :resource_id, :entry_id, :field_name)',
                            [
                                'media_id' => $rootId,
                                'resource_id' => $resourceId,
                                'entry_id' => $entryId,
                                'field_name' => $field,
                            ],
                        );
                        ++$count;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * @param array<string, array<string, mixed>> $fieldMap
     * @return list<string>
     */
    private function mediaFieldNames(array $fieldMap): array
    {
        $names = [];
        foreach ($fieldMap as $name => $field) {
            $type = (string) ($field['type'] ?? '');
            if ($type === 'image' || $type === 'file') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
