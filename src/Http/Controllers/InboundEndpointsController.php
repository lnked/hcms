<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Hooks\InboundEndpointService;
use Cms\Http\Request;
use Cms\Http\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class InboundEndpointsController
{
    public function __construct(
        private readonly InboundEndpointService $endpoints,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->endpoints->list());
    }

    public function show(Request $request, AuthContext $auth, int $id): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->endpoints->get($id));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function create(Request $request, AuthContext $auth): Response
    {
        try {
            $created = $this->endpoints->create($request->json());
            $this->audit->log(
                $request,
                'inbound_endpoint.created',
                $auth->userId(),
                'inbound_endpoint',
                (string) $created['id'],
                ['slug' => $created['slug']],
            );

            return Response::data($created, 201);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function update(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $updated = $this->endpoints->update($id, $request->json());
            $this->audit->log(
                $request,
                'inbound_endpoint.updated',
                $auth->userId(),
                'inbound_endpoint',
                (string) $id,
            );

            return Response::data($updated);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function delete(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $this->endpoints->delete($id);
            $this->audit->log(
                $request,
                'inbound_endpoint.deleted',
                $auth->userId(),
                'inbound_endpoint',
                (string) $id,
            );

            return new Response(204, '');
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function deliveries(Request $request, AuthContext $auth, int $id): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->endpoints->deliveries($id));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function test(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $delivery = $this->endpoints->test($id);
            $this->audit->log(
                $request,
                'inbound_endpoint.tested',
                $auth->userId(),
                'inbound_endpoint',
                (string) $id,
                ['deliveryId' => $delivery['id'] ?? null],
            );

            return Response::data($delivery);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }
}
