<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Api\QueryEngine;
use Cms\Auth\AuthContext;
use Cms\Auth\TokenGrantRepository;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Resources\ResourceApiRepository;
use Cms\Resources\ResourceApiService;
use Cms\Resources\ResourceRepository;
use Cms\Resources\ResourceService;
use Cms\Security\SpamGuard;
use InvalidArgumentException;
use RuntimeException;

final class PublicApiController
{
    public function __construct(
        private readonly QueryEngine $query,
        private readonly ResourceRepository $resources,
        private readonly TokenGrantRepository $grants,
        private readonly ?ResourceApiRepository $apis = null,
        private readonly ?SpamGuard $spamGuard = null,
    ) {
    }

    public function handle(Request $request, string $slug, ?string $id, ?AuthContext $auth): Response
    {
        try {
            $this->authorize($request->method, $slug, $auth);

            return match ($request->method) {
                'GET' => $id === null
                    ? Response::json($this->query->list($slug, $request->query, ['public' => true]))
                    : Response::data($this->query->find($slug, (int) $id, ['public' => true])),
                'POST' => $this->create($request, $slug, $auth),
                'PUT', 'PATCH' => $id === null
                    ? Response::error('BAD_REQUEST', 'Missing id', 400)
                    : Response::data($this->query->patch($slug, (int) $id, $request->json(), ['public' => true])),
                'DELETE' => $id === null
                    ? Response::error('BAD_REQUEST', 'Missing id', 400)
                    : $this->delete($slug, (int) $id),
                default => Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405),
            };
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        }
    }

    public function handleCustom(
        Request $request,
        string $slug,
        string $apiSlug,
        ?string $id,
        ?AuthContext $auth,
    ): Response {
        try {
            if ($request->method !== 'GET') {
                return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
            }
            if (!ResourceApiService::isValidApiSlug($apiSlug)) {
                return Response::error('NOT_FOUND', 'Resource API not found', 404);
            }
            $this->authorizeCustom($slug, $apiSlug, $auth);

            return $id === null
                ? Response::json($this->query->listCustom($slug, $apiSlug, $request->query, ['public' => true]))
                : Response::data($this->query->findCustom($slug, $apiSlug, (int) $id, ['public' => true]));
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        }
    }

    private function create(Request $request, string $slug, ?AuthContext $auth): Response
    {
        $payload = $request->json();
        if ($auth === null && $this->spamGuard !== null) {
            $resource = $this->resources->findByPublicKey($slug);
            $settings = [];
            if ($resource !== null) {
                $raw = $resource['settings_json'] ?? [];
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    $settings = is_array($decoded) ? $decoded : [];
                } elseif (is_array($raw)) {
                    $settings = $raw;
                }
                $settings = ResourceService::normalizeSettings($settings);
            }
            $this->spamGuard->assertCreateAllowed($request, $settings, $payload);
            $honeypot = is_string($settings['spam']['honeypotField'] ?? null) ? $settings['spam']['honeypotField'] : '';
            if ($honeypot !== '') {
                unset($payload[$honeypot]);
            }
            unset($payload['captchaToken'], $payload['_startedAt']);
        }

        return Response::data($this->query->create($slug, $payload, ['public' => true]), 201);
    }

    private function delete(string $slug, int $id): Response
    {
        $this->query->delete($slug, $id, ['public' => true]);

        return new Response(204, '');
    }

    private function runtimeError(RuntimeException $e): Response
    {
        $code = $e->getCode();
        $status = in_array($code, [401, 403, 404, 405], true) ? $code : 400;
        $errorCode = match ($status) {
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            default => 'BAD_REQUEST',
        };

        return Response::error($errorCode, $e->getMessage(), $status);
    }

    private function authorize(string $method, string $slug, ?AuthContext $auth): void
    {
        $resource = $this->resources->findByPublicKey($slug);
        if ($resource === null || ($resource['status'] ?? '') !== 'published') {
            throw new RuntimeException('Resource not found', 404);
        }
        $settings = is_string($resource['settings_json'])
            ? json_decode((string) $resource['settings_json'], true)
            : $resource['settings_json'];
        $public = is_array($settings['public'] ?? null) ? $settings['public'] : [];

        $action = match ($method) {
            'GET' => 'read',
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'read',
        };

        $this->authorizeAction($resource, $public[$action] ?? false, $action, $auth);
    }

    private function authorizeCustom(string $slug, string $apiSlug, ?AuthContext $auth): void
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

        $resourceSettings = is_string($resource['settings_json'])
            ? json_decode((string) $resource['settings_json'], true)
            : $resource['settings_json'];
        $resourcePublic = is_array($resourceSettings['public'] ?? null) ? $resourceSettings['public'] : [];

        $apiSettings = is_string($api['settings_json'])
            ? json_decode((string) $api['settings_json'], true)
            : $api['settings_json'];
        $apiPublic = is_array($apiSettings['public'] ?? null) ? $apiSettings['public'] : [];

        $allowPublic = array_key_exists('read', $apiPublic) && $apiPublic['read'] !== null
            ? (bool) $apiPublic['read']
            : (bool) ($resourcePublic['read'] ?? false);

        $this->authorizeAction($resource, $allowPublic, 'read', $auth);
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function authorizeAction(array $resource, bool $allowPublic, string $action, ?AuthContext $auth): void
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
}
