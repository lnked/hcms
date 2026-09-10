<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Auth\AuthContext;
use Cms\Http\Controllers\KeyValuesController;

/**
 * Admin + public routes for Key-values store.
 */
final class KeyValuesRoutes
{
    public static function registerAdmin(Router $router, KeyValuesController $kv): void
    {
        $auth = static function (?AuthContext $context): ?Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return null;
        };

        $router->add('GET', '/admin/api/key-values/settings', function (Request $request, array $params, ?AuthContext $context) use ($kv, $auth): Response {
            unset($params);
            return $auth($context) ?? $kv->getSettings($request, $context);
        });
        $router->add('PUT', '/admin/api/key-values/settings', function (Request $request, array $params, ?AuthContext $context) use ($kv, $auth): Response {
            unset($params);
            return $auth($context) ?? $kv->saveSettings($request, $context);
        });
        $router->add('GET', '/admin/api/key-values', function (Request $request, array $params, ?AuthContext $context) use ($kv, $auth): Response {
            unset($params);
            return $auth($context) ?? $kv->index($request, $context);
        });
        $router->add('POST', '/admin/api/key-values', function (Request $request, array $params, ?AuthContext $context) use ($kv, $auth): Response {
            unset($params);
            return $auth($context) ?? $kv->create($request, $context);
        });
        $router->add('GET', '/admin/api/key-values/{id}', function (Request $request, array $params, ?AuthContext $context) use ($kv, $auth): Response {
            return $auth($context) ?? $kv->show($request, $context, (int) $params['id']);
        });
        $router->add('PATCH', '/admin/api/key-values/{id}', function (Request $request, array $params, ?AuthContext $context) use ($kv, $auth): Response {
            return $auth($context) ?? $kv->update($request, $context, (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/key-values/{id}', function (Request $request, array $params, ?AuthContext $context) use ($kv, $auth): Response {
            return $auth($context) ?? $kv->delete($request, $context, (int) $params['id']);
        });
    }

    public static function registerPublic(
        Router $router,
        KeyValuesController $kv,
        string $kvPath,
    ): void {
        $paths = array_unique(array_filter([
            $kvPath,
            self::v1Mirror($kvPath),
            '/api/kv',
            '/api/v1/kv',
        ]));
        foreach ($paths as $path) {
            $router->add('GET', $path, function (Request $request, array $params, ?AuthContext $context) use ($kv): Response {
                unset($params);

                return $kv->publicIndex($request, $context);
            }, true, 'api');
        }
    }

    private static function v1Mirror(string $path): ?string
    {
        if (str_starts_with($path, '/api/v1/')) {
            return null;
        }
        if (str_starts_with($path, '/api/')) {
            return '/api/v1/' . substr($path, 5);
        }

        return null;
    }
}
