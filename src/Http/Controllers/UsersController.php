<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Auth\UsersService;
use Cms\Http\Request;
use Cms\Http\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class UsersController
{
    public function __construct(
        private readonly UsersService $users,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->users->list());
    }

    public function create(Request $request, AuthContext $auth): Response
    {
        try {
            $created = $this->users->create($request->json());
            $this->audit->log(
                $request,
                'user.created',
                $auth->userId(),
                'user',
                (string) $created['id'],
                ['email' => $created['email']],
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
            $payload = $request->json();
            $updated = $this->users->update($id, $payload, $auth);
            $this->audit->log(
                $request,
                'user.updated',
                $auth->userId(),
                'user',
                (string) $id,
                isset($payload['password']) ? ['passwordReset' => true] : [],
            );

            return Response::data($updated);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $status = str_contains($message, 'Only an owner') || str_contains($message, 'Cannot reset')
                ? 403
                : 422;
            $code = $status === 403 ? 'FORBIDDEN' : 'VALIDATION_ERROR';

            return Response::error($code, $message, $status);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function delete(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $actorId = $auth->userId();
            if ($actorId === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }
            $this->users->delete($id, $actorId);
            $this->audit->log(
                $request,
                'user.deleted',
                $actorId,
                'user',
                (string) $id,
            );

            return new Response(204, '');
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function getAcl(Request $request, AuthContext $auth, int $id): Response
    {
        unset($request);
        try {
            return Response::data($this->users->getAcl($id, $auth));
        } catch (InvalidArgumentException $e) {
            return Response::error('FORBIDDEN', $e->getMessage(), 403);
        } catch (RuntimeException $e) {
            $code = $e->getCode() === 503 ? 'SERVICE_UNAVAILABLE' : 'NOT_FOUND';
            $status = $e->getCode() === 503 ? 503 : 404;

            return Response::error($code, $e->getMessage(), $status);
        }
    }

    public function setAcl(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $acl = $this->users->setAcl($id, $request->json(), $auth);
            $this->audit->log(
                $request,
                'user.acl_updated',
                $auth->userId(),
                'user',
                (string) $id,
                ['aclEnabled' => $acl['aclEnabled']],
            );

            return Response::data($acl);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $status = str_contains($message, 'Only an owner') || str_contains($message, 'Cannot change ACL')
                ? 403
                : 422;
            $code = $status === 403 ? 'FORBIDDEN' : 'VALIDATION_ERROR';

            return Response::error($code, $message, $status);
        } catch (RuntimeException $e) {
            $code = $e->getCode() === 503 ? 'SERVICE_UNAVAILABLE' : 'NOT_FOUND';
            $status = $e->getCode() === 503 ? 503 : 404;

            return Response::error($code, $e->getMessage(), $status);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }
}
