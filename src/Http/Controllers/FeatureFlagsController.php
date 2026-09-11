<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Core\Exception\ValidationFailedException;
use Cms\FeatureFlags\FeatureFlagService;
use Cms\Http\Request;
use Cms\Http\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class FeatureFlagsController
{
    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, AuthContext $auth): Response
    {
        unset($auth);
        $search = isset($request->query['search']) && \is_string($request->query['search'])
            ? $request->query['search']
            : null;
        $type = isset($request->query['type']) && \is_string($request->query['type'])
            ? $request->query['type']
            : null;
        $enabled = null;
        if (isset($request->query['enabled'])) {
            $raw = $request->query['enabled'];
            if ($raw === '1' || $raw === 'true' || $raw === true) {
                $enabled = true;
            } elseif ($raw === '0' || $raw === 'false' || $raw === false) {
                $enabled = false;
            }
        }

        return Response::data($this->flags->list($search, $type, $enabled));
    }

    public function show(Request $request, AuthContext $auth, int $id): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->flags->get($id));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function create(Request $request, AuthContext $auth): Response
    {
        try {
            $created = $this->flags->create($request->json());
            $this->audit->log(
                $request,
                'feature_flag.created',
                $auth->userId(),
                'feature_flag',
                (string) $created['id'],
                ['key' => $created['key']],
            );

            return Response::data($created, 201);
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function update(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $updated = $this->flags->update($id, $request->json());
            $this->audit->log(
                $request,
                'feature_flag.updated',
                $auth->userId(),
                'feature_flag',
                (string) $id,
                ['key' => $updated['key']],
            );

            return Response::data($updated);
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
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
            $this->flags->delete($id);
            $this->audit->log($request, 'feature_flag.deleted', $auth->userId(), 'feature_flag', (string) $id);

            return new Response(204, '');
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function getSettings(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->flags->getApiSettings());
    }

    public function saveSettings(Request $request, AuthContext $auth): Response
    {
        try {
            $saved = $this->flags->saveApiSettings($request->json());
            $this->audit->log(
                $request,
                'feature_flag.settings_updated',
                $auth->userId(),
                'feature_flag',
                null,
                $saved,
            );

            return Response::data($saved);
        } catch (ValidationFailedException $e) {
            return Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->fields() ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }
    }

    public function publicIndex(Request $request, ?AuthContext $auth): Response
    {
        $settings = $this->flags->getApiSettings();
        if (!$settings['enabled']) {
            return Response::error('NOT_FOUND', 'Feature flags API is disabled', 404);
        }
        if ($settings['requireToken'] && $auth === null) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $keys = $this->parseKeys($request);
        $subject = $this->parseSubject($request);
        $result = $this->flags->publicMap($keys, $subject);
        $map = $result['map'];
        $etag = $this->flags->etag($result['subject']);
        $cacheControl = $result['hasAb'] ? 'private, no-store' : 'public, max-age=30';
        $ifNoneMatch = $request->header('If-None-Match');
        if (\is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
            return new Response(304, '', [
                'ETag' => $etag,
                'Cache-Control' => $cacheControl,
            ]);
        }

        $body = json_encode(['data' => $map], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"data":{}}';
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'ETag' => $etag,
            'Cache-Control' => $cacheControl,
        ];
        if ($result['hasAb']) {
            $headers['Vary'] = 'X-Flag-Subject';
        }

        return new Response(200, $body, $headers);
    }

    private function parseSubject(Request $request): ?string
    {
        foreach (['subject', 'sid'] as $q) {
            if (isset($request->query[$q]) && \is_string($request->query[$q])) {
                $v = trim($request->query[$q]);
                if ($v !== '') {
                    return mb_substr($v, 0, 128);
                }
            }
        }
        $header = $request->header('X-Flag-Subject');
        if (\is_string($header)) {
            $v = trim($header);
            if ($v !== '') {
                return mb_substr($v, 0, 128);
            }
        }

        return null;
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
