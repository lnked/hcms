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

    /**
     * Deep links bypass the web server rule on /admin/index.html, so the shell answers here.
     * Booted from a scratch root: resolving the shell publishes the admin build into every
     * candidate docroot, which would litter the repository.
     */
    public function testAdminDeepLinkIsNotCached(): void
    {
        $root = sys_get_temp_dir() . '/hcms-kernel-' . bin2hex(random_bytes(4));
        mkdir($root . '/public/admin/assets', 0775, true);
        file_put_contents($root . '/public/admin/index.html', '<script src="/admin/assets/app.js"></script>');
        file_put_contents($root . '/public/admin/assets/app.js', 'ok');

        try {
            $kernel = Kernel::boot($root);
            $response = $kernel->handle(new Request('GET', '/admin/resources/5/data', [], [], null, '', '127.0.0.1', 'test'));

            $this->assertSame(200, $response->status);
            $this->assertSame('no-cache', $response->headers['Cache-Control'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeDir($full) : @unlink($full);
        }
        @rmdir($path);
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

    public function testChangePasswordRequiresToken(): void
    {
        $kernel = Kernel::boot(dirname(__DIR__));
        $response = $kernel->handle(new Request(
            'POST',
            '/admin/api/auth/password',
            [],
            ['content-type' => 'application/json'],
            ['currentPassword' => 'old-secret1', 'newPassword' => 'new-secret1'],
            '{}',
            '127.0.0.1',
            'test',
        ));

        $this->assertContains($response->status, [401, 503]);
        $this->assertStringContainsString('error', $response->body);
    }
}
