<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Core\Exception\ValidationFailedException;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Webhooks\WebhookService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class WebhooksController
{
    public function __construct(
        private readonly WebhookService $webhooks,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->webhooks->list());
    }

    public function show(Request $request, AuthContext $auth, int $id): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->webhooks->get($id));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function create(Request $request, AuthContext $auth): Response
    {
        try {
            $created = $this->webhooks->create($request->json());
            $this->audit->log(
                $request,
                'webhook.created',
                $auth->userId(),
                'webhook',
                (string) $created['id'],
                ['name' => $created['name']],
            );

            return Response::data($created, 201);
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function update(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $updated = $this->webhooks->update($id, $request->json());
            $this->audit->log(
                $request,
                'webhook.updated',
                $auth->userId(),
                'webhook',
                (string) $id,
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

    public function delete(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $this->webhooks->delete($id);
            $this->audit->log(
                $request,
                'webhook.deleted',
                $auth->userId(),
                'webhook',
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
            return Response::data($this->webhooks->deliveries($id));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function test(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $delivery = $this->webhooks->test($id);
            $this->audit->log(
                $request,
                'webhook.tested',
                $auth->userId(),
                'webhook',
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
