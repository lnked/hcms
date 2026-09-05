<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Core\Config;
use Cms\OpenApi\OpenApiGenerator;
use PHPUnit\Framework\TestCase;

final class OpenApiGeneratorTest extends TestCase
{
    public function testEmptySpecWithoutRepositories(): void
    {
        $config = new Config(
            appEnv: 'test',
            debug: true,
            appUrl: 'http://localhost',
            appSecret: 'x',
            dbHost: '127.0.0.1',
            dbPort: 3306,
            dbName: '',
            dbUser: '',
            dbPassword: '',
            dbCharset: 'utf8mb4',
            githubRepo: 'lnked/hcms',
        );
        $spec = (new OpenApiGenerator($config))->generate();

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame('0.12.0', $spec['info']['version']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
        $this->assertCount(2, $spec['servers']);
    }
}
