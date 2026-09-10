<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\Api\QueryEngine;
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
    ) {
    }

    /**
     * @param array<string, string> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(int $resourceId, array $query): array
    {
        return $this->query->list($this->slug($resourceId), $query);
    }

    /** @return array<string, mixed> */
    public function find(int $resourceId, int $entryId): array
    {
        return $this->query->find($this->slug($resourceId), $entryId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(int $resourceId, array $payload): array
    {
        return $this->query->create($this->slug($resourceId), $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $resourceId, int $entryId, array $payload): array
    {
        return $this->query->patch($this->slug($resourceId), $entryId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function patch(int $resourceId, int $entryId, array $payload): array
    {
        return $this->query->patch($this->slug($resourceId), $entryId, $payload);
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
}
