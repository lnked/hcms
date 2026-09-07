<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Database\MigrationService;
use Cms\Fields\FieldTypeRegistry;
use Cms\Fields\SqlTypeMapper;
use PHPUnit\Framework\TestCase;

final class SqlTypeMapperTest extends TestCase
{
    public function testMapsCoreTypes(): void
    {
        $mapper = new SqlTypeMapper(new FieldTypeRegistry());
        $string = $mapper->columnFor(['name' => 'title', 'type' => 'string', 'config' => ['maxLength' => 120]]);
        $this->assertSame('VARCHAR(120)', $string->sqlType);

        $bool = $mapper->columnFor(['name' => 'active', 'type' => 'boolean', 'nullable' => false]);
        $this->assertSame('TINYINT(1)', $bool->sqlType);
        $this->assertFalse($bool->nullable);

        $rich = $mapper->columnFor(['name' => 'body', 'type' => 'richtext']);
        $this->assertSame('MEDIUMTEXT', $rich->sqlType);
    }

    public function testTableNameFromSlug(): void
    {
        $this->assertSame('res_articles', MigrationService::tableName('articles'));
    }
}
