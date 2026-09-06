<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Resources\EntryImportExportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EntryImportExportServiceTest extends TestCase
{
    private EntryImportExportService $service;

    protected function setUp(): void
    {
        $this->service = (new ReflectionClass(EntryImportExportService::class))
            ->newInstanceWithoutConstructor();
    }

    public function testCsvRoundTripPreservesValuesAndEscaping(): void
    {
        $columns = ['title', 'body', 'meta', 'active'];
        $rows = [
            [
                'title' => 'Hello, world',
                'body' => "Line 1\nLine 2",
                'meta' => ['tags' => ['a', 'b']],
                'active' => true,
            ],
            [
                'title' => 'Quote "here"',
                'body' => null,
                'meta' => null,
                'active' => false,
            ],
        ];

        $csv = $this->service->encodeCsv($rows, $columns);
        $parsed = $this->service->parseCsv($csv);

        $this->assertCount(2, $parsed);
        $this->assertSame('Hello, world', $parsed[0]['title']);
        $this->assertSame("Line 1\nLine 2", $parsed[0]['body']);
        $this->assertSame('{"tags":["a","b"]}', $parsed[0]['meta']);
        $this->assertSame('true', $parsed[0]['active']);
        $this->assertSame('Quote "here"', $parsed[1]['title']);
        $this->assertSame('', $parsed[1]['body']);
        $this->assertSame('false', $parsed[1]['active']);
    }

    public function testParseJsonAcceptsArrayAndDataWrapper(): void
    {
        $direct = $this->service->parseJson('[{"title":"A"},{"title":"B"}]');
        $this->assertSame([['title' => 'A'], ['title' => 'B']], $direct);

        $wrapped = $this->service->parseJson('{"data":[{"title":"C"}]}');
        $this->assertSame([['title' => 'C']], $wrapped);
    }

    public function testParseJsonRejectsInvalidShape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->parseJson('{"title":"solo"}');
    }
}
