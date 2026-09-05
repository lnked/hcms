<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Http\Kernel;
use Cms\Http\Request;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    public function testDocsAndHealthArePublic(): void
    {
        $kernel = Kernel::boot(dirname(__DIR__));

        $docs = $kernel->handle(new Request('GET', '/api/docs', [], [], null, '', '127.0.0.1', 'test'));
        $this->assertSame(200, $docs->status);
        $this->assertStringContainsString('swagger-ui', $docs->body);

        $spec = $kernel->handle(new Request('GET', '/api/openapi.json', [], [], null, '', '127.0.0.1', 'test'));
        $this->assertSame(200, $spec->status);
        $this->assertStringContainsString('openapi', $spec->body);

        $health = $kernel->handle(new Request('GET', '/admin/api/health', [], [], null, '', '127.0.0.1', 'test'));
        $this->assertSame(200, $health->status);
    }

    public function testProtectedAdminApiRequiresToken(): void
    {
        $kernel = Kernel::boot(dirname(__DIR__));
        $response = $kernel->handle(new Request('GET', '/admin/api/auth/me', [], [], null, '', '127.0.0.1', 'test'));

        $this->assertContains($response->status, [401, 503]);
        $this->assertStringContainsString('error', $response->body);
    }

    public function testLoginWithoutInstallIsUnavailable(): void
    {
        $kernel = Kernel::boot(dirname(__DIR__));
        $response = $kernel->handle(new Request(
            'POST',
            '/admin/api/auth/login',
            [],
            ['content-type' => 'application/json'],
            ['email' => 'a@b.c', 'password' => 'secret'],
            '{}',
            '127.0.0.1',
            'test',
        ));

        $this->assertContains($response->status, [401, 422, 503]);
    }
}
