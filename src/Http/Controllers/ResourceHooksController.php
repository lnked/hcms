<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Core\Exception\ValidationFailedException;
use Cms\Hooks\ResourceHookService;
use Cms\Http\Request;
use Cms\Http\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ResourceHooksController
{
    public function __construct(
        private readonly ResourceHookService $hooks,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, AuthContext $auth, int $resourceId): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->hooks->list($resourceId));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function show(Request $request, AuthContext $auth, int $resourceId, int $id): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->hooks->get($resourceId, $id));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function create(Request $request, AuthContext $auth, int $resourceId): Response
    {
        try {
            $created = $this->hooks->create($resourceId, $request->json());
            $this->audit->log(
                $request,
                'resource_hook.created',
                $auth->userId(),
                'resource_hook',
                (string) $created['id'],
                ['resourceId' => $resourceId, 'name' => $created['name']],
            );

            return Response::data($created, 201);
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function update(Request $request, AuthContext $auth, int $resourceId, int $id): Response
    {
        try {
            $updated = $this->hooks->update($resourceId, $id, $request->json());
            $this->audit->log(
                $request,
                'resource_hook.updated',
                $auth->userId(),
                'resource_hook',
                (string) $id,
                ['resourceId' => $resourceId],
            );

            return Response::data($updated);
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function delete(Request $request, AuthContext $auth, int $resourceId, int $id): Response
    {
        try {
            $this->hooks->delete($resourceId, $id);
            $this->audit->log(
                $request,
                'resource_hook.deleted',
                $auth->userId(),
                'resource_hook',
                (string) $id,
                ['resourceId' => $resourceId],
            );

            return new Response(204, '');
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function deliveries(Request $request, AuthContext $auth, int $resourceId, int $id): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->hooks->deliveries($resourceId, $id));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function test(Request $request, AuthContext $auth, int $resourceId, int $id): Response
    {
        try {
            $delivery = $this->hooks->test($resourceId, $id);
            $this->audit->log(
                $request,
                'resource_hook.tested',
                $auth->userId(),
                'resource_hook',
                (string) $id,
                ['resourceId' => $resourceId, 'deliveryId' => $delivery['id'] ?? null],
            );

            return Response::data($delivery);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }
}
