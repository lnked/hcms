<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Auth\AuthContext;
use Cms\Http\Controllers\AuthController;

/**
 * Route table of admin auth endpoints (/admin/api/auth/*).
 */
final class AuthRoutes
{
    public static function register(Router $router, ?AuthController $auth): void
    {
        $router->add('POST', '/admin/api/auth/login', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params, $context);
            if ($auth === null) {
                return Response::error('SERVICE_UNAVAILABLE', 'CMS is not installed', 503);
            }

            return $auth->login($request);
        }, true);

        $router->add('POST', '/admin/api/auth/logout', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params);
            if ($auth === null || $context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $auth->logout($request, $context);
        });

        $router->add('GET', '/admin/api/auth/me', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params);
            if ($auth === null || $context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $auth->me($request, $context);
        });

        $router->add('GET', '/admin/api/auth/captcha', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params, $context);
            if ($auth === null) {
                return Response::error('SERVICE_UNAVAILABLE', 'CMS is not installed', 503);
            }

            return $auth->captchaConfig($request);
        }, true);

        $router->add('POST', '/admin/api/auth/totp/setup', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params);
            if ($auth === null || $context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $auth->totpSetup($request, $context);
        });

        $router->add('POST', '/admin/api/auth/totp/enable', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params);
            if ($auth === null || $context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $auth->totpEnable($request, $context);
        });

        $router->add('POST', '/admin/api/auth/totp/disable', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params);
            if ($auth === null || $context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $auth->totpDisable($request, $context);
        });

        $router->add('POST', '/admin/api/auth/password', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params);
            if ($auth === null || $context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $auth->changePassword($request, $context);
        });

        $router->add('GET', '/admin/api/auth/providers', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params, $context);
            if ($auth === null) {
                return Response::error('SERVICE_UNAVAILABLE', 'CMS is not installed', 503);
            }

            return $auth->providers($request);
        }, true);

        $router->add('GET', '/admin/api/auth/google/start', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params, $context);
            if ($auth === null) {
                return Response::error('SERVICE_UNAVAILABLE', 'CMS is not installed', 503);
            }

            return $auth->googleStart($request);
        }, true);

        $router->add('GET', '/admin/api/auth/google/callback', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params, $context);
            if ($auth === null) {
                return Response::error('SERVICE_UNAVAILABLE', 'CMS is not installed', 503);
            }

            return $auth->googleCallback($request);
        }, true);

        $router->add('POST', '/admin/api/auth/telegram', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params, $context);
            if ($auth === null) {
                return Response::error('SERVICE_UNAVAILABLE', 'CMS is not installed', 503);
            }

            return $auth->telegramLogin($request);
        }, true);

        $router->add('POST', '/admin/api/auth/totp/complete', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params, $context);
            if ($auth === null) {
                return Response::error('SERVICE_UNAVAILABLE', 'CMS is not installed', 503);
            }

            return $auth->totpComplete($request);
        }, true);

        $router->add('GET', '/admin/api/auth/identities', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params);
            if ($auth === null || $context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $auth->listIdentities($request, $context);
        });

        $router->add('POST', '/admin/api/auth/identities/google/start', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params);
            if ($auth === null || $context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $auth->googleLinkStart($request, $context);
        });

        $router->add('POST', '/admin/api/auth/identities/telegram', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            unset($params);
            if ($auth === null || $context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $auth->telegramLink($request, $context);
        });

        $router->add('DELETE', '/admin/api/auth/identities/{provider}', function (Request $request, array $params, ?AuthContext $context) use ($auth): Response {
            if ($auth === null || $context === null) {
                return Response::error('UNAUTHORIZED', 'Unauthorized', 401);
            }

            return $auth->unlinkIdentity($request, $context, (string) $params['provider']);
        });
    }
}
