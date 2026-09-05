<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Database\ColumnDefinition;
use Cms\Database\SchemaDiff;
use PHPUnit\Framework\TestCase;

final class SchemaDiffTest extends TestCase
{
    public function testDetectsAddAndDrop(): void
    {
        $diff = new SchemaDiff();
        $current = [new ColumnDefinition('name', 'VARCHAR(255)', true)];
        $desired = [
            new ColumnDefinition('name', 'VARCHAR(255)', true),
            new ColumnDefinition('age', 'INT', true),
        ];

        $plan = $diff->plan($current, $desired);
        $ops = array_column($plan, 'op');
        $this->assertContains('add_field', $ops);

        $planDrop = $diff->plan($desired, $current);
        $this->assertContains('drop_field', array_column($planDrop, 'op'));
    }
}
