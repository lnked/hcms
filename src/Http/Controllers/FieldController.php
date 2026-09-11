<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Fields\FieldService;
use Cms\Http\Request;
use Cms\Http\Response;
use InvalidArgumentException;
use RuntimeException;

final class FieldController
{
    public function __construct(
        private readonly FieldService $fields,
        private readonly AuditLogger $audit,
    ) {
    }

    public function types(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->fields->availableTypes());
    }

    public function index(Request $request, AuthContext $auth, int $resourceId): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->fields->listForResource($resourceId));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function replace(Request $request, AuthContext $auth, int $resourceId): Response
    {
        $payload = $request->json();
        $fields = $payload['fields'] ?? $payload;
        if (!\is_array($fields)) {
            return Response::error('VALIDATION_ERROR', 'fields must be an array', 422);
        }

        try {
            /** @var list<array<string, mixed>> $list */
            $list = array_values($fields);
            $result = $this->fields->replaceSchema($resourceId, $list);
            $this->audit->log($request, 'schema.updated', $auth->userId(), 'resource', (string) $resourceId);

            return Response::data($result);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function create(Request $request, AuthContext $auth, int $resourceId): Response
    {
        try {
            $field = $this->fields->create($resourceId, $request->json());
            $this->audit->log($request, 'field.created', $auth->userId(), 'field', (string) $field['id']);

            return Response::data($field, 201);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function update(Request $request, AuthContext $auth, int $fieldId): Response
    {
        try {
            $field = $this->fields->update($fieldId, $request->json());
            $this->audit->log($request, 'field.updated', $auth->userId(), 'field', (string) $fieldId);

            return Response::data($field);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function delete(Request $request, AuthContext $auth, int $fieldId): Response
    {
        try {
            $this->fields->delete($fieldId);
            $this->audit->log($request, 'field.deleted', $auth->userId(), 'field', (string) $fieldId);

            return new Response(204, '');
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }
}
