<?php

declare(strict_types=1);

namespace Cms\Auth;

final class GrantPolicy
{
    /**
     * @param list<array{resource_id?: mixed, resourceId?: mixed, can_read?: mixed, can_create?: mixed, can_update?: mixed, can_delete?: mixed, canRead?: mixed, canCreate?: mixed, canUpdate?: mixed, canDelete?: mixed}> $grants
     */
    public static function allows(array $grants, int $resourceId, string $action): bool
    {
        $flag = match ($action) {
            'read' => ['can_read', 'canRead'],
            'create' => ['can_create', 'canCreate'],
            'update' => ['can_update', 'canUpdate'],
            'delete' => ['can_delete', 'canDelete'],
            default => null,
        };
        if ($flag === null) {
            return false;
        }

        foreach ($grants as $grant) {
            $rid = $grant['resource_id'] ?? $grant['resourceId'] ?? null;
            if ($rid !== null && (int) $rid !== $resourceId) {
                continue;
            }
            $ok = (bool) ($grant[$flag[0]] ?? $grant[$flag[1]] ?? false);
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
