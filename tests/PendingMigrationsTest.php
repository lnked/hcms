<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Database\PendingMigrations;
use PHPUnit\Framework\TestCase;

final class PendingMigrationsTest extends TestCase
{
    public function testStripLeadingSqlCommentsKeepsAlterAfterHeaderComment(): void
    {
        $raw = "-- Webhook revalidation presets + fine-grained ACL columns.\n\n"
            . "ALTER TABLE cms_webhooks\n"
            . '    ADD COLUMN preset VARCHAR(32) NULL AFTER status';

        $statement = PendingMigrations::stripLeadingSqlComments(trim($raw));

        self::assertStringStartsWith('ALTER TABLE cms_webhooks', $statement);
        self::assertStringContainsString('ADD COLUMN preset', $statement);
        self::assertStringNotContainsString('--', $statement);
    }

    public function testStripLeadingSqlCommentsReturnsEmptyForCommentOnly(): void
    {
        self::assertSame('', PendingMigrations::stripLeadingSqlComments("-- just a comment\n-- another"));
    }

    public function testMigration022FirstStatementIsNotSkipped(): void
    {
        $sql = (string) file_get_contents(\dirname(__DIR__) . '/database/migrations/022_webhooks_presets_field_acl.sql');
        $statements = [];
        foreach (explode(';', $sql) as $raw) {
            $statement = PendingMigrations::stripLeadingSqlComments(trim($raw));
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        self::assertCount(2, $statements);
        self::assertStringContainsString('cms_webhooks', $statements[0]);
        self::assertStringContainsString('cms_user_resource_grants', $statements[1]);
    }
}
