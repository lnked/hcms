<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Auth\AuthContext;
use Cms\Http\Controllers\SystemController;

/**
 * Route table of admin system endpoints (/admin/api/system/*).
 */
final class SystemRoutes
{
    public static function register(Router $router, SystemController $system): void
    {
        $router->add('GET', '/admin/api/system/version', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $system->version($request, $context);
        });
        $router->add('GET', '/admin/api/system/stats', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $system->stats($request, $context);
        });
        $router->add('GET', '/admin/api/system/stats/timeseries', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $system->timeseries($request, $context);
        });
        $router->add('GET', '/admin/api/system/changelog', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
            unset($params, $context);

            return $system->changelog($request);
        });
        $router->add('POST', '/admin/api/system/changelog/seen', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $system->markSeen($request, $context);
        });
        $router->add('GET', '/admin/api/system/update/check', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $system->updateCheck($request, $context);
        });
        $router->add('POST', '/admin/api/system/update/preview', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $system->updatePreview($request, $context);
        });
        $router->add('GET', '/admin/api/system/update/status', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $system->updateStatus($request, $context);
        });
        $router->add('POST', '/admin/api/system/update/run', function (Request $request, array $params, ?AuthContext $context) use ($system): Response {
            unset($params);
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $system->updateRun($request, $context);
        });
    }
}
