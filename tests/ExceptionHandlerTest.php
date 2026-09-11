<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Config;
use Cms\Core\Exception\NotFoundException;
use Cms\Core\Exception\ValidationFailedException;
use Cms\Core\Paths;
use Cms\Http\ExceptionHandler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionHandlerTest extends TestCase
{
    private ExceptionHandler $handler;

    protected function setUp(): void
    {
        $root = \dirname(__DIR__);
        $this->handler = new ExceptionHandler(
            new Config(
                appEnv: 'testing',
                debug: true,
                appUrl: 'http://localhost',
                appSecret: 'x',
                dbHost: '127.0.0.1',
                dbPort: 3306,
                dbName: 'hcms',
                dbUser: 'root',
                dbPassword: '',
                dbCharset: 'utf8mb4',
                githubRepo: 'lnked/hcms',
            ),
            new Paths($root),
        );
    }

    public function testMapsNotFound(): void
    {
        $response = $this->handler->handle(new NotFoundException('gone'));
        self::assertSame(404, $response->status);
        $body = json_decode($response->body, true);
        self::assertSame('NOT_FOUND', $body['error']['code']);
    }

    public function testMapsValidationFailed(): void
    {
        $response = $this->handler->handle(new ValidationFailedException('bad', ['title' => ['required']]));
        self::assertSame(422, $response->status);
        $body = json_decode($response->body, true);
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);
        self::assertSame(['title' => ['required']], $body['error']['fields']);
    }

    public function testMapsInvalidArgument(): void
    {
        $response = $this->handler->handle(new InvalidArgumentException('nope'));
        self::assertSame(422, $response->status);
    }

    public function testMapsLegacyRuntimeCode(): void
    {
        $response = $this->handler->handle(new RuntimeException('missing', 404));
        self::assertSame(404, $response->status);
    }
}
