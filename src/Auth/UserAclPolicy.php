<?php

declare(strict_types=1);

namespace Cms\Auth;

/**
 * Admin-user ACL on top of RolePolicy. Owner always bypasses.
 * When acl_enabled is false, ACL is a no-op (role-only).
 */
final class UserAclPolicy
{
    /** @var list<string> */
    public const SECTIONS = [
        'dashboard',
        'resources',
        'media',
        'logs',
        'docs',
        'changelog',
        'tokens',
        'webhooks',
        'inbound',
        'feature-flags',
        'key-values',
        'translates',
        'users',
        'integrations',
        'system',
        'account',
    ];

    /** @var list<string> */
    public const TABS = ['overview', 'schema', 'data', 'settings', 'api', 'hooks', 'export'];

    public static function isValidSection(string $section): bool
    {
        return in_array($section, self::SECTIONS, true);
    }

    public static function isValidTab(string $tab): bool
    {
        return in_array($tab, self::TABS, true);
    }

    /**
     * @param list<string> $sections
     */
    public static function allowsSection(bool $aclEnabled, string $role, array $sections, string $section): bool
    {
        if (RolePolicy::normalize($role) === RolePolicy::OWNER) {
            return true;
        }
        if (!$aclEnabled) {
            return true;
        }
        if ($section === 'account') {
            return true;
        }

        return in_array($section, $sections, true);
    }

    /**
     * @param list<array{resourceId: int, canRead: bool, canCreate: bool, canUpdate: bool, canDelete: bool, tabs: list<string>}> $grants
     */
    public static function allowsResourceAction(
        bool $aclEnabled,
        string $role,
        array $grants,
        int $resourceId,
        string $action,
    ): bool {
        if (RolePolicy::normalize($role) === RolePolicy::OWNER) {
            return true;
        }
        if (!$aclEnabled) {
            return true;
        }

        $flag = match ($action) {
            'read' => 'canRead',
            'create' => 'canCreate',
            'update' => 'canUpdate',
            'delete' => 'canDelete',
            default => null,
        };
        if ($flag === null) {
            return false;
        }

        foreach ($grants as $grant) {
            if ((int) $grant['resourceId'] !== $resourceId) {
                continue;
            }

            return (bool) ($grant[$flag] ?? false);
        }

        return false;
    }

    /**
     * @param list<array{resourceId: int, canRead: bool, canCreate: bool, canUpdate: bool, canDelete: bool, tabs: list<string>}> $grants
     */
    public static function allowsResourceTab(
        bool $aclEnabled,
        string $role,
        array $grants,
        int $resourceId,
        string $tab,
    ): bool {
        if (RolePolicy::normalize($role) === RolePolicy::OWNER) {
            return true;
        }
        if (!$aclEnabled) {
            return true;
        }
        if (!self::isValidTab($tab)) {
            return false;
        }

        foreach ($grants as $grant) {
            if ((int) $grant['resourceId'] !== $resourceId) {
                continue;
            }
            $tabs = $grant['tabs'] ?? [];

            return in_array($tab, $tabs, true);
        }

        return false;
    }

    /**
     * Paths that ACL never blocks (self-service auth + health).
     */
    public static function isAlwaysAllowed(string $path): bool
    {
        if ($path === '/admin/api/health') {
            return true;
        }
        if (str_starts_with($path, '/admin/api/auth/')) {
            return true;
        }

        return false;
    }

    /**
     * Map admin API path to a nav section key (null = no section gate beyond always-allowed).
     */
    public static function sectionFor(string $path): ?string
    {
        if (self::isAlwaysAllowed($path)) {
            return null;
        }
        if (str_starts_with($path, '/admin/api/users')) {
            return 'users';
        }
        if (str_starts_with($path, '/admin/api/tokens')) {
            return 'tokens';
        }
        if (str_starts_with($path, '/admin/api/webhooks')) {
            return 'webhooks';
        }
        if (str_starts_with($path, '/admin/api/inbound-endpoints')) {
            return 'inbound';
        }
        if (str_starts_with($path, '/admin/api/feature-flags')) {
            return 'feature-flags';
        }
        if (str_starts_with($path, '/admin/api/key-values')) {
            return 'key-values';
        }
        if (
            str_starts_with($path, '/admin/api/locales')
            || str_starts_with($path, '/admin/api/translations')
        ) {
            return 'translates';
        }
        if (str_starts_with($path, '/admin/api/integrations')) {
            return 'integrations';
        }
        if (str_starts_with($path, '/admin/api/media')) {
            return 'media';
        }
        if (str_starts_with($path, '/admin/api/logs')) {
            return 'logs';
        }
        if (
            str_starts_with($path, '/admin/api/resources')
            || str_starts_with($path, '/admin/api/fields')
            || $path === '/admin/api/field-types'
        ) {
            return 'resources';
        }
        if (
            $path === '/admin/api/system/stats'
            || str_starts_with($path, '/admin/api/system/stats/')
        ) {
            return 'dashboard';
        }
        if (str_starts_with($path, '/admin/api/system/changelog')) {
            return 'changelog';
        }
        if (
            str_starts_with($path, '/admin/api/system')
            || str_starts_with($path, '/admin/api/settings')
        ) {
            return 'system';
        }

        return null;
    }

