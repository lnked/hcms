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
        $router->add('GET', '/admin/api/key-values/settings', function (Request $request, array $params, ?AuthContext $context) use ($kv): Response {
            unset($params);

            return $kv->getSettings($request, RequireAuth::context($context));
        });
        $router->add('PUT', '/admin/api/key-values/settings', function (Request $request, array $params, ?AuthContext $context) use ($kv): Response {
            unset($params);

            return $kv->saveSettings($request, RequireAuth::context($context));
        });
        $router->add('GET', '/admin/api/key-values', function (Request $request, array $params, ?AuthContext $context) use ($kv): Response {
            unset($params);

            return $kv->index($request, RequireAuth::context($context));
        });
        $router->add('POST', '/admin/api/key-values', function (Request $request, array $params, ?AuthContext $context) use ($kv): Response {
            unset($params);

            return $kv->create($request, RequireAuth::context($context));
        });
        $router->add('GET', '/admin/api/key-values/{id}', function (Request $request, array $params, ?AuthContext $context) use ($kv): Response {
            return $kv->show($request, RequireAuth::context($context), (int) $params['id']);
        });
        $router->add('PATCH', '/admin/api/key-values/{id}', function (Request $request, array $params, ?AuthContext $context) use ($kv): Response {
            return $kv->update($request, RequireAuth::context($context), (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/key-values/{id}', function (Request $request, array $params, ?AuthContext $context) use ($kv): Response {
            return $kv->delete($request, RequireAuth::context($context), (int) $params['id']);
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
