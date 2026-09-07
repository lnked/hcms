<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Api\QueryEngine;
use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Content\EntryRevisionService;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Resources\EntryImportExportService;
use Cms\Resources\ResourceRepository;
use Cms\Webhooks\WebhookDispatcher;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class EntriesController
{
    public function __construct(
        private readonly QueryEngine $query,
        private readonly ResourceRepository $resources,
        private readonly AuditLogger $audit,
        private readonly EntryImportExportService $importExport,
        private readonly ?WebhookDispatcher $webhooks = null,
        private readonly ?EntryRevisionService $revisions = null,
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
            $this->webhooks?->dispatchAfterResponse('entry.created', [
                'resourceId' => $resourceId,
                'slug' => $slug,
                'entry' => $entry,
            ], $resourceId);

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
            $before = $this->query->find($slug, $entryId);
            $entry = $this->query->patch($slug, $entryId, $request->json());
            $this->revisions?->snapshot($resourceId, $entryId, $before, $entry, $auth->userId());
            $this->audit->log(
                $request,
                'entry.updated',
                $auth->userId(),
                'entry',
                (string) $entryId,
                ['resourceId' => $resourceId, 'slug' => $slug],
            );
            $this->webhooks?->dispatchAfterResponse('entry.updated', [
                'resourceId' => $resourceId,
                'slug' => $slug,
                'entry' => $entry,
            ], $resourceId);

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
            $before = $this->query->find($slug, $entryId);
            $this->revisions?->snapshot($resourceId, $entryId, $before, null, $auth->userId());
            $this->query->delete($slug, $entryId);
            $this->audit->log(
                $request,
                'entry.deleted',
                $auth->userId(),
                'entry',
                (string) $entryId,
                ['resourceId' => $resourceId, 'slug' => $slug],
            );
            $this->webhooks?->dispatchAfterResponse('entry.deleted', [
                'resourceId' => $resourceId,
                'slug' => $slug,
                'entryId' => $entryId,
            ], $resourceId);

            return new Response(204, '');
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function bulkDelete(Request $request, AuthContext $auth, int $resourceId): Response
    {
        try {
            $slug = $this->slug($resourceId);
            $body = $request->json();
            $ids = $body['ids'] ?? null;
            if (!is_array($ids) || $ids === []) {
                throw new InvalidArgumentException('ids array is required');
            }

            $normalized = [];
            foreach ($ids as $id) {
                if (!is_numeric($id)) {
                    throw new InvalidArgumentException('ids must be numbers');
                }
                $normalized[] = (int) $id;
            }
            $normalized = array_values(array_unique($normalized));

            $deleted = 0;
            foreach ($normalized as $id) {
                try {
                    $this->query->delete($slug, $id);
                    ++$deleted;
                    $this->webhooks?->dispatchAfterResponse('entry.deleted', [
                        'resourceId' => $resourceId,
                        'slug' => $slug,
                        'entryId' => $id,
                    ], $resourceId);
                } catch (RuntimeException $e) {
                    if ($e->getCode() !== 404) {
                        throw $e;
                    }
                }
            }

            $this->audit->log(
                $request,
                'entry.bulk_deleted',
                $auth->userId(),
                'resource',
                (string) $resourceId,
                ['resourceId' => $resourceId, 'slug' => $slug, 'ids' => $normalized, 'deleted' => $deleted],
            );

            return Response::data(['deleted' => $deleted]);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function revisions(Request $request, AuthContext $auth, int $resourceId, int $entryId): Response
    {
        unset($auth);
        if ($this->revisions === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'Revisions unavailable', 503);
        }
        try {
            $this->slug($resourceId);
            $limit = max(1, min(100, (int) ($request->query['limit'] ?? 50)));

            return Response::data($this->revisions->list($resourceId, $entryId, $limit));
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        }
    }

    public function restoreRevision(Request $request, AuthContext $auth, int $resourceId, int $entryId, int $revisionId): Response
    {
        if ($this->revisions === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'Revisions unavailable', 503);
        }
        try {
            $slug = $this->slug($resourceId);
            $data = $this->revisions->dataForRestore($resourceId, $entryId, $revisionId);
            $before = $this->query->find($slug, $entryId);
            unset($data['id'], $data['createdAt'], $data['updatedAt'], $data['created_at'], $data['updated_at'], $data['deleted_at']);
            $entry = $this->query->patch($slug, $entryId, $data);
            $this->revisions->snapshot($resourceId, $entryId, $before, $entry, $auth->userId());
            $this->audit->log(
                $request,
                'entry.revision_restored',
                $auth->userId(),
                'entry',
                (string) $entryId,
                ['resourceId' => $resourceId, 'revisionId' => $revisionId],
            );
            $this->webhooks?->dispatchAfterResponse('entry.updated', [
                'resourceId' => $resourceId,
                'slug' => $slug,
                'entry' => $entry,
            ], $resourceId);

            return Response::data($entry);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function export(Request $request, AuthContext $auth, int $resourceId): Response
    {
        try {
            $slug = $this->slug($resourceId);
            $format = (string) ($request->query['format'] ?? 'json');
            $fieldsParam = trim((string) ($request->query['fields'] ?? ''));
            $fields = $fieldsParam === ''
                ? null
                : array_values(array_filter(array_map('trim', explode(',', $fieldsParam)), static fn (string $f): bool => $f !== ''));

            $result = $this->importExport->export($slug, $format, $fields);
            $this->audit->log(
                $request,
                'entry.exported',
                $auth->userId(),
                'resource',
                (string) $resourceId,
                ['slug' => $slug, 'format' => $format, 'fields' => $fields],
            );

            return Response::text($result['body'], 200, $result['contentType'])->withHeaders([
                'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function import(Request $request, AuthContext $auth, int $resourceId): Response
    {
        try {
            $slug = $this->slug($resourceId);
            [$format, $content] = $this->resolveImportPayload($request);
            $result = $this->importExport->import($slug, $format, $content);
            $this->audit->log(
                $request,
                'entry.imported',
                $auth->userId(),
                'resource',
                (string) $resourceId,
                [
                    'slug' => $slug,
                    'format' => $format,
                    'created' => $result['created'],
                    'failed' => $result['failed'],
                ],
            );

            return Response::data($result);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveImportPayload(Request $request): array
    {
        /** @var array<string, mixed>|null $file */
        $file = $_FILES['file'] ?? null;
        if (is_array($file)) {
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('file upload failed');
            }
            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new InvalidArgumentException('invalid uploaded file');
            }
            $content = (string) file_get_contents($tmp);
            $format = (string) ($_POST['format'] ?? $request->query['format'] ?? '');
            if ($format === '') {
                $name = strtolower((string) ($file['name'] ?? ''));
                $format = str_ends_with($name, '.csv') ? 'csv' : 'json';
            }

            return [$format, $content];
        }

        $body = $request->json();
        $format = (string) ($body['format'] ?? $request->query['format'] ?? 'json');
        $content = $body['content'] ?? null;
        if (!is_string($content)) {
            throw new InvalidArgumentException('content string is required (or multipart file)');
        }

        return [$format, $content];
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