    /**
     * @return array{resourceId: int|null, action: string, tab: string|null, collectionWrite: bool}|null
     *     collectionWrite=true means creating/importing resources (no resource id yet) — denied under ACL.
     */
    public static function resourceRequirement(string $method, string $path): ?array
    {
        if ($path === '/admin/api/resources' && $method === 'POST') {
            return ['resourceId' => null, 'action' => 'create', 'tab' => null, 'collectionWrite' => true];
        }
        if ($path === '/admin/api/resources/package/import' && $method === 'POST') {
            return ['resourceId' => null, 'action' => 'create', 'tab' => null, 'collectionWrite' => true];
        }
        if ($path === '/admin/api/resources' || $path === '/admin/api/field-types') {
            return null;
        }

        if (preg_match('#^/admin/api/resources/(\d+)(/.*)?$#', $path, $m) !== 1) {
            return null;
        }

        $resourceId = (int) $m[1];
        $rest = $m[2] ?? '';

        if ($rest === '' || $rest === '/') {
            $action = match ($method) {
                'GET', 'HEAD' => 'read',
                'PATCH' => 'update',
                'DELETE' => 'delete',
                default => 'update',
            };
            $tab = $method === 'PATCH' ? 'settings' : 'overview';

            return ['resourceId' => $resourceId, 'action' => $action, 'tab' => $tab, 'collectionWrite' => false];
        }

        if (str_starts_with($rest, '/fields')) {
            $action = in_array($method, ['GET', 'HEAD'], true) ? 'read' : 'update';

            return ['resourceId' => $resourceId, 'action' => $action, 'tab' => 'schema', 'collectionWrite' => false];
        }
        if (str_starts_with($rest, '/migrate') || str_starts_with($rest, '/publish')) {
            return ['resourceId' => $resourceId, 'action' => 'update', 'tab' => 'schema', 'collectionWrite' => false];
        }
        if (str_starts_with($rest, '/apis')) {
            $action = in_array($method, ['GET', 'HEAD'], true) ? 'read' : 'update';

            return ['resourceId' => $resourceId, 'action' => $action, 'tab' => 'api', 'collectionWrite' => false];
        }
        if (str_starts_with($rest, '/hooks')) {
            $action = in_array($method, ['GET', 'HEAD'], true) ? 'read' : 'update';

            return ['resourceId' => $resourceId, 'action' => $action, 'tab' => 'hooks', 'collectionWrite' => false];
        }
        if (str_starts_with($rest, '/package/export')) {
            return ['resourceId' => $resourceId, 'action' => 'read', 'tab' => 'export', 'collectionWrite' => false];
        }
        if (str_contains($rest, '/entries')) {
            if (str_contains($rest, '/export')) {
                return ['resourceId' => $resourceId, 'action' => 'read', 'tab' => 'export', 'collectionWrite' => false];
            }
            if (str_contains($rest, '/import')) {
                return ['resourceId' => $resourceId, 'action' => 'create', 'tab' => 'export', 'collectionWrite' => false];
            }
            $action = match ($method) {
                'GET', 'HEAD' => 'read',
                'POST' => str_contains($rest, 'bulk-delete') || str_contains($rest, '/restore') ? 'delete' : 'create',
                'PATCH' => 'update',
                'DELETE' => 'delete',
                default => 'read',
            };
            if (str_contains($rest, '/restore')) {
                $action = 'update';
            }

            return ['resourceId' => $resourceId, 'action' => $action, 'tab' => 'data', 'collectionWrite' => false];
        }

        return ['resourceId' => $resourceId, 'action' => 'read', 'tab' => 'overview', 'collectionWrite' => false];
    }
}
