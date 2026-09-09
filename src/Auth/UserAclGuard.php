<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Database\Connection;
use Cms\Http\Response;

/**
 * Enforces user ACL after RolePolicy for admin-token requests.
 */
final class UserAclGuard
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserSectionGrantRepository $sections,
        private readonly UserResourceGrantRepository $resources,
    ) {
    }

    public function enforce(AuthContext $auth, string $method, string $path): ?Response
    {
        if (!$auth->isAdmin() || $auth->user === null || $auth->userId() === null) {
            return null;
        }

        $role = RolePolicy::normalize(isset($auth->user['role']) ? (string) $auth->user['role'] : null);
        if ($role === RolePolicy::OWNER) {
            return null;
        }

        $aclEnabled = (bool) ($auth->user['acl_enabled'] ?? false);
        if (!$aclEnabled) {
            return null;
        }

        if (UserAclPolicy::isAlwaysAllowed($path)) {
            return null;
        }

        $userId = $auth->userId();
        $sectionList = $this->sections->forUser($userId);
        $grants = $this->resources->forUser($userId);

        $section = UserAclPolicy::sectionFor($path);
        if ($section !== null && !UserAclPolicy::allowsSection(true, $role, $sectionList, $section)) {
            return Response::error('FORBIDDEN', 'Section not allowed', 403);
        }

        $requirement = UserAclPolicy::resourceRequirement($method, $path);
        if ($requirement === null) {
            $fieldResourceId = $this->resolveFieldResourceId($path);
            if ($fieldResourceId !== null) {
                $requirement = [
                    'resourceId' => $fieldResourceId,
                    'action' => in_array($method, ['GET', 'HEAD'], true) ? 'read' : 'update',
                    'tab' => 'schema',
                    'collectionWrite' => false,
                ];
            }
        }

        if ($requirement === null) {
            return null;
        }

        if ($requirement['collectionWrite']) {
            return Response::error('FORBIDDEN', 'Creating resources is not allowed under ACL', 403);
        }

        $resourceId = $requirement['resourceId'];
        if ($resourceId === null) {
            return Response::error('FORBIDDEN', 'Resource access denied', 403);
        }

        if (!UserAclPolicy::allowsResourceAction(true, $role, $grants, $resourceId, $requirement['action'])) {
            return Response::error('FORBIDDEN', 'Resource privilege denied', 403);
        }

        $tab = $requirement['tab'];
        if ($tab !== null && !UserAclPolicy::allowsResourceTab(true, $role, $grants, $resourceId, $tab)) {
            return Response::error('FORBIDDEN', 'Resource tab not allowed', 403);
        }

        return null;
    }

    /**
     * @return list<int>|null null = no filter (full list)
     */
    public function allowedResourceIds(AuthContext $auth): ?array
    {
        if (!$auth->isAdmin() || $auth->user === null || $auth->userId() === null) {
            return null;
        }
        $role = RolePolicy::normalize(isset($auth->user['role']) ? (string) $auth->user['role'] : null);
        if ($role === RolePolicy::OWNER) {
            return null;
        }
        if (!(bool) ($auth->user['acl_enabled'] ?? false)) {
            return null;
        }

        return $this->resources->resourceIdsForUser($auth->userId());
    }

    private function resolveFieldResourceId(string $path): ?int
    {
        if (preg_match('#^/admin/api/fields/(\d+)$#', $path, $m) !== 1) {
            return null;
        }
        $fieldId = (int) $m[1];
        $row = $this->db->selectOne(
            'SELECT r.id AS resource_id
             FROM cms_fields f
             INNER JOIN cms_resources r ON r.content_type_id = f.content_type_id
             WHERE f.id = :id
             LIMIT 1',
            ['id' => $fieldId],
        );

        return $row === null ? null : (int) $row['resource_id'];
    }
}
