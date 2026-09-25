<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Content\EntryCommentService;
use Cms\Content\EntryRevisionService;
use Cms\Content\EntryService;
use Cms\Core\Exception\HttpException;
use Cms\Core\Exception\ValidationFailedException;
use Cms\Events\EventBus;
use Cms\Hooks\RequestMeta;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Preview\PreviewTokenService;
use Cms\Resources\EntryImportExportService;
use Cms\Resources\ResourceRepository;
use Cms\Resources\ResourceService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class EntriesController
{
    public function __construct(
        private readonly EntryService $entries,
        private readonly AuditLogger $audit,
        private readonly EntryImportExportService $importExport,
        private readonly ?EventBus $events = null,
        private readonly ?EntryRevisionService $revisions = null,
        private readonly ?PreviewTokenService $previewTokens = null,
        private readonly ?ResourceRepository $resources = null,
        private readonly ?EntryCommentService $comments = null,
    ) {
    }

    public function index(Request $request, AuthContext $auth, int $resourceId): Response
    {
        try {
            return Response::json($this->entries->list($resourceId, $request->query, $auth));
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
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
        unset($request);
        try {
            return Response::data($this->entries->find($resourceId, $entryId, $auth));
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        }
    }

    public function relationLabels(Request $request, AuthContext $auth, int $resourceId): Response
    {
        unset($auth);
        try {
            $field = trim((string) ($request->query['field'] ?? ''));
            if ($field === '') {
                throw new InvalidArgumentException('field is required');
            }
            $ids = [];
            foreach (explode(',', (string) ($request->query['ids'] ?? '')) as $raw) {
                $raw = trim($raw);
                if ($raw !== '' && ctype_digit($raw)) {
                    $ids[] = (int) $raw;
                }
            }
            if (\count($ids) > 100) {
                $ids = \array_slice($ids, 0, 100);
            }

            return Response::data($this->entries->relationLabels($resourceId, $field, $ids));
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function create(Request $request, AuthContext $auth, int $resourceId): Response
    {
        try {
            $slug = $this->entries->slug($resourceId);
            $entry = $this->entries->create($resourceId, $request->json(), $auth->userId(), $auth);
            $this->audit->log(
                $request,
                'entry.created',
                $auth->userId(),
                'entry',
                (string) ($entry['id'] ?? ''),
                ['resourceId' => $resourceId, 'slug' => $slug],
            );
            $this->events?->dispatchAfterResponse('entry.created', [
                'resourceId' => $resourceId,
                'slug' => $slug,
                'entry' => $entry,
                'meta' => RequestMeta::fromRequest($request, 'admin'),
            ], $resourceId);

            return Response::data($entry, 201);
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
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
            $slug = $this->entries->slug($resourceId);
            $before = $this->entries->find($resourceId, $entryId, $auth);
            $entry = $this->entries->patch($resourceId, $entryId, $request->json(), $auth->userId(), $auth);
            $this->revisions?->snapshot($resourceId, $entryId, $before, $entry, $auth->userId());
            $this->audit->log(
                $request,
                'entry.updated',
                $auth->userId(),
                'entry',
                (string) $entryId,
                ['resourceId' => $resourceId, 'slug' => $slug],
            );
            $this->events?->dispatchAfterResponse('entry.updated', [
                'resourceId' => $resourceId,
                'slug' => $slug,
                'entry' => $entry,
                'meta' => RequestMeta::fromRequest($request, 'admin'),
            ], $resourceId);

            return Response::data($entry);
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
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
            $slug = $this->entries->slug($resourceId);
            $before = $this->entries->find($resourceId, $entryId, $auth);
            $this->revisions?->snapshot($resourceId, $entryId, $before, null, $auth->userId());
            $this->entries->delete($resourceId, $entryId, $auth);
            $this->audit->log(
                $request,
                'entry.deleted',
                $auth->userId(),
                'entry',
                (string) $entryId,
                ['resourceId' => $resourceId, 'slug' => $slug],
            );
            $this->events?->dispatchAfterResponse('entry.deleted', [
                'resourceId' => $resourceId,
                'slug' => $slug,
                'entryId' => $entryId,
                'meta' => RequestMeta::fromRequest($request, 'admin'),
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
            $slug = $this->entries->slug($resourceId);
            $body = $request->json();
            $ids = $body['ids'] ?? null;
            if (!\is_array($ids) || $ids === []) {
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
                    $this->entries->delete($resourceId, $id, $auth);
                    ++$deleted;
                    $this->events?->dispatchAfterResponse('entry.deleted', [
                        'resourceId' => $resourceId,
                        'slug' => $slug,
                        'entryId' => $id,
                        'meta' => RequestMeta::fromRequest($request, 'admin'),
                    ], $resourceId);
                } catch (RuntimeException $e) {
                    if (!($e instanceof HttpException && $e->status() === 404) && $e->getCode() !== 404) {
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
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
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
            $this->entries->slug($resourceId);
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
            $slug = $this->entries->slug($resourceId);
            $data = $this->revisions->dataForRestore($resourceId, $entryId, $revisionId);
            $before = $this->entries->find($resourceId, $entryId, $auth);
            unset(
                $data['id'],
                $data['createdAt'],
                $data['updatedAt'],
                $data['created_at'],
                $data['updated_at'],
                $data['deleted_at'],
                $data['createdById'],
                $data['updatedById'],
                $data['createdBy'],
                $data['updatedBy'],
            );
            $entry = $this->entries->patch($resourceId, $entryId, $data, $auth->userId(), $auth);
            $this->revisions->snapshot($resourceId, $entryId, $before, $entry, $auth->userId());
            $this->audit->log(
                $request,
                'entry.revision_restored',
                $auth->userId(),
                'entry',
                (string) $entryId,
                ['resourceId' => $resourceId, 'revisionId' => $revisionId],
            );
            $this->events?->dispatchAfterResponse('entry.updated', [
                'resourceId' => $resourceId,
                'slug' => $slug,
                'entry' => $entry,
                'meta' => RequestMeta::fromRequest($request, 'admin'),
            ], $resourceId);

            return Response::data($entry);
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function preview(Request $request, AuthContext $auth, int $resourceId, int $entryId): Response
    {
        if ($this->previewTokens === null || $this->resources === null) {
            return Response::error('SERVICE_UNAVAILABLE', 'Preview unavailable', 503);
        }
        try {
            $slug = $this->entries->slug($resourceId);
            $this->entries->find($resourceId, $entryId, $auth);
            $row = $this->resources->find($resourceId);
            if ($row === null) {
                return Response::error('NOT_FOUND', 'Resource not found', 404);
            }
            $raw = $row['settings_json'] ?? [];
            if (\is_string($raw)) {
                $decoded = json_decode($raw, true);
                $settings = \is_array($decoded) ? $decoded : [];
            } elseif (\is_array($raw)) {
                $settings = $raw;
            } else {
                $settings = [];
            }
            $settings = ResourceService::normalizeSettings($settings);
            $template = (string) ($settings['preview']['url'] ?? '');
            if ($template === '') {
                return Response::error(
                    'VALIDATION_ERROR',
                    'Preview URL is not configured',
                    422,
                    ['preview.url' => ['Preview URL is not configured']],
                );
            }

            $issued = $this->previewTokens->issue($resourceId, $entryId, $slug);
            $previewUrl = PreviewTokenService::buildPreviewUrl($template, $slug, $entryId, $issued);
            $this->audit->log(
                $request,
                'entry.preview_issued',
                $auth->userId(),
                'entry',
                (string) $entryId,
                ['resourceId' => $resourceId, 'expiresAt' => $issued['expiresAt']],
            );

            return Response::data([
                'previewUrl' => $previewUrl,
                'token' => $issued['token'],
                'expiresAt' => $issued['expiresAt'],
            ]);
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function listTranslations(Request $request, AuthContext $auth, int $resourceId, int $entryId): Response
    {
        unset($request);
        try {
            return Response::data($this->entries->listTranslations($resourceId, $entryId, $auth));
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function createTranslation(Request $request, AuthContext $auth, int $resourceId, int $entryId): Response
    {
        try {
            $payload = $request->json();
            $locale = isset($payload['locale']) && \is_string($payload['locale']) ? trim($payload['locale']) : '';
            if ($locale === '') {
                return Response::error('VALIDATION_ERROR', 'locale is required', 422, [
                    'locale' => ['locale is required'],
                ]);
            }
            $slug = $this->entries->slug($resourceId);
            $entry = $this->entries->createTranslation($resourceId, $entryId, $locale, $auth);
            $this->audit->log(
                $request,
                'entry.translation_created',
                $auth->userId(),
                'entry',
                (string) ($entry['id'] ?? ''),
                ['resourceId' => $resourceId, 'slug' => $slug, 'locale' => $locale, 'sourceEntryId' => $entryId],
            );

            return Response::data($entry, 201);
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function listComments(Request $request, AuthContext $auth, int $resourceId, int $entryId): Response
    {
        unset($request);
        try {
            $this->assertWorkflowEnabled($resourceId);
            $this->entries->find($resourceId, $entryId, $auth);
            if ($this->comments === null) {
                return Response::error('SERVICE_UNAVAILABLE', 'Comments unavailable', 503);
            }

            return Response::data($this->comments->list($resourceId, $entryId));
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function createComment(Request $request, AuthContext $auth, int $resourceId, int $entryId): Response
    {
        try {
            $this->assertWorkflowEnabled($resourceId);
            $this->entries->find($resourceId, $entryId, $auth);
            if ($this->comments === null) {
                return Response::error('SERVICE_UNAVAILABLE', 'Comments unavailable', 503);
            }
            $userId = $auth->userId();
            if ($userId === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }
            $payload = $request->json();
            $body = isset($payload['body']) && \is_string($payload['body']) ? $payload['body'] : '';
            $comment = $this->comments->create($resourceId, $entryId, $userId, $body);
            $this->audit->log(
                $request,
                'entry.comment_created',
                $userId,
                'entry',
                (string) $entryId,
                ['resourceId' => $resourceId, 'commentId' => $comment['id'] ?? null],
            );

            return Response::data($comment, 201);
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function deleteComment(
        Request $request,
        AuthContext $auth,
        int $resourceId,
        int $entryId,
        int $commentId,
    ): Response {
        try {
            $this->assertWorkflowEnabled($resourceId);
            $this->entries->find($resourceId, $entryId, $auth);
            if ($this->comments === null) {
                return Response::error('SERVICE_UNAVAILABLE', 'Comments unavailable', 503);
            }
            $role = \Cms\Auth\RolePolicy::normalize(
                isset($auth->user['role']) ? (string) $auth->user['role'] : null,
            );
            $canModerate = \Cms\Auth\RolePolicy::can($role, 'entries.publish')
                || \in_array($role, ['owner', 'admin'], true);
            $this->comments->delete($resourceId, $entryId, $commentId, $auth->userId(), $canModerate);
            $this->audit->log(
                $request,
                'entry.comment_deleted',
                $auth->userId(),
                'entry',
                (string) $entryId,
                ['resourceId' => $resourceId, 'commentId' => $commentId],
            );

            return Response::data(['ok' => true]);
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceSettings(int $resourceId): array
    {
        if ($this->resources === null) {
            throw new RuntimeException('Unavailable', 503);
        }
        $row = $this->resources->find($resourceId);
        if ($row === null) {
            throw new RuntimeException('Resource not found', 404);
        }
        $raw = $row['settings_json'] ?? [];
        if (\is_string($raw)) {
            $decoded = json_decode($raw, true);
            $settings = \is_array($decoded) ? $decoded : [];
        } elseif (\is_array($raw)) {
            $settings = $raw;
        } else {
            $settings = [];
        }

        return ResourceService::normalizeSettings($settings);
    }

    private function assertWorkflowEnabled(int $resourceId): void
    {
        $settings = $this->resourceSettings($resourceId);
        if (!($settings['workflow']['enabled'] ?? false)) {
            throw new InvalidArgumentException('Workflow is not enabled for this resource');
        }
    }

    public function setStatus(Request $request, AuthContext $auth, int $resourceId, int $entryId): Response
    {
        try {
            if ($this->resources === null) {
                return Response::error('SERVICE_UNAVAILABLE', 'Unavailable', 503);
            }
            $row = $this->resources->find($resourceId);
            if ($row === null) {
                return Response::error('NOT_FOUND', 'Resource not found', 404);
            }
            $raw = $row['settings_json'] ?? [];
            if (\is_string($raw)) {
                $decoded = json_decode($raw, true);
                $settings = \is_array($decoded) ? $decoded : [];
            } elseif (\is_array($raw)) {
                $settings = $raw;
            } else {
                $settings = [];
            }
            $settings = ResourceService::normalizeSettings($settings);
            if (!($settings['workflow']['enabled'] ?? false)) {
                return Response::error('VALIDATION_ERROR', 'Workflow is not enabled for this resource', 422);
            }
            $payload = $request->json();
            $status = isset($payload['status']) && \is_string($payload['status']) ? $payload['status'] : '';
            if (!\in_array($status, ['draft', 'in_review', 'published'], true)) {
                return Response::error('VALIDATION_ERROR', 'Invalid status', 422, ['status' => ['Invalid status']]);
            }
            $role = \Cms\Auth\RolePolicy::normalize(
                isset($auth->user['role']) ? (string) $auth->user['role'] : null,
            );
            if ($status === 'published' && !\Cms\Auth\RolePolicy::can($role, 'entries.publish')) {
                return Response::error('FORBIDDEN', 'Insufficient role to publish', 403);
            }
            $slug = $this->entries->slug($resourceId);
            $entry = $this->entries->patch($resourceId, $entryId, ['status' => $status], $auth->userId(), $auth);
            $event = match ($status) {
                'in_review' => 'entry.submitted',
                'published' => 'entry.published',
                default => 'entry.unpublished',
            };
            $this->audit->log($request, $event, $auth->userId(), 'entry', (string) $entryId, [
                'resourceId' => $resourceId,
                'status' => $status,
            ]);
            $this->events?->dispatchAfterResponse($event, [
                'resourceId' => $resourceId,
                'slug' => $slug,
                'entry' => $entry,
                'meta' => RequestMeta::fromRequest($request, 'admin'),
            ], $resourceId);

            return Response::data($entry);
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
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
            $slug = $this->entries->slug($resourceId);
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
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
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
            $slug = $this->entries->slug($resourceId);
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
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
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
        if (\is_array($file)) {
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
        if (!\is_string($content)) {
            throw new InvalidArgumentException('content string is required (or multipart file)');
        }

        return [$format, $content];
    }


    private function runtimeError(RuntimeException $e): Response
    {
        if ($e instanceof HttpException) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status());
        }
        $code = $e->getCode();
        $status = \in_array($code, [403, 404], true) ? $code : 400;

        return Response::error(
            $status === 404 ? 'NOT_FOUND' : ($status === 403 ? 'FORBIDDEN' : 'BAD_REQUEST'),
            $e->getMessage(),
            $status,
        );
    }
}
