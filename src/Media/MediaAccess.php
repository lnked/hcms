<?php

declare(strict_types=1);

namespace Cms\Media;

/**
 * Pure ACL decisions for media visibility / mutation given known refs.
 */
final class MediaAccess
{
    /**
     * @param list<int> $refResourceIds resources that currently reference this library root
     */
    public static function isVisible(MediaAclScope $scope, array $refResourceIds, ?int $uploadedBy): bool
    {
        if (!$scope->isRestricted()) {
            return true;
        }

        $allowed = $scope->allowedResourceIds ?? [];
        foreach ($refResourceIds as $resourceId) {
            if (in_array($resourceId, $allowed, true)) {
                return true;
            }
        }

        if ($refResourceIds !== []) {
            return false;
        }

        return $uploadedBy !== null
            && $scope->userId !== null
            && $uploadedBy === $scope->userId;
    }

    /**
     * Delete / regenerate / edit: allowed when unrestricted, or when every ref
     * is to an accessible resource (shared across forbidden resources → deny),
     * or orphan uploaded by the current user.
     *
     * @param list<int> $refResourceIds
     */
    public static function canMutate(MediaAclScope $scope, array $refResourceIds, ?int $uploadedBy): bool
    {
        if (!$scope->isRestricted()) {
            return true;
        }

        $allowed = array_fill_keys($scope->allowedResourceIds ?? [], true);
        foreach ($refResourceIds as $resourceId) {
            if (!isset($allowed[$resourceId])) {
                return false;
            }
        }

        if ($refResourceIds !== []) {
            return true;
        }

        return $uploadedBy !== null
            && $scope->userId !== null
            && $uploadedBy === $scope->userId;
    }
}
