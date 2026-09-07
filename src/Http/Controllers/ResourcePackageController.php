<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Resources\ResourcePackageService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ResourcePackageController
{
    public function __construct(
        private readonly ResourcePackageService $packages,
        private readonly AuditLogger $audit,
    ) {
    }

    public function export(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $includeData = in_array(
                strtolower((string) ($request->query['includeData'] ?? '0')),
                ['1', 'true', 'yes'],
                true,
            );
            $result = $this->packages->export($id, $includeData);
            $this->audit->log(
                $request,
                'resource.package_exported',
                $auth->userId(),
                'resource',
                (string) $id,
                ['includeData' => $includeData],
            );

            return Response::text($result['body'], 200, $result['contentType'])->withHeaders([
                'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $status = $e->getCode() === 404 ? 404 : 400;

            return Response::error($status === 404 ? 'NOT_FOUND' : 'BAD_REQUEST', $e->getMessage(), $status);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function import(Request $request, AuthContext $auth): Response
    {
        try {
            [$package, $slugOverride] = $this->resolveImportPayload($request);
            $result = $this->packages->import($package, $slugOverride);
            $resourceId = (int) ($result['resource']['id'] ?? 0);
            $this->audit->log(
                $request,
                'resource.package_imported',
                $auth->userId(),
                'resource',
                (string) $resourceId,
                [
                    'slugResolved' => $result['slugResolved'],
                    'mediaRemapped' => $result['mediaRemapped'],
                    'entriesCreated' => $result['entries']['created'],
                    'warnings' => count($result['warnings']),
                ],
            );

            return Response::data($result, 201);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $status = $e->getCode() === 404 ? 404 : 400;

            return Response::error($status === 404 ? 'NOT_FOUND' : 'BAD_REQUEST', $e->getMessage(), $status);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?string}
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
            $raw = file_get_contents($tmp);
            if ($raw === false) {
                throw new InvalidArgumentException('failed to read uploaded file');
            }
            if (strlen($raw) > ResourcePackageService::MAX_BYTES) {
                throw new InvalidArgumentException('Import payload exceeds 50MB limit');
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('Uploaded file must be a JSON package');
            }
            $slug = isset($_POST['slug']) && is_string($_POST['slug']) ? $_POST['slug'] : null;

            return [$decoded, $slug];
        }

        $json = $request->json();
        if (isset($json['package']) && is_array($json['package'])) {
            $package = $json['package'];
            $slug = isset($json['slug']) && is_string($json['slug']) ? $json['slug'] : null;

            return [$package, $slug];
        }

        // Raw package object as body
        if (isset($json['kind']) && $json['kind'] === ResourcePackageService::KIND) {
            $slug = isset($json['slug']) && is_string($json['slug']) ? $json['slug'] : null;
            unset($json['slug']);

            return [$json, $slug];
        }

        $raw = $request->rawBody;
        if (trim($raw) !== '') {
            if (strlen($raw) > ResourcePackageService::MAX_BYTES) {
                throw new InvalidArgumentException('Import payload exceeds 50MB limit');
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && ($decoded['kind'] ?? null) === ResourcePackageService::KIND) {
                return [$decoded, null];
            }
        }

        throw new InvalidArgumentException('Expected { package, slug? } or a cms.resource.package JSON body');
    }
}
