<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Api\QueryEngine;
use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Resources\ResourceRepository;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class EntriesController
{
    public function __construct(
        private readonly QueryEngine $query,
        private readonly ResourceRepository $resources,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, AuthContext $auth, int $resourceId): Response
    {
        unset($auth);
        try {
            $slug = $this->slug($resourceId);

            return Response::json($this->query->list($slug, $request->query));
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function show(Request $request, AuthContext $auth, int $resourceId, int $entryId): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->query->find($this->slug($resourceId), $entryId));
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        }
    }

    public function create(Request $request, AuthContext $auth, int $resourceId): Response
    {
        try {
            $slug = $this->slug($resourceId);
            $entry = $this->query->create($slug, $request->json());
            $this->audit->log(
                $request,
                'entry.created',
                $auth->userId(),
                'entry',
                (string) ($entry['id'] ?? ''),
                ['resourceId' => $resourceId, 'slug' => $slug],
            );

            return Response::data($entry, 201);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function update(Request $request, AuthContext $auth, int $resourceId, int $entryId): Response
    {
        try {
            $slug = $this->slug($resourceId);
            $entry = $this->query->patch($slug, $entryId, $request->json());
            $this->audit->log(
                $request,
                'entry.updated',
                $auth->userId(),
                'entry',
                (string) $entryId,
                ['resourceId' => $resourceId, 'slug' => $slug],
            );

            return Response::data($entry);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function delete(Request $request, AuthContext $auth, int $resourceId, int $entryId): Response
    {
        try {
            $slug = $this->slug($resourceId);
            $this->query->delete($slug, $entryId);
            $this->audit->log(
                $request,
                'entry.deleted',
                $auth->userId(),
                'entry',
                (string) $entryId,
                ['resourceId' => $resourceId, 'slug' => $slug],
            );

            return new Response(204, '');
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    private function slug(int $resourceId): string
    {
        $resource = $this->resources->find($resourceId);
        if ($resource === null) {
            throw new RuntimeException('Resource not found', 404);
        }
        if (($resource['status'] ?? '') !== 'published') {
            throw new RuntimeException('Resource must be published before managing entries', 400);
        }

        return (string) $resource['slug'];
    }

    private function runtimeError(RuntimeException $e): Response
    {
        $code = $e->getCode();
        $status = in_array($code, [403, 404], true) ? $code : 400;

        return Response::error(
            $status === 404 ? 'NOT_FOUND' : ($status === 403 ? 'FORBIDDEN' : 'BAD_REQUEST'),
            $e->getMessage(),
            $status,
        );
    }
}
