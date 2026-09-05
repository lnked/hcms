<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Content\Slug;
use Cms\Database\MigrationService;
use PHPUnit\Framework\TestCase;

final class PublicApiRoutingTest extends TestCase
{
    public function testTableNameMatchesSlugRules(): void
    {
        $this->assertTrue(Slug::isValid('articles'));
        $this->assertSame('res_articles', MigrationService::tableName('articles'));
    }
}
