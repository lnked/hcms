<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Auth\AuthContext;
use Cms\Http\Controllers\FeatureFlagsController;
use Cms\Http\Controllers\TranslatesController;

/**
 * Admin + public routes for Feature flags and Translates.
 */
final class FeatureTranslatesRoutes
{
    public static function registerAdmin(
        Router $router,
        FeatureFlagsController $flags,
        TranslatesController $translates,
    ): void {
        $router->add('GET', '/admin/api/feature-flags/settings', function (Request $request, array $params, ?AuthContext $context) use ($flags): Response {
            unset($params);

            return $flags->getSettings($request, RequireAuth::context($context));
        });
        $router->add('PUT', '/admin/api/feature-flags/settings', function (Request $request, array $params, ?AuthContext $context) use ($flags): Response {
            unset($params);

            return $flags->saveSettings($request, RequireAuth::context($context));
        });
        $router->add('GET', '/admin/api/feature-flags', function (Request $request, array $params, ?AuthContext $context) use ($flags): Response {
            unset($params);

            return $flags->index($request, RequireAuth::context($context));
        });
        $router->add('POST', '/admin/api/feature-flags', function (Request $request, array $params, ?AuthContext $context) use ($flags): Response {
            unset($params);

            return $flags->create($request, RequireAuth::context($context));
        });
        $router->add('GET', '/admin/api/feature-flags/{id}', function (Request $request, array $params, ?AuthContext $context) use ($flags): Response {
            return $flags->show($request, RequireAuth::context($context), (int) $params['id']);
        });
        $router->add('PATCH', '/admin/api/feature-flags/{id}', function (Request $request, array $params, ?AuthContext $context) use ($flags): Response {
            return $flags->update($request, RequireAuth::context($context), (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/feature-flags/{id}', function (Request $request, array $params, ?AuthContext $context) use ($flags): Response {
            return $flags->delete($request, RequireAuth::context($context), (int) $params['id']);
        });

        $router->add('GET', '/admin/api/locales', function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
            unset($params);

            return $translates->listLocales($request, RequireAuth::context($context));
        });
        $router->add('POST', '/admin/api/locales', function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
            unset($params);

            return $translates->createLocale($request, RequireAuth::context($context));
        });
        $router->add('PATCH', '/admin/api/locales/{code}', function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
            return $translates->updateLocale($request, RequireAuth::context($context), (string) $params['code']);
        });
        $router->add('PUT', '/admin/api/locales/{code}/default', function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
            return $translates->setDefaultLocale($request, RequireAuth::context($context), (string) $params['code']);
        });
        $router->add('DELETE', '/admin/api/locales/{code}', function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
            return $translates->deleteLocale($request, RequireAuth::context($context), (string) $params['code']);
        });

        $router->add('GET', '/admin/api/translations/settings', function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
            unset($params);

            return $translates->getSettings($request, RequireAuth::context($context));
        });
        $router->add('PUT', '/admin/api/translations/settings', function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
            unset($params);

            return $translates->saveSettings($request, RequireAuth::context($context));
        });
        $router->add('GET', '/admin/api/translations/export', function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
            unset($params);

            return $translates->export($request, RequireAuth::context($context));
        });
        $router->add('POST', '/admin/api/translations/import', function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
            unset($params);

            return $translates->import($request, RequireAuth::context($context));
        });
        $router->add('GET', '/admin/api/translations', function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
            unset($params);

            return $translates->listTranslations($request, RequireAuth::context($context));
        });
        $router->add('POST', '/admin/api/translations', function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
            unset($params);

            return $translates->createTranslation($request, RequireAuth::context($context));
        });
        $router->add('GET', '/admin/api/translations/{id}', function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
            return $translates->showTranslation($request, RequireAuth::context($context), (int) $params['id']);
        });
        $router->add('PATCH', '/admin/api/translations/{id}', function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
            return $translates->updateTranslation($request, RequireAuth::context($context), (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/translations/{id}', function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
            return $translates->deleteTranslation($request, RequireAuth::context($context), (int) $params['id']);
        });
    }

    public static function registerPublic(
        Router $router,
        FeatureFlagsController $flags,
        TranslatesController $translates,
        string $featuresPath,
        string $translatesPath,
    ): void {
        $paths = array_unique(array_filter([
            $featuresPath,
            self::v1Mirror($featuresPath),
            '/api/features',
            '/api/v1/features',
        ]));
        foreach ($paths as $path) {
            $router->add('GET', $path, function (Request $request, array $params, ?AuthContext $context) use ($flags): Response {
                unset($params);

                return $flags->publicIndex($request, $context);
            }, true, 'api');
        }

        $tPaths = array_unique(array_filter([
            $translatesPath,
            self::v1Mirror($translatesPath),
            '/api/translates',
            '/api/v1/translates',
        ]));
        foreach ($tPaths as $path) {
            $router->add('GET', $path, function (Request $request, array $params, ?AuthContext $context) use ($translates): Response {
                unset($params);

                return $translates->publicIndex($request, $context);
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
