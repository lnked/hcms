<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Audit\AuditLogger;
use Cms\Auth\AuthContext;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Translates\TranslationService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TranslatesController
{
    public function __construct(
        private readonly TranslationService $translates,
        private readonly AuditLogger $audit,
    ) {
    }

    public function listLocales(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->translates->listLocales());
    }

    public function createLocale(Request $request, AuthContext $auth): Response
    {
        try {
            $created = $this->translates->createLocale($request->json());
            $this->audit->log(
                $request,
                'locale.created',
                $auth->userId(),
                'locale',
                (string) $created['code'],
            );

            return Response::data($created, 201);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }
    }

    public function updateLocale(Request $request, AuthContext $auth, string $code): Response
    {
        try {
            $updated = $this->translates->updateLocale($code, $request->json());
            $this->audit->log($request, 'locale.updated', $auth->userId(), 'locale', $code);

            return Response::data($updated);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function setDefaultLocale(Request $request, AuthContext $auth, string $code): Response
    {
        try {
            $updated = $this->translates->setDefaultLocale($code);
            $this->audit->log($request, 'locale.default_set', $auth->userId(), 'locale', $code);

            return Response::data($updated);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function deleteLocale(Request $request, AuthContext $auth, string $code): Response
    {
        try {
            $this->translates->deleteLocale($code);
            $this->audit->log($request, 'locale.deleted', $auth->userId(), 'locale', $code);

            return new Response(204, '');
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function listTranslations(Request $request, AuthContext $auth): Response
    {
        unset($auth);
        $search = isset($request->query['search']) && is_string($request->query['search'])
            ? $request->query['search']
            : null;

        return Response::data($this->translates->listTranslations($search));
    }

    public function showTranslation(Request $request, AuthContext $auth, int $id): Response
    {
        unset($request, $auth);
        try {
            return Response::data($this->translates->getTranslation($id));
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function createTranslation(Request $request, AuthContext $auth): Response
    {
        try {
            $created = $this->translates->createTranslation($request->json());
            $this->audit->log(
                $request,
                'translation.created',
                $auth->userId(),
                'translation',
                (string) $created['id'],
                ['key' => $created['key']],
            );

            return Response::data($created, 201);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }
    }

    public function updateTranslation(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $updated = $this->translates->updateTranslation($id, $request->json());
            $this->audit->log($request, 'translation.updated', $auth->userId(), 'translation', (string) $id);

            return Response::data($updated);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function deleteTranslation(Request $request, AuthContext $auth, int $id): Response
    {
        try {
            $this->translates->deleteTranslation($id);
            $this->audit->log($request, 'translation.deleted', $auth->userId(), 'translation', (string) $id);

            return new Response(204, '');
        } catch (RuntimeException $e) {
            return Response::error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function getSettings(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->translates->getApiSettings());
    }

    public function saveSettings(Request $request, AuthContext $auth): Response
    {
        try {
            $saved = $this->translates->saveApiSettings($request->json());
            $this->audit->log(
                $request,
                'translation.settings_updated',
                $auth->userId(),
                'translation',
                null,
                $saved,
            );

            return Response::data($saved);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }
    }

    public function export(Request $request, AuthContext $auth): Response
    {
        unset($request, $auth);

        return Response::data($this->translates->export());
    }

    public function import(Request $request, AuthContext $auth): Response
    {
        try {
            $body = $request->json();
            $map = $body['translations'] ?? $body;
            if (!is_array($map)) {
                throw new InvalidArgumentException('Body must be a map of key → locale values');
            }
            // Strip wrapper keys if present
            if (isset($map['translations']) && is_array($map['translations'])) {
                $map = $map['translations'];
            }
            $result = $this->translates->import($map);
            $this->audit->log(
                $request,
                'translation.imported',
                $auth->userId(),
                'translation',
                null,
                $result,
            );

            return Response::data($result);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Throwable $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function publicIndex(Request $request, ?AuthContext $auth): Response
    {
        $settings = $this->translates->getApiSettings();
        if (!$settings['enabled']) {
            return Response::error('NOT_FOUND', 'Translates API is disabled', 404);
        }
        if ($settings['requireToken'] && $auth === null) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $locale = isset($request->query['locale']) && is_string($request->query['locale'])
            ? $request->query['locale']
            : null;
        $keys = $this->parseKeys($request);

        try {
            $map = $this->translates->publicMap($locale, $keys);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }

        $etag = $this->translates->etag();
        $ifNoneMatch = $request->header('If-None-Match');
        if (is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
            return new Response(304, '', [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=30',
            ]);
        }

        $body = json_encode(['data' => $map], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"data":{}}';

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
            if (is_string($raw)) {
                foreach (explode(',', $raw) as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $keys[] = $part;
                    }
                }
            } elseif (is_array($raw)) {
                foreach ($raw as $part) {
                    if (is_string($part) && trim($part) !== '') {
                        $keys[] = trim($part);
                    }
                }
            }
        }

        return $keys === [] ? null : array_values(array_unique($keys));
    }
}
