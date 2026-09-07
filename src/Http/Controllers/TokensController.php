<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\ApiTokenService;
use Cms\Auth\AuthContext;
use Cms\Http\Request;
use Cms\Http\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TokensController
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->tokens->list());
    }

    public function show(Request $request, AuthContext $auth, int $id): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->tokens->get($id));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function create(Request $request, AuthContext $auth): Response
    {
        try {
            $created = $this->tokens->create($request->json());
            $this->audit->log(
                $request,
                'token.created',
                $auth->userId(),
                'token',
                (string) $created['meta']['id'],
                ['name' => $created['meta']['name']],
            );

            return Response::data([
                'token' => $created['token'],
                'meta' => $created['meta'],
            ], 201);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function update(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $meta = $this->tokens->update($id, $request->json());
            $this->audit->log(
                $request,
                'token.updated',
                $auth->userId(),
                'token',
                (string) $id,
            );

            return Response::data($meta);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function updateGrants(Request $request, AuthContext $auth, int $id): Response
    {
        return $this->update($request, $auth, $id);
    }

    public function restore(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $this->tokens->restore($id);
            $this->audit->log(
                $request,
                'token.restored',
                $auth->userId(),
                'token',
                (string) $id,
            );

            return Response::data($this->tokens->get($id));
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function delete(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $this->tokens->revoke($id);
            $this->audit->log(
                $request,
                'token.revoked',
                $auth->userId(),
                'token',
                (string) $id,
            );

            return new Response(204, '');
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }
}
