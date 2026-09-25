<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Api\QueryFilterBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class QueryFilterBuilderTest extends TestCase
{
    private QueryFilterBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new QueryFilterBuilder();
    }

    /** @return array<string, array<string, mixed>> */
    private function fieldMap(): array
    {
        return [
            'status' => [
                'type' => 'enum',
                'spec' => ['filterable' => true, 'sortable' => true, 'searchable' => false, 'config' => []],
            ],
            'title' => [
                'type' => 'string',
                'spec' => ['filterable' => true, 'sortable' => true, 'searchable' => true, 'config' => []],
            ],
            'views' => [
                'type' => 'integer',
                'spec' => ['filterable' => true, 'sortable' => true, 'searchable' => false, 'config' => []],
            ],
            'secret' => [
                'type' => 'string',
                'spec' => ['filterable' => false, 'sortable' => false, 'searchable' => false, 'config' => []],
            ],
        ];
    }

    public function testApplyFiltersEq(): void
    {
        $where = [];
        $params = [];
        $this->builder->applyFilters(
            ['filter[status]' => 'published'],
            $this->fieldMap(),
            $where,
            $params,
        );

        self::assertSame(['`status` = :f_0'], $where);
        self::assertSame(['f_0' => 'published'], $params);
    }

    public function testApplyFiltersIn(): void
    {
        $where = [];
        $params = [];
        $this->builder->applyFilters(
            ['filter[status][in]' => 'draft, review'],
            $this->fieldMap(),
            $where,
            $params,
        );

        self::assertSame(['`status` IN (:in_0_0, :in_1_1)'], $where);
        self::assertSame(['in_0_0' => 'draft', 'in_1_1' => 'review'], $params);
    }

    public function testApplyFiltersContains(): void
    {
        $where = [];
        $params = [];
        $this->builder->applyFilters(
            ['filter[title][contains]' => 'hello'],
            $this->fieldMap(),
            $where,
            $params,
        );

        self::assertSame(['`title` LIKE :f_0'], $where);
        self::assertSame(['f_0' => '%hello%'], $params);
    }

    public function testNotFilterableThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field not filterable: secret');

        $where = [];
        $params = [];
        $this->builder->applyFilters(
            ['filter[secret]' => 'x'],
            $this->fieldMap(),
            $where,
            $params,
        );
    }

    public function testApplySearchScoreSql(): void
    {
        $where = [];
        $params = [];
        $score = $this->builder->applySearch(
            ['search' => 'hello world'],
            $this->fieldMap(),
            $where,
            $params,
        );

        self::assertNotNull($score);
        self::assertStringContainsString('CASE WHEN', $score);
        self::assertStringContainsString(' + ', $score);
        self::assertCount(1, $where);
        self::assertStringStartsWith('(', $where[0]);
        self::assertArrayHasKey('s_0', $params);
        self::assertSame('%hello%', $params['s_0']);
        self::assertSame('%world%', $params['s_1']);
    }

    public function testOrderSqlAscAndDesc(): void
    {
        self::assertSame(
            ' ORDER BY `title` ASC',
            $this->builder->orderSql(['sort' => 'title'], $this->fieldMap()),
        );
        self::assertSame(
            ' ORDER BY `views` DESC',
            $this->builder->orderSql(['sort' => '-views'], $this->fieldMap()),
        );
    }

    public function testOrderSqlWithSearchScore(): void
    {
        $sql = $this->builder->orderSql(
            ['sort' => 'id'],
            $this->fieldMap(),
            '(CASE WHEN 1 THEN 1 ELSE 0 END)',
        );
        self::assertSame(
            ' ORDER BY (CASE WHEN 1 THEN 1 ELSE 0 END) DESC, `id` ASC',
            $sql,
        );
    }

    public function testHasFilterParams(): void
    {
        self::assertTrue($this->builder->hasFilterParams(['filter[status]' => 'a', 'page' => '1']));
        self::assertFalse($this->builder->hasFilterParams(['page' => '1', 'search' => 'x']));
    }

    public function testApplyFeatureFiltersUsesPassedDefaultLocale(): void
    {
        $where = [];
        $params = [];
        $this->builder->applyFeatureFilters(
            [],
            ['localization' => ['enabled' => true], 'workflow' => ['enabled' => true]],
            true,
            $where,
            $params,
            'ru',
        );

        self::assertContains('`status` = :wf_status', $where);
        self::assertContains('`locale` = :feat_locale', $where);
        self::assertSame('published', $params['wf_status']);
        self::assertSame('ru', $params['feat_locale']);
    }
}
