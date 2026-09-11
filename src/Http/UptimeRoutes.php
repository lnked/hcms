<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Auth\AuthContext;
use Cms\Http\Controllers\UptimeController;

final class UptimeRoutes
{
    public static function register(Router $router, UptimeController $uptime): void
    {
        $auth = static function (?AuthContext $context): ?Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return null;
        };

        $router->add('GET', '/admin/api/uptime/summary', function (Request $request, array $params, ?AuthContext $context) use ($uptime, $auth): Response {
            unset($params);

            return $auth($context) ?? $uptime->summary($request, $context);
        });
        $router->add('GET', '/admin/api/uptime/status', function (Request $request, array $params, ?AuthContext $context) use ($uptime, $auth): Response {
            unset($params);

            return $auth($context) ?? $uptime->status($request, $context);
        });
        $router->add('GET', '/admin/api/uptime/targets', function (Request $request, array $params, ?AuthContext $context) use ($uptime, $auth): Response {
            unset($params);

            return $auth($context) ?? $uptime->index($request, $context);
        });
        $router->add('POST', '/admin/api/uptime/targets', function (Request $request, array $params, ?AuthContext $context) use ($uptime, $auth): Response {
            unset($params);

            return $auth($context) ?? $uptime->create($request, $context);
        });
        $router->add('PATCH', '/admin/api/uptime/targets/{id}', function (Request $request, array $params, ?AuthContext $context) use ($uptime, $auth): Response {
            return $auth($context) ?? $uptime->update($request, $context, (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/uptime/targets/{id}', function (Request $request, array $params, ?AuthContext $context) use ($uptime, $auth): Response {
            return $auth($context) ?? $uptime->delete($request, $context, (int) $params['id']);
        });
        $router->add('GET', '/admin/api/uptime/targets/{id}/incidents', function (Request $request, array $params, ?AuthContext $context) use ($uptime, $auth): Response {
            return $auth($context) ?? $uptime->incidents($request, $context, (int) $params['id']);
        });
        $router->add('GET', '/admin/api/uptime/targets/{id}/checks', function (Request $request, array $params, ?AuthContext $context) use ($uptime, $auth): Response {
            return $auth($context) ?? $uptime->checks($request, $context, (int) $params['id']);
        });
        $router->add('POST', '/admin/api/uptime/targets/{id}/check', function (Request $request, array $params, ?AuthContext $context) use ($uptime, $auth): Response {
            return $auth($context) ?? $uptime->checkNow($request, $context, (int) $params['id']);
        });
        $router->add('POST', '/admin/api/uptime/run', function (Request $request, array $params, ?AuthContext $context) use ($uptime, $auth): Response {
            unset($params);

            return $auth($context) ?? $uptime->run($request, $context);
        });
    }
}
