<?php

declare(strict_types=1);

namespace Cms\Http\Controllers;

use Cms\Api\QueryEngine;
use Cms\Auth\AuthContext;
use Cms\Hooks\HookRejectedException;
use Cms\Hooks\InboundEndpointService;
use Cms\Hooks\RequestMeta;
use Cms\Hooks\ResourceHookService;
use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Resources\ResourceRepository;
use Cms\Resources\ResourceService;
use Cms\Security\RateLimitExceeded;
use Cms\Security\SpamGuard;
use Cms\Webhooks\WebhookDispatcher;
use InvalidArgumentException;
use RuntimeException;

final class PublicInboundController
{
    public function __construct(
        private readonly InboundEndpointService $endpoints,
        private readonly ResourceRepository $resources,
        private readonly QueryEngine $query,
        private readonly ?ResourceHookService $resourceHooks = null,
        private readonly ?SpamGuard $spamGuard = null,
        private readonly ?WebhookDispatcher $webhooks = null,
    ) {
    }

    public function handle(Request $request, string $slug, ?AuthContext $auth): Response
    {
        unset($auth);
        try {
            if ($request->method !== 'POST') {
                return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
            }

            $endpoint = $this->endpoints->findEnabledBySlug($slug);
            if ($endpoint === null) {
                return Response::error('NOT_FOUND', 'Inbound endpoint not found', 404);
            }

            $payload = $request->json();
            $meta = RequestMeta::fromRequest($request, 'inbound');

            $persistResourceId = $endpoint['persist_resource_id'] === null
                ? null
                : (int) $endpoint['persist_resource_id'];

            if ($persistResourceId !== null) {
                $resource = $this->resources->find($persistResourceId);
                if ($resource === null || ($resource['status'] ?? '') !== 'published') {
                    return Response::error('BAD_REQUEST', 'Persist resource is not published', 400);
                }
                $resourceSlug = (string) ($resource['slug'] ?? '');
                if ($resourceSlug === '') {
                    return Response::error('BAD_REQUEST', 'Persist resource has no slug', 400);
                }
                $this->guardAnonymousCreate($request, $resourceSlug, $resource, $payload);
            }

            $forwarded = $this->endpoints->forward($endpoint, $payload, $meta);
            $payload = $forwarded['payload'];
            $hookResponse = $forwarded['response'];

            if ($persistResourceId === null) {
                $body = ['accepted' => true];
                if ($hookResponse !== []) {
                    $body['hook'] = $hookResponse;
                }

                return Response::data($body, 200);
            }

            $resource = $this->resources->find($persistResourceId);
            if ($resource === null) {
                return Response::error('BAD_REQUEST', 'Persist resource missing', 400);
            }
            $resourceSlug = (string) ($resource['slug'] ?? '');
            if ($resourceSlug === '') {
                return Response::error('BAD_REQUEST', 'Persist resource has no slug', 400);
            }
            $fieldMap = $endpoint['field_map'];
            if (is_string($fieldMap)) {
                $decoded = json_decode($fieldMap, true);
                $fieldMap = is_array($decoded) ? $decoded : null;
            }
            /** @var array<string, string>|null $fieldMap */
            $fieldMap = is_array($fieldMap) ? $fieldMap : null;
            $payload = InboundEndpointService::applyFieldMap($payload, $fieldMap);

            if ($this->resourceHooks !== null) {
                $this->resourceHooks->runBeforeCreate($persistResourceId, $resourceSlug, $payload, $meta);
            }

            $entry = $this->query->create($resourceSlug, $payload, ['public' => true]);

            $afterHook = [];
            if ($this->resourceHooks !== null) {
                $afterHook = $this->resourceHooks->runAfterCreate($persistResourceId, $resourceSlug, $entry, $meta);
            }

            if ($this->webhooks !== null) {
                $this->webhooks->dispatchAfterResponse('entry.created', [
                    'resourceId' => $persistResourceId,
                    'slug' => $resourceSlug,
                    'entry' => $entry,
                    'meta' => $meta,
                    'inboundSlug' => $slug,
                ], $persistResourceId);
            }

            $mergedHook = array_merge($hookResponse, $afterHook);
            if ($mergedHook !== []) {
                return Response::json(['data' => $entry, 'hook' => $mergedHook], 201);
            }

            return Response::data($entry, 201);
        } catch (HookRejectedException $e) {
            return Response::error($e->errorCode, $e->getMessage(), 422);
        } catch (RateLimitExceeded $e) {
            return Response::tooManyRequests($e->retryAfter, $e->limit);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            $status = in_array($code, [401, 403, 404, 405], true) ? $code : 400;

            return Response::error('BAD_REQUEST', $e->getMessage(), $status);
        }
    }

    /**
     * @param array<string, mixed> $resource
     * @param array<string, mixed> $payload
     */
    private function guardAnonymousCreate(Request $request, string $slug, array $resource, array &$payload): void
    {
        if ($this->spamGuard === null) {
            return;
        }

        $raw = $resource['settings_json'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $settings = is_array($decoded) ? $decoded : [];
        } elseif (is_array($raw)) {
            $settings = $raw;
        } else {
            $settings = [];
        }
        $settings = ResourceService::normalizeSettings($settings);
        $this->spamGuard->assertCreateAllowed($request, $slug, $settings, $payload);
        $honeypot = is_string($settings['spam']['honeypotField'] ?? null) ? $settings['spam']['honeypotField'] : '';
        if ($honeypot !== '') {
            unset($payload[$honeypot]);
        }
        unset($payload['captchaToken'], $payload['_startedAt']);
    }
}
