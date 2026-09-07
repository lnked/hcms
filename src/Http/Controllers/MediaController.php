<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Fields\Types\MediaFieldConfig;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Media\MediaService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class MediaController
{
    public function __construct(
        private readonly MediaService $media,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, AuthContext $auth): Response
    {
        unset($auth);
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = max(1, (int) ($request->query['limit'] ?? 40));

        return Response::json($this->media->page($page, $limit));
    }

    public function show(Request $request, AuthContext $auth, int $id): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->media->get($id));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function upload(Request $request, AuthContext $auth): Response
    {
        try {
            /** @var array<string, mixed>|null $file */
            $file = $_FILES['file'] ?? null;
            if (!is_array($file)) {
                return Response::error('VALIDATION_ERROR', 'file is required (multipart field name: file)', 422);
            }

            $formats = $this->parseFormats($_POST['formats'] ?? null);
            $sizes = $this->parseSizes($_POST['sizes'] ?? null);
            $positions = MediaFieldConfig::normalizePositions($this->parseJsonObject($_POST['positions'] ?? null));
            $rotation = MediaFieldConfig::normalizeRotation($_POST['rotation'] ?? 0);

            if ($sizes !== []) {
                $result = $this->media->uploadWithTransforms($file, $sizes, $rotation, $positions, $formats);
                $this->audit->log(
                    $request,
                    'media.uploaded',
                    $auth->userId(),
                    'media',
                    (string) $result['id'],
                    ['name' => $result['media']['originalName'] ?? null, 'variants' => array_keys($result['variants'])],
                );

                return Response::data($result, 201);
            }

            $item = $this->media->upload($file, $formats);
            $this->audit->log(
                $request,
                'media.uploaded',
                $auth->userId(),
                'media',
                (string) $item['id'],
                ['name' => $item['originalName']],
            );

            return Response::data([
                'id' => (int) $item['id'],
                'rotation' => 0,
                'positions' => [],
                'variants' => [],
                'media' => $item,
            ], 201);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function regenerate(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $body = $request->json();
            $sizes = MediaFieldConfig::normalizeSizes($body['sizes'] ?? []);
            $positions = MediaFieldConfig::normalizePositions($body['positions'] ?? []);
            $rotation = MediaFieldConfig::normalizeRotation($body['rotation'] ?? 0);

            $result = $this->media->regenerateVariants($id, $sizes, $rotation, $positions);
            $this->audit->log(
                $request,
                'media.regenerated',
                $auth->userId(),
                'media',
                (string) $id,
                ['variants' => array_keys($result['variants']), 'rotation' => $rotation],
            );

            return Response::data($result);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $code = $e->getCode() === 404 ? 404 : 500;
            return Response::error($code === 404 ? 'NOT_FOUND' : 'INTERNAL_ERROR', $e->getMessage(), $code);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function delete(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $this->media->delete($id);
            $this->audit->log($request, 'media.deleted', $auth->userId(), 'media', (string) $id);

            return new Response(204, '');
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function bulkDelete(Request $request, AuthContext $auth): Response
    {
        try {
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
                    $this->media->delete($id);
                    ++$deleted;
                    $this->audit->log($request, 'media.deleted', $auth->userId(), 'media', (string) $id);
                } catch (RuntimeException $e) {
                    if ($e->getCode() !== 404) {
                        throw $e;
                    }
                }
            }

            $this->audit->log(
                $request,
                'media.bulk_deleted',
                $auth->userId(),
                'media',
                null,
                ['ids' => $normalized, 'deleted' => $deleted],
            );

            return Response::data(['deleted' => $deleted]);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function file(Request $request, int $id): Response
    {
        unset($request);
        $file = $this->media->absoluteFile($id);
        if ($file === null) {
            return Response::error('NOT_FOUND', 'Media not found', 404);
        }

        $contents = (string) file_get_contents($file['path']);
        $mime = strtolower(trim(explode(';', $file['mime'])[0]));
        $inline = $this->isInlineSafeMime($mime);
        $filename = $this->safeDownloadName($file['name']);

        return new Response(
            200,
            $contents,
            [
                'Content-Type' => $mime !== '' ? $mime : 'application/octet-stream',
                'Content-Length' => (string) strlen($contents),
                'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                    . '; filename="' . $filename . '"'
                    . "; filename*=UTF-8''" . rawurlencode($file['name']),
                'Cache-Control' => 'public, max-age=86400',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * @return list<string>|null
     */
    private function parseFormats(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return array_values(array_filter($decoded, 'is_string'));
            }
            return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));
        }
        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_string'));
        }

        return null;
    }

    /**
     * @return list<array{prefix: string, width: int, height: int, mode: string, position: string}>
     */
    private function parseSizes(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('sizes must be valid JSON');
            }
            $raw = $decoded;
        }

        return MediaFieldConfig::normalizeSizes($raw);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJsonObject(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new InvalidArgumentException('positions must be valid JSON object');
        }

        return $decoded;
    }

    private function isInlineSafeMime(string $mime): bool
    {
        return match (true) {
            str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' => true,
            $mime === 'application/pdf' => true,
            str_starts_with($mime, 'video/') => true,
            default => false,
        };
    }

    private function safeDownloadName(string $name): string
    {
        $base = basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $name));
        $ascii = preg_replace('/["\\\\\\r\\n]+/', '_', $base) ?? 'file';
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $ascii) ?? 'file';

        return $ascii !== '' ? $ascii : 'file';
    }
}
