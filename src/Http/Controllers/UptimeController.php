<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Uptime\UptimeService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class UptimeController
{
    public function __construct(
        private readonly UptimeService $uptime,
        private readonly AuditLogger $audit,
    ) {
    }

    public function summary(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->uptime->summary());
    }

    public function status(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->uptime->statusPayload());
    }

    public function index(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->uptime->listTargets());
    }

    public function create(Request $request, AuthContext $auth): Response
    {
        try {
            $created = $this->uptime->createTarget($request->json());
            $this->audit->log(
                $request,
                'uptime.target_created',
                $auth->userId(),
                'uptime_target',
                (string) $created['id'],
                ['name' => $created['name'], 'url' => $created['url']],
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
            $updated = $this->uptime->updateTarget($id, $request->json());
            $this->audit->log(
                $request,
                'uptime.target_updated',
                $auth->userId(),
                'uptime_target',
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
            $this->uptime->deleteTarget($id);
            $this->audit->log(
                $request,
                'uptime.target_deleted',
                $auth->userId(),
                'uptime_target',
                (string) $id,
            );

            return new Response(204, '');
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function incidents(Request $request, AuthContext $auth, int $id): Response
    {
        unset($auth);
        $limit = isset($request->query['limit']) && is_numeric($request->query['limit'])
            ? (int) $request->query['limit']
            : 50;
        try {
            return Response::data($this->uptime->incidents($id, $limit));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function checks(Request $request, AuthContext $auth, int $id): Response
    {
        unset($auth);
        $limit = isset($request->query['limit']) && is_numeric($request->query['limit'])
            ? (int) $request->query['limit']
            : 50;
        try {
            return Response::data($this->uptime->checks($id, $limit));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function checkNow(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $result = $this->uptime->checkNow($id);
            $this->audit->log(
                $request,
                'uptime.target_checked',
                $auth->userId(),
                'uptime_target',
                (string) $id,
                ['ok' => $result['ok'] ?? null],
            );

            return Response::data($result);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function run(Request $request, AuthContext $auth): Response
    {
        try {
            $results = $this->uptime->runDue();
            $this->audit->log(
                $request,
                'uptime.run',
                $auth->userId(),
                'uptime',
                null,
                ['checked' => count($results)],
            );

            return Response::data(['results' => $results, 'checked' => count($results)]);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }
}
