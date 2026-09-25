<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Preview\PreviewTokenService;
use PHPUnit\Framework\TestCase;

final class PreviewTokenServiceTest extends TestCase
{
    public function testIssueAndParseRoundTrip(): void
    {
        $svc = new PreviewTokenService('test-secret', 600);
        $issued = $svc->issue(3, 42, 'articles');
        $claims = $svc->parse($issued['token']);

        $this->assertSame(3, $claims['resourceId']);
        $this->assertSame(42, $claims['entryId']);
        $this->assertSame('articles', $claims['slug']);
        $this->assertSame($issued['expiresAt'], $claims['exp']);
    }

    public function testRejectsTamperedToken(): void
    {
        $svc = new PreviewTokenService('test-secret');
        $issued = $svc->issue(1, 1, 'posts');
        $this->expectException(\InvalidArgumentException::class);
        $svc->parse($issued['token'] . 'x');
    }

    public function testRejectsWrongSecret(): void
    {
        $a = new PreviewTokenService('secret-a');
        $b = new PreviewTokenService('secret-b');
        $issued = $a->issue(1, 2, 'x');
        $this->expectException(\InvalidArgumentException::class);
        $b->parse($issued['token']);
    }

    public function testBuildPreviewUrl(): void
    {
        $url = PreviewTokenService::buildPreviewUrl(
            'https://app.test/p?t={token}&s={slug}&i={id}',
            'articles',
            7,
            ['token' => 'tok', 'expiresAt' => 123],
        );
        $this->assertSame('https://app.test/p?t=tok&s=articles&i=7', $url);
    }
}
