<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Auth\AuthContext;
use Cms\Http\Controllers\EntriesController;
use Cms\Http\Controllers\FieldController;
use Cms\Http\Controllers\InboundEndpointsController;
use Cms\Http\Controllers\IntegrationsController;
use Cms\Http\Controllers\LogsController;
use Cms\Http\Controllers\MediaController;
use Cms\Http\Controllers\MigrationController;
use Cms\Http\Controllers\ResourceApiController;
use Cms\Http\Controllers\ResourceController;
use Cms\Http\Controllers\ResourceHooksController;
use Cms\Http\Controllers\ResourcePackageController;
use Cms\Http\Controllers\SettingsController;
use Cms\Http\Controllers\TokensController;
use Cms\Http\Controllers\UsersController;
use Cms\Http\Controllers\WebhooksController;

/**
 * Route table of admin resource / settings / integrations endpoints.
 */
final class AdminResourceRoutes
{
    public static function register(
        Router $router,
        ResourceController $resources,
        ResourceApiController $resourceApis,
        FieldController $fields,
        EntriesController $entries,
        MigrationController $migrations,
        TokensController $apiTokens,
        WebhooksController $webhooksApi,
        UsersController $users,
        ResourcePackageController $packages,
        MediaController $media,
        LogsController $logs,
        SettingsController $settingsController,
        IntegrationsController $integrations,
        ?ResourceHooksController $resourceHooks = null,
        ?InboundEndpointsController $inboundEndpoints = null,
    ): void {
        $router->add('GET', '/admin/api/resources', function (Request $request, array $params, ?AuthContext $context) use ($resources): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $resources->index($request, $context);
        });
        $router->add('POST', '/admin/api/resources', function (Request $request, array $params, ?AuthContext $context) use ($resources): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $resources->create($request, $context);
        });
        $router->add('GET', '/admin/api/resources/{id}', function (Request $request, array $params, ?AuthContext $context) use ($resources): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $resources->show($request, $context, (int) $params['id']);
        });
        $router->add('PATCH', '/admin/api/resources/{id}', function (Request $request, array $params, ?AuthContext $context) use ($resources): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $resources->update($request, $context, (int) $params['id']);
        });
        $router->add('POST', '/admin/api/resources/{id}/publish', function (Request $request, array $params, ?AuthContext $context) use ($resources): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $resources->publish($request, $context, (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/resources/{id}', function (Request $request, array $params, ?AuthContext $context) use ($resources): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $resources->delete($request, $context, (int) $params['id']);
        });

        $router->add('GET', '/admin/api/resources/{id}/apis', function (Request $request, array $params, ?AuthContext $context) use ($resourceApis): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $resourceApis->index($request, $context, (int) $params['id']);
        });
        $router->add('POST', '/admin/api/resources/{id}/apis', function (Request $request, array $params, ?AuthContext $context) use ($resourceApis): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $resourceApis->create($request, $context, (int) $params['id']);
        });
        $router->add('GET', '/admin/api/resources/{id}/apis/{apiId}', function (Request $request, array $params, ?AuthContext $context) use ($resourceApis): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $resourceApis->show($request, $context, (int) $params['id'], (int) $params['apiId']);
        });
        $router->add('PATCH', '/admin/api/resources/{id}/apis/{apiId}', function (Request $request, array $params, ?AuthContext $context) use ($resourceApis): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $resourceApis->update($request, $context, (int) $params['id'], (int) $params['apiId']);
        });
        $router->add('DELETE', '/admin/api/resources/{id}/apis/{apiId}', function (Request $request, array $params, ?AuthContext $context) use ($resourceApis): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $resourceApis->delete($request, $context, (int) $params['id'], (int) $params['apiId']);
        });

        if ($resourceHooks !== null) {
            $router->add('GET', '/admin/api/resources/{id}/hooks', function (Request $request, array $params, ?AuthContext $context) use ($resourceHooks): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $resourceHooks->index($request, $context, (int) $params['id']);
            });
            $router->add('POST', '/admin/api/resources/{id}/hooks', function (Request $request, array $params, ?AuthContext $context) use ($resourceHooks): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $resourceHooks->create($request, $context, (int) $params['id']);
            });
            $router->add('GET', '/admin/api/resources/{id}/hooks/{hookId}', function (Request $request, array $params, ?AuthContext $context) use ($resourceHooks): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $resourceHooks->show($request, $context, (int) $params['id'], (int) $params['hookId']);
            });
            $router->add('PATCH', '/admin/api/resources/{id}/hooks/{hookId}', function (Request $request, array $params, ?AuthContext $context) use ($resourceHooks): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $resourceHooks->update($request, $context, (int) $params['id'], (int) $params['hookId']);
            });
            $router->add('DELETE', '/admin/api/resources/{id}/hooks/{hookId}', function (Request $request, array $params, ?AuthContext $context) use ($resourceHooks): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $resourceHooks->delete($request, $context, (int) $params['id'], (int) $params['hookId']);
            });
            $router->add('GET', '/admin/api/resources/{id}/hooks/{hookId}/deliveries', function (Request $request, array $params, ?AuthContext $context) use ($resourceHooks): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $resourceHooks->deliveries($request, $context, (int) $params['id'], (int) $params['hookId']);
            });
            $router->add('POST', '/admin/api/resources/{id}/hooks/{hookId}/test', function (Request $request, array $params, ?AuthContext $context) use ($resourceHooks): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $resourceHooks->test($request, $context, (int) $params['id'], (int) $params['hookId']);
            });
        }

        $router->add('GET', '/admin/api/field-types', function (Request $request, array $params, ?AuthContext $context) use ($fields): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $fields->types($request, $context);
        });
        $router->add('GET', '/admin/api/resources/{id}/fields', function (Request $request, array $params, ?AuthContext $context) use ($fields): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $fields->index($request, $context, (int) $params['id']);
        });
        $router->add('PUT', '/admin/api/resources/{id}/fields', function (Request $request, array $params, ?AuthContext $context) use ($fields): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $fields->replace($request, $context, (int) $params['id']);
        });
        $router->add('POST', '/admin/api/resources/{id}/fields', function (Request $request, array $params, ?AuthContext $context) use ($fields): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $fields->create($request, $context, (int) $params['id']);
        });
        $router->add('PATCH', '/admin/api/fields/{id}', function (Request $request, array $params, ?AuthContext $context) use ($fields): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $fields->update($request, $context, (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/fields/{id}', function (Request $request, array $params, ?AuthContext $context) use ($fields): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $fields->delete($request, $context, (int) $params['id']);
        });

        $router->add('GET', '/admin/api/resources/{id}/entries', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $entries->index($request, $context, (int) $params['id']);
        });
        $router->add('POST', '/admin/api/resources/{id}/entries', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $entries->create($request, $context, (int) $params['id']);
        });
        $router->add('GET', '/admin/api/resources/{id}/entries/relation-labels', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $entries->relationLabels($request, $context, (int) $params['id']);
        });
        $router->add('GET', '/admin/api/resources/{id}/entries/export', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $entries->export($request, $context, (int) $params['id']);
        });
        $router->add('POST', '/admin/api/resources/{id}/entries/import', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $entries->import($request, $context, (int) $params['id']);
        });
        $router->add('POST', '/admin/api/resources/{id}/entries/bulk-delete', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $entries->bulkDelete($request, $context, (int) $params['id']);
        });
        $router->add('GET', '/admin/api/resources/{id}/entries/{entryId}', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $entries->show($request, $context, (int) $params['id'], (int) $params['entryId']);
        });
        $router->add('PATCH', '/admin/api/resources/{id}/entries/{entryId}', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $entries->update($request, $context, (int) $params['id'], (int) $params['entryId']);
        });
        $router->add('DELETE', '/admin/api/resources/{id}/entries/{entryId}', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $entries->delete($request, $context, (int) $params['id'], (int) $params['entryId']);
        });
        $router->add('GET', '/admin/api/resources/{id}/entries/{entryId}/revisions', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $entries->revisions($request, $context, (int) $params['id'], (int) $params['entryId']);
        });
        $router->add('POST', '/admin/api/resources/{id}/entries/{entryId}/revisions/{revId}/restore', function (Request $request, array $params, ?AuthContext $context) use ($entries): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $entries->restoreRevision(
                $request,
                $context,
                (int) $params['id'],
                (int) $params['entryId'],
                (int) $params['revId'],
            );
        });

        $router->add('POST', '/admin/api/resources/{id}/migrate', function (Request $request, array $params, ?AuthContext $context) use ($migrations): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $migrations->apply($request, $context, (int) $params['id']);
        });

        $router->add('GET', '/admin/api/tokens', function (Request $request, array $params, ?AuthContext $context) use ($apiTokens): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $apiTokens->index($request, $context);
        });
        $router->add('POST', '/admin/api/tokens', function (Request $request, array $params, ?AuthContext $context) use ($apiTokens): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $apiTokens->create($request, $context);
        });
        $router->add('GET', '/admin/api/tokens/{id}', function (Request $request, array $params, ?AuthContext $context) use ($apiTokens): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $apiTokens->show($request, $context, (int) $params['id']);
        });
        $router->add('PUT', '/admin/api/tokens/{id}/grants', function (Request $request, array $params, ?AuthContext $context) use ($apiTokens): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $apiTokens->updateGrants($request, $context, (int) $params['id']);
        });
        $router->add('PATCH', '/admin/api/tokens/{id}', function (Request $request, array $params, ?AuthContext $context) use ($apiTokens): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $apiTokens->update($request, $context, (int) $params['id']);
        });
        $router->add('POST', '/admin/api/tokens/{id}/restore', function (Request $request, array $params, ?AuthContext $context) use ($apiTokens): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $apiTokens->restore($request, $context, (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/tokens/{id}', function (Request $request, array $params, ?AuthContext $context) use ($apiTokens): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $apiTokens->delete($request, $context, (int) $params['id']);
        });

        $router->add('GET', '/admin/api/webhooks', function (Request $request, array $params, ?AuthContext $context) use ($webhooksApi): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $webhooksApi->index($request, $context);
        });
        $router->add('POST', '/admin/api/webhooks', function (Request $request, array $params, ?AuthContext $context) use ($webhooksApi): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $webhooksApi->create($request, $context);
        });
        $router->add('GET', '/admin/api/webhooks/{id}', function (Request $request, array $params, ?AuthContext $context) use ($webhooksApi): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $webhooksApi->show($request, $context, (int) $params['id']);
        });
        $router->add('PATCH', '/admin/api/webhooks/{id}', function (Request $request, array $params, ?AuthContext $context) use ($webhooksApi): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $webhooksApi->update($request, $context, (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/webhooks/{id}', function (Request $request, array $params, ?AuthContext $context) use ($webhooksApi): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $webhooksApi->delete($request, $context, (int) $params['id']);
        });
        $router->add('GET', '/admin/api/webhooks/{id}/deliveries', function (Request $request, array $params, ?AuthContext $context) use ($webhooksApi): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $webhooksApi->deliveries($request, $context, (int) $params['id']);
        });
        $router->add('POST', '/admin/api/webhooks/{id}/test', function (Request $request, array $params, ?AuthContext $context) use ($webhooksApi): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $webhooksApi->test($request, $context, (int) $params['id']);
        });

        if ($inboundEndpoints !== null) {
            $router->add('GET', '/admin/api/inbound-endpoints', function (Request $request, array $params, ?AuthContext $context) use ($inboundEndpoints): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $inboundEndpoints->index($request, $context);
            });
            $router->add('POST', '/admin/api/inbound-endpoints', function (Request $request, array $params, ?AuthContext $context) use ($inboundEndpoints): Response {
                unset($params);
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $inboundEndpoints->create($request, $context);
            });
            $router->add('GET', '/admin/api/inbound-endpoints/{id}', function (Request $request, array $params, ?AuthContext $context) use ($inboundEndpoints): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $inboundEndpoints->show($request, $context, (int) $params['id']);
            });
            $router->add('PATCH', '/admin/api/inbound-endpoints/{id}', function (Request $request, array $params, ?AuthContext $context) use ($inboundEndpoints): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $inboundEndpoints->update($request, $context, (int) $params['id']);
            });
            $router->add('DELETE', '/admin/api/inbound-endpoints/{id}', function (Request $request, array $params, ?AuthContext $context) use ($inboundEndpoints): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $inboundEndpoints->delete($request, $context, (int) $params['id']);
            });
            $router->add('GET', '/admin/api/inbound-endpoints/{id}/deliveries', function (Request $request, array $params, ?AuthContext $context) use ($inboundEndpoints): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $inboundEndpoints->deliveries($request, $context, (int) $params['id']);
            });
            $router->add('POST', '/admin/api/inbound-endpoints/{id}/test', function (Request $request, array $params, ?AuthContext $context) use ($inboundEndpoints): Response {
                if ($context === null) {
                    return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
                }

                return $inboundEndpoints->test($request, $context, (int) $params['id']);
            });
        }

        $router->add('GET', '/admin/api/users', function (Request $request, array $params, ?AuthContext $context) use ($users): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $users->index($request, $context);
        });
        $router->add('POST', '/admin/api/users', function (Request $request, array $params, ?AuthContext $context) use ($users): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $users->create($request, $context);
        });
        $router->add('PATCH', '/admin/api/users/{id}', function (Request $request, array $params, ?AuthContext $context) use ($users): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $users->update($request, $context, (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/users/{id}', function (Request $request, array $params, ?AuthContext $context) use ($users): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $users->delete($request, $context, (int) $params['id']);
        });
        $router->add('GET', '/admin/api/users/{id}/acl', function (Request $request, array $params, ?AuthContext $context) use ($users): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $users->getAcl($request, $context, (int) $params['id']);
        });
        $router->add('PATCH', '/admin/api/users/{id}/acl', function (Request $request, array $params, ?AuthContext $context) use ($users): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $users->setAcl($request, $context, (int) $params['id']);
        });

        $router->add('POST', '/admin/api/resources/package/import', function (Request $request, array $params, ?AuthContext $context) use ($packages): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $packages->import($request, $context);
        });
        $router->add('GET', '/admin/api/resources/{id}/package/export', function (Request $request, array $params, ?AuthContext $context) use ($packages): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $packages->export($request, $context, (int) $params['id']);
        });
        $router->add('GET', '/admin/api/media', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $media->index($request, $context);
        });
        $router->add('POST', '/admin/api/media', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $media->upload($request, $context);
        });
        $router->add('POST', '/admin/api/media/{id}/regenerate', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $media->regenerate($request, $context, (int) $params['id']);
        });
        $router->add('POST', '/admin/api/media/{id}/edit', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $media->edit($request, $context, (int) $params['id']);
        });
        $router->add('POST', '/admin/api/media/{id}/optimize', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $media->optimize($request, $context, (int) $params['id']);
        });
        $router->add('POST', '/admin/api/media/bulk-optimize', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $media->bulkOptimize($request, $context);
        });
        $router->add('POST', '/admin/api/media/bulk-delete', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $media->bulkDelete($request, $context);
        });
        $router->add('GET', '/admin/api/media/{id}', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $media->show($request, $context, (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/media/{id}', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $media->delete($request, $context, (int) $params['id']);
        });
        $router->add('GET', '/media/{id}/{filename}', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
            unset($context);

            return $media->file($request, (int) $params['id']);
        }, true);
        $router->add('GET', '/media/{id}', function (Request $request, array $params, ?AuthContext $context) use ($media): Response {
            unset($context);

            return $media->file($request, (int) $params['id']);
        }, true);

        $router->add('GET', '/admin/api/logs/audit', function (Request $request, array $params, ?AuthContext $context) use ($logs): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $logs->audit($request, $context);
        });
        $router->add('GET', '/admin/api/logs/api', function (Request $request, array $params, ?AuthContext $context) use ($logs): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $logs->api($request, $context);
        });
        $router->add('GET', '/admin/api/logs/anomalies', function (Request $request, array $params, ?AuthContext $context) use ($logs): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $logs->anomalies($request, $context);
        });
        $router->add('GET', '/admin/api/logs/ip-blocks', function (Request $request, array $params, ?AuthContext $context) use ($logs): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $logs->ipBlocks($request, $context);
        });
        $router->add('POST', '/admin/api/logs/ip-blocks', function (Request $request, array $params, ?AuthContext $context) use ($logs): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $logs->blockIp($request, $context);
        });
        $router->add('DELETE', '/admin/api/logs/ip-blocks/{id}', function (Request $request, array $params, ?AuthContext $context) use ($logs): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $logs->unblockIp($request, $context, (int) $params['id']);
        });

        $router->add('GET', '/admin/api/settings/locale', function (Request $request, array $params, ?AuthContext $context) use ($settingsController): Response {
            unset($request, $params, $context);

            return $settingsController->locale();
        }, true);
        $router->add('GET', '/admin/api/settings/api-access', function (Request $request, array $params, ?AuthContext $context) use ($settingsController): Response {
            unset($request, $params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $settingsController->apiAccess();
        });
        $router->add('GET', '/admin/api/settings/admin-base', function (Request $request, array $params, ?AuthContext $context) use ($settingsController): Response {
            unset($request, $params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $settingsController->adminBase();
        });
        $router->add('PATCH', '/admin/api/settings', function (Request $request, array $params, ?AuthContext $context) use ($settingsController): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $settingsController->update($request, $context);
        });

        $router->add('GET', '/admin/api/integrations/email', function (Request $request, array $params, ?AuthContext $context) use ($integrations): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $integrations->getEmail($request, $context);
        });
        $router->add('PUT', '/admin/api/integrations/email', function (Request $request, array $params, ?AuthContext $context) use ($integrations): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $integrations->updateEmail($request, $context);
        });
        $router->add('GET', '/admin/api/integrations/oauth', function (Request $request, array $params, ?AuthContext $context) use ($integrations): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $integrations->getOauth($request, $context);
        });
        $router->add('PUT', '/admin/api/integrations/oauth', function (Request $request, array $params, ?AuthContext $context) use ($integrations): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $integrations->updateOauth($request, $context);
        });
        $router->add('POST', '/admin/api/integrations/email/test', function (Request $request, array $params, ?AuthContext $context) use ($integrations): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $integrations->testEmail($request, $context);
        });
        $router->add('GET', '/admin/api/integrations/email/apis', function (Request $request, array $params, ?AuthContext $context) use ($integrations): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $integrations->listEmailApis($request, $context);
        });
        $router->add('POST', '/admin/api/integrations/email/apis', function (Request $request, array $params, ?AuthContext $context) use ($integrations): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $integrations->createEmailApi($request, $context);
        });
        $router->add('GET', '/admin/api/integrations/email/apis/{id}', function (Request $request, array $params, ?AuthContext $context) use ($integrations): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $integrations->getEmailApi($request, $context, (int) $params['id']);
        });
        $router->add('PATCH', '/admin/api/integrations/email/apis/{id}', function (Request $request, array $params, ?AuthContext $context) use ($integrations): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $integrations->updateEmailApi($request, $context, (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/integrations/email/apis/{id}', function (Request $request, array $params, ?AuthContext $context) use ($integrations): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $integrations->deleteEmailApi($request, $context, (int) $params['id']);
        });
    }
}
