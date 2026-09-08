<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Api\QueryEngine;
use Cms\Auth\TokenGrantRepository;
use Cms\Content\Slug;
use Cms\Database\Connection;
use Cms\Database\MigrationService;
use Cms\Fields\FieldRepository;
use Cms\Http\Controllers\PublicApiController;
use Cms\Http\PublicApiRoutes;
use Cms\Http\Request;
use Cms\Http\Router;
use Cms\Resources\ResourceApiRepository;
use Cms\Resources\ResourceRepository;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicApiRoutingTest extends TestCase
{
    public function testTableNameMatchesSlugRules(): void
    {
        $this->assertTrue(Slug::isValid('articles'));
        $this->assertSame('res_articles', MigrationService::tableName('articles'));
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function routeCases(): array
    {
        return [
            'collection' => ['GET', '/api/posts', '/api/{slug}'],
            'entry' => ['GET', '/api/posts/5', '/api/{slug}/{apiSlug}'],
            'custom collection' => ['GET', '/api/posts/leads', '/api/{slug}/{apiSlug}'],
            'custom entry' => ['GET', '/api/posts/leads/5', '/api/{slug}/{apiSlug}/{id}'],
            'versioned collection' => ['GET', '/api/v1/posts', '/api/v1/{slug}'],
            'versioned entry' => ['GET', '/api/v1/posts/5', '/api/v1/{slug}/{apiSlug}'],
            'versioned custom entry' => ['POST', '/api/v1/posts/leads', '/api/v1/{slug}/{apiSlug}'],
            'create on custom api' => ['POST', '/api/posts/leads', '/api/{slug}/{apiSlug}'],
            'patch on custom api' => ['PATCH', '/api/posts/leads/5', '/api/{slug}/{apiSlug}/{id}'],
            'delete on custom api' => ['DELETE', '/api/posts/leads/5', '/api/{slug}/{apiSlug}/{id}'],
            'delete on entry' => ['DELETE', '/api/posts/5', '/api/{slug}/{apiSlug}'],
        ];
    }

    #[DataProvider('routeCases')]
    public function testWriteMethodsResolveCustomApiPaths(string $method, string $path, string $pattern): void
    {
        $router = new Router();
        PublicApiRoutes::register($router, $this->controller());

        $match = $router->match(new Request($method, $path, [], [], null, '', '127.0.0.1', 'test'));

        $this->assertNotNull($match, $method . ' ' . $path . ' should match a route');
        $this->assertSame($pattern, $match['route']->pattern);
    }

    public function testPublicApiRoutesAreTokenAuthenticated(): void
    {
        $router = new Router();
        PublicApiRoutes::register($router, $this->controller());

        $match = $router->match(new Request('POST', '/api/posts/leads', [], [], null, '', '127.0.0.1', 'test'));

        $this->assertNotNull($match);
        $this->assertTrue($match['route']->public);
        $this->assertSame('api', $match['route']->auth);
    }

    private function controller(): PublicApiController
    {
        // Routing is resolved before any handler runs, so the connection is never queried.
        $db = new Connection(new PDO('sqlite::memory:'));
        $resources = new ResourceRepository($db);
        $apis = new ResourceApiRepository($db);

        return new PublicApiController(
            new QueryEngine($db, $resources, new FieldRepository($db), $apis),
            $resources,
            new TokenGrantRepository($db),
            $apis,
        );
    }
}
