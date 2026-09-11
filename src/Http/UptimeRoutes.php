<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Auth\AuthContext;
use Cms\Http\Controllers\UptimeController;

final class UptimeRoutes
{
    public static function register(Router $router, UptimeController $uptime): void
    {
        $router->add('GET', '/admin/api/uptime/summary', function (Request $request, array $params, ?AuthContext $context) use ($uptime): Response {
            unset($params);

            return $uptime->summary($request, RequireAuth::context($context));
        });
        $router->add('GET', '/admin/api/uptime/status', function (Request $request, array $params, ?AuthContext $context) use ($uptime): Response {
            unset($params);

            return $uptime->status($request, RequireAuth::context($context));
        });
        $router->add('GET', '/admin/api/uptime/targets', function (Request $request, array $params, ?AuthContext $context) use ($uptime): Response {
            unset($params);

            return $uptime->index($request, RequireAuth::context($context));
        });
        $router->add('POST', '/admin/api/uptime/targets', function (Request $request, array $params, ?AuthContext $context) use ($uptime): Response {
            unset($params);

            return $uptime->create($request, RequireAuth::context($context));
        });
        $router->add('PATCH', '/admin/api/uptime/targets/{id}', function (Request $request, array $params, ?AuthContext $context) use ($uptime): Response {
            return $uptime->update($request, RequireAuth::context($context), (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/uptime/targets/{id}', function (Request $request, array $params, ?AuthContext $context) use ($uptime): Response {
            return $uptime->delete($request, RequireAuth::context($context), (int) $params['id']);
        });
        $router->add('GET', '/admin/api/uptime/targets/{id}/incidents', function (Request $request, array $params, ?AuthContext $context) use ($uptime): Response {
            return $uptime->incidents($request, RequireAuth::context($context), (int) $params['id']);
        });
        $router->add('GET', '/admin/api/uptime/targets/{id}/checks', function (Request $request, array $params, ?AuthContext $context) use ($uptime): Response {
            return $uptime->checks($request, RequireAuth::context($context), (int) $params['id']);
        });
        $router->add('POST', '/admin/api/uptime/targets/{id}/check', function (Request $request, array $params, ?AuthContext $context) use ($uptime): Response {
            return $uptime->checkNow($request, RequireAuth::context($context), (int) $params['id']);
        });
        $router->add('POST', '/admin/api/uptime/run', function (Request $request, array $params, ?AuthContext $context) use ($uptime): Response {
            unset($params);

            return $uptime->run($request, RequireAuth::context($context));
        });
    }
}
