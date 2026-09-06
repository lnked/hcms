<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Resources\ResourceApiService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ResourceApiController
{
    public function __construct(
        private readonly ResourceApiService $apis,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, AuthContext $auth, int $resourceId): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->apis->list($resourceId));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function show(Request $request, AuthContext $auth, int $resourceId, int $apiId): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->apis->get($resourceId, $apiId));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function create(Request $request, AuthContext $auth, int $resourceId): Response
    {
        try {
            $api = $this->apis->create($resourceId, $request->json());
            $this->audit->log(
                $request,
                'resource_api.created',
                $auth->userId(),
                'resource_api',
                (string) $api['id'],
                ['resourceId' => $resourceId, 'slug' => $api['slug']],
            );

            return Response::data($api, 201);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $status = $e->getCode() === 404 ? 404 : 400;

            return Response::error($status === 404 ? 'NOT_FOUND' : 'BAD_REQUEST', $e->getMessage(), $status);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function update(Request $request, AuthContext $auth, int $resourceId, int $apiId): Response
    {
        try {
            $api = $this->apis->update($resourceId, $apiId, $request->json());
            $this->audit->log(
                $request,
                'resource_api.updated',
                $auth->userId(),
                'resource_api',
                (string) $apiId,
                ['resourceId' => $resourceId],
            );

            return Response::data($api);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $status = $e->getCode() === 404 ? 404 : 400;

            return Response::error($status === 404 ? 'NOT_FOUND' : 'BAD_REQUEST', $e->getMessage(), $status);
        }
    }

    public function delete(Request $request, AuthContext $auth, int $resourceId, int $apiId): Response
    {
        try {
            $this->apis->delete($resourceId, $apiId);
            $this->audit->log(
                $request,
                'resource_api.deleted',
                $auth->userId(),
                'resource_api',
                (string) $apiId,
                ['resourceId' => $resourceId],
            );

            return new Response(204, '');
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }
}
