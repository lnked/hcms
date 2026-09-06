<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestFlattenQueryTest extends TestCase
{
    public function testFlattensNestedFilterArrays(): void
    {
        $flat = Request::flattenQuery([
            'page' => '1',
            'filter' => [
                'title' => 'hello',
                'status' => ['eq' => 'draft'],
            ],
        ]);

        $this->assertSame('1', $flat['page']);
        $this->assertSame('hello', $flat['filter[title]']);
        $this->assertSame('draft', $flat['filter[status][eq]']);
    }

    public function testNormalizePathStripsTrailingSlash(): void
    {
        $this->assertSame('/admin', Request::normalizePath('/admin/'));
        $this->assertSame('/admin/api/health', Request::normalizePath('/admin/api/health/'));
        $this->assertSame('/', Request::normalizePath('/'));
    }

    public function testTrailingSlashRedirectTarget(): void
    {
        $this->assertSame('/admin', Request::trailingSlashRedirectTarget('GET', '/admin/'));
        $this->assertSame(
            '/admin/api/health?x=1',
            Request::trailingSlashRedirectTarget('GET', '/admin/api/health/?x=1'),
        );
        $this->assertNull(Request::trailingSlashRedirectTarget('GET', '/admin'));
        $this->assertNull(Request::trailingSlashRedirectTarget('POST', '/admin/'));
        $this->assertNull(Request::trailingSlashRedirectTarget('GET', '/'));
    }
}
