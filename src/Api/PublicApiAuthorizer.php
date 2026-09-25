<?php

declare(strict_types=1);

namespace Cms\Api;

use Cms\Auth\AuthContext;
use Cms\Auth\TokenGrantRepository;
use Cms\Resources\ResourceApiRepository;
use Cms\Resources\ResourceRepository;
use RuntimeException;

/**
 * Shared public REST/GraphQL authorize path: public.* short-circuit, then API token grants.
 */
final class PublicApiAuthorizer
{
    public function __construct(
        private readonly ResourceRepository $resources,
        private readonly TokenGrantRepository $grants,
        private readonly ?ResourceApiRepository $apis = null,
    ) {
    }

    public function authorize(string $method, string $slug, ?AuthContext $auth): void
    {
        $resource = $this->resources->findByPublicKey($slug);
        if ($resource === null || ($resource['status'] ?? '') !== 'published') {
            throw new RuntimeException('Resource not found', 404);
        }
        $settings = \is_string($resource['settings_json'])
            ? json_decode((string) $resource['settings_json'], true)
            : $resource['settings_json'];
        $public = \is_array($settings['public'] ?? null) ? $settings['public'] : [];
        $action = self::actionFor($method);

        $this->authorizeAction($resource, (bool) ($public[$action] ?? false), $action, $auth);
    }

    public function authorizeActionName(string $slug, string $action, ?AuthContext $auth): void
    {
        $resource = $this->resources->findByPublicKey($slug);
        if ($resource === null || ($resource['status'] ?? '') !== 'published') {
            throw new RuntimeException('Resource not found', 404);
        }
        $settings = \is_string($resource['settings_json'])
            ? json_decode((string) $resource['settings_json'], true)
            : $resource['settings_json'];
        $public = \is_array($settings['public'] ?? null) ? $settings['public'] : [];

        $this->authorizeAction($resource, (bool) ($public[$action] ?? false), $action, $auth);
    }

    public function authorizeCustom(string $slug, string $apiSlug, string $action, ?AuthContext $auth): void
    {
        $resource = $this->resources->findByPublicKey($slug);
        if ($resource === null || ($resource['status'] ?? '') !== 'published') {
            throw new RuntimeException('Resource not found', 404);
        }
        if ($this->apis === null) {
            throw new RuntimeException('Resource API not found', 404);
        }
        $api = $this->apis->findByResourceAndSlug((int) $resource['id'], $apiSlug);
        if ($api === null || !(bool) (int) ($api['enabled'] ?? 0)) {
            throw new RuntimeException('Resource API not found', 404);
        }

        $resourceSettings = \is_string($resource['settings_json'])
            ? json_decode((string) $resource['settings_json'], true)
            : $resource['settings_json'];
        $resourcePublic = \is_array($resourceSettings['public'] ?? null) ? $resourceSettings['public'] : [];

        $apiSettings = \is_string($api['settings_json'])
            ? json_decode((string) $api['settings_json'], true)
            : $api['settings_json'];
        $apiPublic = \is_array($apiSettings['public'] ?? null) ? $apiSettings['public'] : [];

        $allowPublic = \array_key_exists($action, $apiPublic) && $apiPublic[$action] !== null
            ? (bool) $apiPublic[$action]
            : (bool) ($resourcePublic[$action] ?? false);

        $this->authorizeAction($resource, $allowPublic, $action, $auth);
    }

    /**
     * @param array<string, mixed> $resource
     */
    public function authorizeAction(array $resource, bool $allowPublic, string $action, ?AuthContext $auth): void
    {
        if ($allowPublic === true) {
            return;
        }

        if ($auth === null) {
            throw new RuntimeException('Unauthorized', 401);
        }

        if ($auth->isAdmin()) {
            return;
        }

        if (($auth->token['type'] ?? '') !== 'api') {
            throw new RuntimeException('Forbidden', 403);
        }

        if (!$this->grants->allows($auth->tokenId(), (int) $resource['id'], $action)) {
            throw new RuntimeException('Forbidden', 403);
        }
    }

    public static function actionFor(string $method): string
    {
        return match ($method) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'read',
        };
    }
}
