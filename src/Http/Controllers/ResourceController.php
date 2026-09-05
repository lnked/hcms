<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Database\MigrationService;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Resources\ResourceService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ResourceController
{
    public function __construct(
        private readonly ResourceService $resources,
        private readonly AuditLogger $audit,
        private readonly ?MigrationService $migrations = null,
    ) {
    }

    public function index(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->resources->list());
    }

    public function show(Request $request, AuthContext $auth, int $id): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->resources->get($id));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function create(Request $request, AuthContext $auth): Response
    {
        try {
            $resource = $this->resources->create($request->json());
            $this->audit->log(
                $request,
                'resource.created',
                $auth->userId(),
                'resource',
                (string) $resource['id'],
                ['slug' => $resource['slug']],
            );

            return Response::data($resource, 201);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function update(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $resource = $this->resources->update($id, $request->json());
            $this->audit->log(
                $request,
                'resource.updated',
                $auth->userId(),
                'resource',
                (string) $id,
            );

            return Response::data($resource);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $status = $e->getCode() === 404 ? 404 : 400;

            return Response::error($status === 404 ? 'NOT_FOUND' : 'BAD_REQUEST', $e->getMessage(), $status);
        }
    }

    public function publish(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            if ($this->migrations !== null) {
                $this->migrations->applyForResource($id, [
                    'confirmDestructive' => (bool) ($request->json()['confirmDestructive'] ?? false),
                ]);
            }
            $resource = $this->resources->publish($id);
            $this->audit->log(
                $request,
                'resource.published',
                $auth->userId(),
                'resource',
                (string) $id,
            );

            return Response::data($resource);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function delete(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $this->resources->delete($id);
            $this->audit->log(
                $request,
                'resource.deleted',
                $auth->userId(),
                'resource',
                (string) $id,
            );

            return new Response(204, '');
        } catch (InvalidArgumentException $e) {
            return Response::error('FORBIDDEN', $e->getMessage(), 403);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }
}
