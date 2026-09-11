<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\KeyValues\KeyValueService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class KeyValuesController
{
    public function __construct(
        private readonly KeyValueService $entries,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, AuthContext $auth): Response
    {
        unset($auth);
        $search = isset($request->query['search']) && \is_string($request->query['search'])
            ? $request->query['search']
            : null;

        return Response::data($this->entries->list($search));
    }

    public function show(Request $request, AuthContext $auth, int $id): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->entries->get($id));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function create(Request $request, AuthContext $auth): Response
    {
        try {
            $created = $this->entries->create($request->json(), $auth->userId());
            $this->audit->log(
                $request,
                'key_value.created',
                $auth->userId(),
                'key_value',
                (string) $created['id'],
                ['key' => $created['key']],
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
            $updated = $this->entries->update($id, $request->json(), $auth->userId());
            $this->audit->log(
                $request,
                'key_value.updated',
                $auth->userId(),
                'key_value',
                (string) $id,
                ['key' => $updated['key']],
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
            $this->entries->delete($id);
            $this->audit->log($request, 'key_value.deleted', $auth->userId(), 'key_value', (string) $id);

            return new Response(204, '');
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function getSettings(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->entries->getApiSettings());
    }

    public function saveSettings(Request $request, AuthContext $auth): Response
    {
        try {
            $saved = $this->entries->saveApiSettings($request->json());
            $this->audit->log(
                $request,
                'key_value.settings_updated',
                $auth->userId(),
                'key_value',
                null,
                $saved,
            );

            return Response::data($saved);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }
    }

    public function publicIndex(Request $request, ?AuthContext $auth): Response
    {
        $settings = $this->entries->getApiSettings();
        if (!$settings['enabled']) {
            return Response::error('NOT_FOUND', 'Key-values API is disabled', 404);
        }
        if ($settings['requireToken'] && $auth === null) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $keys = $this->parseKeys($request);
        $map = $this->entries->publicMap($keys);
        $etag = $this->entries->etag();
        $ifNoneMatch = $request->header('If-None-Match');
        if (\is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
            return new Response(304, '', [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=30',
            ]);
        }

        // Public body is the bare map { [key]: value } — no data wrapper.
        $body = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return new Response(200, $body, [
            'Content-Type' => 'application/json; charset=utf-8',
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=30',
        ]);
    }

    /**
     * @return list<string>|null
     */
    private function parseKeys(Request $request): ?array
    {
        $keys = [];
        if (isset($request->query['keys'])) {
            $raw = $request->query['keys'];
            if (\is_string($raw)) {
                foreach (explode(',', $raw) as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $keys[] = $part;
                    }
                }
            } elseif (\is_array($raw)) {
                foreach ($raw as $part) {
                    if (\is_string($part) && trim($part) !== '') {
                        $keys[] = trim($part);
                    }
                }
            }
        }
        if (isset($request->query['key'])) {
            $raw = $request->query['key'];
            if (\is_string($raw) && trim($raw) !== '') {
                $keys[] = trim($raw);
            } elseif (\is_array($raw)) {
                foreach ($raw as $part) {
                    if (\is_string($part) && trim($part) !== '') {
                        $keys[] = trim($part);
                    }
                }
            }
        }

        return $keys === [] ? null : array_values(array_unique($keys));
    }
}
