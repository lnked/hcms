<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Database\MigrationService;
use Cms\Http\Request;
use Cms\Http\Response;
use InvalidArgumentException;
use RuntimeException;

final class MigrationController
{
    public function __construct(
        private readonly MigrationService $migrations,
        private readonly AuditLogger $audit,
    ) {
    }

    public function apply(Request $request, AuthContext $auth, int $resourceId): Response
    {
        $payload = $request->json();
        try {
            $result = $this->migrations->applyForResource($resourceId, [
                'confirmDestructive' => (bool) ($payload['confirmDestructive'] ?? false),
            ]);
            $this->audit->log(
                $request,
                'schema.migrated',
                $auth->userId(),
                'resource',
                (string) $resourceId,
                $result,
            );

            return Response::data($result);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }
}
