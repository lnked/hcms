<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testTooManyRequestsHasRetryAfter(): void
    {
        $response = Response::tooManyRequests(42);

        $this->assertSame(429, $response->status);
        $this->assertSame('42', $response->headers['Retry-After']);
        $this->assertStringContainsString('TOO_MANY_REQUESTS', $response->body);
    }
}
