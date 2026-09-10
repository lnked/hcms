<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Http\Response;

/**
 * Admin panel RBAC. API tokens use GrantPolicy separately.
 */
final class RolePolicy
{
    public const OWNER = 'owner';
    public const ADMIN = 'admin';
    public const EDITOR = 'editor';
    public const VIEWER = 'viewer';

    /** @var list<string> */
    public const ROLES = [self::OWNER, self::ADMIN, self::EDITOR, self::VIEWER];

    public static function normalize(?string $role): string
    {
        $role = strtolower(trim((string) $role));
        if ($role === '' || !in_array($role, self::ROLES, true)) {
            return self::ADMIN;
        }

        return $role;
    }

    public static function can(string $role, string $capability): bool
    {
        $role = self::normalize($role);

        return match ($capability) {
            'read' => true,
            'entries.write' => in_array($role, [self::OWNER, self::ADMIN, self::EDITOR], true),
            'schema.write' => in_array($role, [self::OWNER, self::ADMIN], true),
            'users.write' => in_array($role, [self::OWNER, self::ADMIN], true),
            'settings.write' => in_array($role, [self::OWNER, self::ADMIN], true),
            'system.write' => $role === self::OWNER,
            default => false,
        };
    }

    public static function denyUnless(AuthContext $auth, string $capability): ?Response
    {
        if (!$auth->isAdmin()) {
            return null;
        }
        $role = self::normalize(isset($auth->user['role']) ? (string) $auth->user['role'] : null);
        if (self::can($role, $capability)) {
            return null;
        }

        return Response::error('FORBIDDEN', 'Insufficient role for this action', 403);
    }

    /**
     * Map admin API request to required capability (null = read-only / always allowed for authed).
     */
    public static function capabilityFor(string $method, string $path): ?string
    {
        if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
            return null;
        }

        if (str_starts_with($path, '/admin/api/auth/')) {
            return null;
        }
        if ($path === '/admin/api/system/update/run') {
            return 'system.write';
        }
        // check/preview/status are read-only despite POST
        if (str_starts_with($path, '/admin/api/system/update')) {
            return null;
        }
        if (str_starts_with($path, '/admin/api/system/changelog/seen')) {
            return null;
        }
        if (str_starts_with($path, '/admin/api/users')) {
            return 'users.write';
        }
        if (
            str_starts_with($path, '/admin/api/settings')
            || str_starts_with($path, '/admin/api/tokens')
            || str_starts_with($path, '/admin/api/integrations')
            || str_starts_with($path, '/admin/api/webhooks')
            || str_starts_with($path, '/admin/api/feature-flags')
            || str_starts_with($path, '/admin/api/locales')
            || str_starts_with($path, '/admin/api/translations')
            || str_starts_with($path, '/admin/api/logs/ip-blocks')
        ) {
            return 'settings.write';
        }
        if (str_starts_with($path, '/admin/api/media')) {
            return 'entries.write';
        }
        if (str_contains($path, '/entries')) {
            return 'entries.write';
        }
        if (
            str_starts_with($path, '/admin/api/resources')
            || str_starts_with($path, '/admin/api/fields')
        ) {
            return 'schema.write';
        }

        return 'settings.write';
    }

    public static function enforce(AuthContext $auth, string $method, string $path): ?Response
    {
        $capability = self::capabilityFor($method, $path);
        if ($capability === null) {
            return null;
        }

        return self::denyUnless($auth, $capability);
    }
}
