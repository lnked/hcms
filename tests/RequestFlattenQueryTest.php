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
}
