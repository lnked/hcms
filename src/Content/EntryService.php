<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\Api\QueryEngine;
use Cms\Auth\AuthContext;
use Cms\Auth\UserAclGuard;
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
        private readonly ?UserAclGuard $userAcl = null,
    ) {
    }

    /**
     * @param array<string, string> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(int $resourceId, array $query, ?AuthContext $auth = null): array
    {
        $page = $this->query->list($this->slug($resourceId), $query, $this->aclOptions($auth, $resourceId));
        $page['data'] = $this->attachActors($page['data']);

        return $page;
    }

    /** @return array<string, mixed> */
    public function find(int $resourceId, int $entryId, ?AuthContext $auth = null): array
    {
        return $this->attachActor(
            $this->query->find($this->slug($resourceId), $entryId, $this->aclOptions($auth, $resourceId)),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(int $resourceId, array $payload, ?int $actorUserId = null, ?AuthContext $auth = null): array
    {
        $options = $this->aclOptions($auth, $resourceId);
        if ($actorUserId !== null) {
            $options['actorUserId'] = $actorUserId;
        }

        return $this->attachActor($this->query->create($this->slug($resourceId), $payload, $options));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $resourceId, int $entryId, array $payload, ?int $actorUserId = null, ?AuthContext $auth = null): array
    {
        return $this->patch($resourceId, $entryId, $payload, $actorUserId, $auth);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function patch(int $resourceId, int $entryId, array $payload, ?int $actorUserId = null, ?AuthContext $auth = null): array
    {
        $options = $this->aclOptions($auth, $resourceId);
        if ($actorUserId !== null) {
            $options['actorUserId'] = $actorUserId;
        }

        return $this->attachActor(
            $this->query->patch($this->slug($resourceId), $entryId, $payload, $options),
        );
    }

    public function delete(int $resourceId, int $entryId, ?AuthContext $auth = null): void
    {
        $this->query->delete($this->slug($resourceId), $entryId, $this->aclOptions($auth, $resourceId));
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
     * @return array<string, mixed>
     */
    private function aclOptions(?AuthContext $auth, int $resourceId): array
    {
        if ($auth === null) {
            return [];
        }
        $options = [];
        if ($auth->userId() !== null) {
            $options['actorUserId'] = $auth->userId();
        }
        if ($this->userAcl === null) {
            return $options;
        }
        $grant = $this->userAcl->grantForResource($auth, $resourceId);
        if ($grant === null) {
            return $options;
        }
        if ($grant['fieldAcl'] !== []) {
            $options['fieldAcl'] = $grant['fieldAcl'];
        }
        if ($grant['ownEntriesOnly'] && $auth->userId() !== null) {
            $options['ownCreatedBy'] = $auth->userId();
        }

        return $options;
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
                if (\is_int($id) || (\is_string($id) && ctype_digit($id))) {
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
