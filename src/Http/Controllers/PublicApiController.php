<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Api\QueryEngine;
use Cms\Auth\AuthContext;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Resources\ResourceRepository;
use InvalidArgumentException;
use RuntimeException;

final class PublicApiController
{
    public function __construct(
        private readonly QueryEngine $query,
        private readonly ResourceRepository $resources,
    ) {
    }

    public function handle(Request $request, string $slug, ?string $id, ?AuthContext $auth): Response
    {
        try {
            $this->authorize($request->method, $slug, $auth);

            return match ($request->method) {
                'GET' => $id === null
                    ? Response::json($this->query->list($slug, $request->query))
                    : Response::data($this->query->find($slug, (int) $id)),
                'POST' => Response::data($this->query->create($slug, $request->json()), 201),
                'PUT', 'PATCH' => $id === null
                    ? Response::error('BAD_REQUEST', 'Missing id', 400)
                    : Response::data($this->query->patch($slug, (int) $id, $request->json())),
                'DELETE' => $id === null
                    ? Response::error('BAD_REQUEST', 'Missing id', 400)
                    : $this->delete($slug, (int) $id),
                default => Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405),
            };
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            $status = in_array($code, [401, 403, 404], true) ? $code : 400;
            $errorCode = match ($status) {
                401 => 'UNAUTHORIZED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                default => 'BAD_REQUEST',
            };

            return Response::error($errorCode, $e->getMessage(), $status);
        }
    }

    private function delete(string $slug, int $id): Response
    {
        $this->query->delete($slug, $id);

        return new Response(204, '');
    }

    private function authorize(string $method, string $slug, ?AuthContext $auth): void
    {
        $resource = $this->resources->findBySlug($slug);
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

        if (($public[$action] ?? false) === true) {
            return;
        }

        if ($auth === null) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // Phase 8 will enforce token grants; for now any valid api/admin token may access private methods
        if (!in_array($auth->token['type'] ?? '', ['api', 'admin'], true)) {
            throw new RuntimeException('Forbidden', 403);
        }
    }
}
