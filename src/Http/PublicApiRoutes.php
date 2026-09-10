<?php

declare(strict_types=1);

namespace Cms\Http;

use Cms\Auth\AuthContext;
use Cms\Http\Controllers\PublicApiController;
use Cms\Http\Controllers\PublicInboundController;

/**
 * Route table of the public API (/api/*).
 *
 * Entry ids and custom API slugs share the same path shape, so the segment is
 * dispatched by its content: digits address an entry, anything else an API slug.
 */
final class PublicApiRoutes
{
    /** @var list<string> */
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** The longer prefix registers first: /api/{slug}/{apiSlug} would otherwise swallow /api/v1/{slug}. */
    private const PREFIXES = ['/api/v1', '/api'];

    public static function register(
        Router $router,
        PublicApiController $api,
        ?PublicInboundController $inbound = null,
    ): void {
        if ($inbound !== null) {
            foreach (self::PREFIXES as $prefix) {
                $router->add('POST', $prefix . '/inbound/{slug}', function (Request $request, array $params, ?AuthContext $context) use ($inbound): Response {
                    return $inbound->handle($request, (string) $params['slug'], $context);
                }, true, 'api');
            }
        }

        foreach (self::PREFIXES as $prefix) {
            foreach (self::METHODS as $method) {
                $router->add($method, $prefix . '/{slug}', function (Request $request, array $params, ?AuthContext $context) use ($api): Response {
                    return $api->handle($request, (string) $params['slug'], null, $context);
                }, true, 'api');

                $router->add($method, $prefix . '/{slug}/{apiSlug}/{id}', function (Request $request, array $params, ?AuthContext $context) use ($api): Response {
                    $apiSlug = (string) $params['apiSlug'];
                    if (ctype_digit($apiSlug)) {
                        return Response::error('NOT_FOUND', 'Not found', 404);
                    }

                    return $api->handleCustom(
                        $request,
                        (string) $params['slug'],
                        $apiSlug,
                        (string) $params['id'],
                        $context,
                    );
                }, true, 'api');

                $router->add($method, $prefix . '/{slug}/{apiSlug}', function (Request $request, array $params, ?AuthContext $context) use ($api): Response {
                    $apiSlug = (string) $params['apiSlug'];
                    if (ctype_digit($apiSlug)) {
                        return $api->handle($request, (string) $params['slug'], $apiSlug, $context);
                    }

                    return $api->handleCustom($request, (string) $params['slug'], $apiSlug, null, $context);
                }, true, 'api');
            }
        }
    }
}
