<?php

declare(strict_types=1);

namespace Cms\Tests;

use PHPUnit\Framework\TestCase;

final class ApiLogPathSanitizeTest extends TestCase
{
    public function testStripsQueryString(): void
    {
        $path = '/api/articles?token=secret&page=1';
        $safe = strtok($path, '?') ?: $path;
        $this->assertSame('/api/articles', $safe);
    }
}
