<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\Api\QueryEngine;
use Cms\Auth\UsersRepository;
use Cms\Core\Exception\NotFoundException;
use Cms\Resources\ResourceRepository;
use RuntimeException;

/**
 * Thin application service between admin entry controllers and QueryEngine.
 */
final class EntryService
{
    public function __construct(
        private readonly QueryEngine $query,
        private readonly ResourceRepository $resources,
        private readonly ?UsersRepository $users = null,
    ) {
    }

    /**
     * @param array<string, string> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(int $resourceId, array $query): array
    {
        $page = $this->query->list($this->slug($resourceId), $query);
        $page['data'] = $this->attachActors($page['data']);

        return $page;
    }

    /** @return array<string, mixed> */
    public function find(int $resourceId, int $entryId): array
    {
        return $this->attachActor($this->query->find($this->slug($resourceId), $entryId));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(int $resourceId, array $payload, ?int $actorUserId = null): array
    {
        $options = $actorUserId === null ? [] : ['actorUserId' => $actorUserId];

        return $this->attachActor($this->query->create($this->slug($resourceId), $payload, $options));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $resourceId, int $entryId, array $payload, ?int $actorUserId = null): array
    {
        return $this->patch($resourceId, $entryId, $payload, $actorUserId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function patch(int $resourceId, int $entryId, array $payload, ?int $actorUserId = null): array
    {
        $options = $actorUserId === null ? [] : ['actorUserId' => $actorUserId];

        return $this->attachActor(
            $this->query->patch($this->slug($resourceId), $entryId, $payload, $options),
        );
    }

    public function delete(int $resourceId, int $entryId): void
    {
        $this->query->delete($this->slug($resourceId), $entryId);
    }

    /**
     * @param list<int> $ids
     * @return array<string, array<int, string>|int|string>
     */
    public function relationLabels(int $resourceId, string $field, array $ids): array
    {
        return $this->query->relationLabels($this->slug($resourceId), $field, $ids);
    }

    public function slug(int $resourceId): string
    {
        $resource = $this->resources->find($resourceId);
        if ($resource === null) {
            throw new NotFoundException('Resource not found');
        }
        if (($resource['status'] ?? '') !== 'published') {
            throw new RuntimeException('Resource must be published before managing entries', 400);
        }

        return (string) $resource['slug'];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function attachActors(array $entries): array
    {
        if ($this->users === null || $entries === []) {
            return array_map(fn (array $entry): array => $this->withNullActors($entry), $entries);
        }

        $ids = [];
        foreach ($entries as $entry) {
            foreach (['createdById', 'updatedById'] as $key) {
                $id = $entry[$key] ?? null;
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $ids[] = (int) $id;
                }
            }
        }
        $map = $this->users->actorsByIds($ids);

        return array_map(function (array $entry) use ($map): array {
            $createdId = isset($entry['createdById']) ? (int) $entry['createdById'] : 0;
            $updatedId = isset($entry['updatedById']) ? (int) $entry['updatedById'] : 0;
            $entry['createdBy'] = $createdId > 0 ? ($map[$createdId] ?? null) : null;
            $entry['updatedBy'] = $updatedId > 0 ? ($map[$updatedId] ?? null) : null;

            return $entry;
        }, $entries);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function attachActor(array $entry): array
    {
        return $this->attachActors([$entry])[0];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function withNullActors(array $entry): array
    {
        $entry['createdBy'] = null;
        $entry['updatedBy'] = null;

        return $entry;
    }
}
