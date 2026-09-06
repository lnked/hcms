<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
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
            $item = $this->media->upload($file);
            $this->audit->log(
                $request,
                'media.uploaded',
                $auth->userId(),
                'media',
                (string) $item['id'],
                ['name' => $item['originalName']],
            );

            return Response::data($item, 201);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
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
