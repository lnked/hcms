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
        $auth = static function (?AuthContext $context): ?Response {
            if ($context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return null;
        };

        $router->add('GET', '/admin/api/feature-flags/settings', function (Request $request, array $params, ?AuthContext $context) use ($flags, $auth): Response {
            unset($params);
            return $auth($context) ?? $flags->getSettings($request, $context);
        });
        $router->add('PUT', '/admin/api/feature-flags/settings', function (Request $request, array $params, ?AuthContext $context) use ($flags, $auth): Response {
            unset($params);
            return $auth($context) ?? $flags->saveSettings($request, $context);
        });
        $router->add('GET', '/admin/api/feature-flags', function (Request $request, array $params, ?AuthContext $context) use ($flags, $auth): Response {
            unset($params);
            return $auth($context) ?? $flags->index($request, $context);
        });
        $router->add('POST', '/admin/api/feature-flags', function (Request $request, array $params, ?AuthContext $context) use ($flags, $auth): Response {
            unset($params);
            return $auth($context) ?? $flags->create($request, $context);
        });
        $router->add('GET', '/admin/api/feature-flags/{id}', function (Request $request, array $params, ?AuthContext $context) use ($flags, $auth): Response {
            return $auth($context) ?? $flags->show($request, $context, (int) $params['id']);
        });
        $router->add('PATCH', '/admin/api/feature-flags/{id}', function (Request $request, array $params, ?AuthContext $context) use ($flags, $auth): Response {
            return $auth($context) ?? $flags->update($request, $context, (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/feature-flags/{id}', function (Request $request, array $params, ?AuthContext $context) use ($flags, $auth): Response {
            return $auth($context) ?? $flags->delete($request, $context, (int) $params['id']);
        });

        $router->add('GET', '/admin/api/locales', function (Request $request, array $params, ?AuthContext $context) use ($translates, $auth): Response {
            unset($params);
            return $auth($context) ?? $translates->listLocales($request, $context);
        });
        $router->add('POST', '/admin/api/locales', function (Request $request, array $params, ?AuthContext $context) use ($translates, $auth): Response {
            unset($params);
            return $auth($context) ?? $translates->createLocale($request, $context);
        });
        $router->add('PATCH', '/admin/api/locales/{code}', function (Request $request, array $params, ?AuthContext $context) use ($translates, $auth): Response {
            return $auth($context) ?? $translates->updateLocale($request, $context, (string) $params['code']);
        });
        $router->add('PUT', '/admin/api/locales/{code}/default', function (Request $request, array $params, ?AuthContext $context) use ($translates, $auth): Response {
            return $auth($context) ?? $translates->setDefaultLocale($request, $context, (string) $params['code']);
        });
        $router->add('DELETE', '/admin/api/locales/{code}', function (Request $request, array $params, ?AuthContext $context) use ($translates, $auth): Response {
            return $auth($context) ?? $translates->deleteLocale($request, $context, (string) $params['code']);
        });

        $router->add('GET', '/admin/api/translations/settings', function (Request $request, array $params, ?AuthContext $context) use ($translates, $auth): Response {
            unset($params);
            return $auth($context) ?? $translates->getSettings($request, $context);
        });
        $router->add('PUT', '/admin/api/translations/settings', function (Request $request, array $params, ?AuthContext $context) use ($translates, $auth): Response {
            unset($params);
            return $auth($context) ?? $translates->saveSettings($request, $context);
        });
        $router->add('GET', '/admin/api/translations/export', function (Request $request, array $params, ?AuthContext $context) use ($translates, $auth): Response {
            unset($params);
            return $auth($context) ?? $translates->export($request, $context);
        });
        $router->add('POST', '/admin/api/translations/import', function (Request $request, array $params, ?AuthContext $context) use ($translates, $auth): Response {
            unset($params);
            return $auth($context) ?? $translates->import($request, $context);
        });
        $router->add('GET', '/admin/api/translations', function (Request $request, array $params, ?AuthContext $context) use ($translates, $auth): Response {
            unset($params);
            return $auth($context) ?? $translates->listTranslations($request, $context);
        });
        $router->add('POST', '/admin/api/translations', function (Request $request, array $params, ?AuthContext $context) use ($translates, $auth): Response {
            unset($params);
            return $auth($context) ?? $translates->createTranslation($request, $context);
        });
        $router->add('GET', '/admin/api/translations/{id}', function (Request $request, array $params, ?AuthContext $context) use ($translates, $auth): Response {
            return $auth($context) ?? $translates->showTranslation($request, $context, (int) $params['id']);
        });
        $router->add('PATCH', '/admin/api/translations/{id}', function (Request $request, array $params, ?AuthContext $context) use ($translates, $auth): Response {
            return $auth($context) ?? $translates->updateTranslation($request, $context, (int) $params['id']);
        });
        $router->add('DELETE', '/admin/api/translations/{id}', function (Request $request, array $params, ?AuthContext $context) use ($translates, $auth): Response {
            return $auth($context) ?? $translates->deleteTranslation($request, $context, (int) $params['id']);
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
