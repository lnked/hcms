<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Auth\AuthContext;
use Cms\Http\Controllers\BackupsController;

final class BackupRoutes
{
    public static function register(Router $router, BackupsController $backups): void
    {
        $router->add('GET', '/admin/api/backups', function (Request $request, array $params, ?AuthContext $context) use ($backups): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $backups->list($request, $context);
        });
        $router->add('GET', '/admin/api/backups/status', function (Request $request, array $params, ?AuthContext $context) use ($backups): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $backups->status($request, $context);
        });
        $router->add('POST', '/admin/api/backups', function (Request $request, array $params, ?AuthContext $context) use ($backups): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $backups->create($request, $context);
        });

        // Static /cloud/* before /{id} so "cloud" is not captured as backup id.
        $router->add('GET', '/admin/api/backups/cloud', function (Request $request, array $params, ?AuthContext $context) use ($backups): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $backups->getCloud($request, $context);
        });
        $router->add('PUT', '/admin/api/backups/cloud', function (Request $request, array $params, ?AuthContext $context) use ($backups): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $backups->updateCloud($request, $context);
        });
        $router->add('POST', '/admin/api/backups/cloud/{provider}/connect', function (Request $request, array $params, ?AuthContext $context) use ($backups): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $backups->connect($request, $context, (string) ($params['provider'] ?? ''));
        });
        $router->add('GET', '/admin/api/backups/cloud/{provider}/callback', function (Request $request, array $params, ?AuthContext $context) use ($backups): Response {
            unset($context);

            return $backups->callback($request, (string) ($params['provider'] ?? ''));
        }, true);
        $router->add('POST', '/admin/api/backups/cloud/{provider}/disconnect', function (Request $request, array $params, ?AuthContext $context) use ($backups): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $backups->disconnect($request, $context, (string) ($params['provider'] ?? ''));
        });
        $router->add('POST', '/admin/api/backups/cloud/{provider}/test', function (Request $request, array $params, ?AuthContext $context) use ($backups): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $backups->test($request, $context, (string) ($params['provider'] ?? ''));
        });

        $router->add('POST', '/admin/api/backups/{id}/restore', function (Request $request, array $params, ?AuthContext $context) use ($backups): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $backups->restore($request, $context, (string) ($params['id'] ?? ''));
        });
        $router->add('DELETE', '/admin/api/backups/{id}', function (Request $request, array $params, ?AuthContext $context) use ($backups): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $backups->delete($request, $context, (string) ($params['id'] ?? ''));
        });
        $router->add('GET', '/admin/api/backups/{id}/download', function (Request $request, array $params, ?AuthContext $context) use ($backups): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $backups->download($request, $context, (string) ($params['id'] ?? ''));
        });
        $router->add('POST', '/admin/api/backups/{id}/push', function (Request $request, array $params, ?AuthContext $context) use ($backups): Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $backups->push($request, $context, (string) ($params['id'] ?? ''));
        });
    }
}
