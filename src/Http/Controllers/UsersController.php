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
            $updated = $this->users->update($id, $request->json());
            $this->audit->log(
                $request,
                'user.updated',
                $auth->userId(),
                'user',
                (string) $id,
            );

            return Response::data($updated);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
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
}
