<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Http\Request;
use Cms\Http\Response;
use Cms\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesParameterizedRoute(): void
    {
        $router = new Router();
        $router->add('GET', '/admin/api/resources/{id}', function (Request $request, array $params): Response {
            unset($request);

            return Response::data($params);
        });

        $request = new Request('GET', '/admin/api/resources/42', [], [], null, '', '127.0.0.1', 'test');
        $match = $router->match($request);

        $this->assertNotNull($match);
        $this->assertSame('42', $match['params']['id']);
    }

    public function testMissReturnsNull(): void
    {
        $router = new Router();
        $router->add('GET', '/admin/api/health', fn (): Response => Response::data(['ok' => true]));
        $request = new Request('POST', '/admin/api/health', [], [], null, '', '127.0.0.1', 'test');

        $this->assertNull($router->match($request));
    }
}
