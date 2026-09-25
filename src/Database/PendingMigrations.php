<?php

declare(strict_types=1);

namespace Cms\Database;

use Cms\Core\Paths;
use Cms\Core\Settings;
use Cms\Media\MediaRefService;
use Throwable;

final class PendingMigrations
{
    public static function apply(Connection $db, Paths $paths, Settings $settings): void
    {
        $appliedRaw = $settings->get('db.migrations');
        /** @var list<string> $applied */
        $applied = \is_array($appliedRaw) ? array_values(array_map('strval', $appliedRaw)) : [];
        $dir = $paths->migrations();
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        $changed = false;
        foreach ($files as $file) {
            $name = basename($file);
            if (\in_array($name, $applied, true)) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            foreach (explode(';', $sql) as $raw) {
                $statement = self::stripLeadingSqlComments(trim($raw));
                if ($statement === '') {
                    continue;
                }
                try {
                    $db->execRaw($statement);
                } catch (Throwable $e) {
                    // Mid-file failure after ADD COLUMN leaves installs stuck on retry
                    // (Duplicate column). Skip only that recoverable case.
                    if (!self::isIgnorableMigrationError($statement, $e)) {
                        throw $e;
                    }
                }
            }
            $applied[] = $name;
            $changed = true;
        }
        if ($changed) {
            $settings->set('db.migrations', $applied);
        }

        // Outside .sql on purpose: update runs PendingMigrations from a stale
        // in-request class after swap, so ADD COLUMN in 013.sql 1060's when the
        // column already exists from a previous failed attempt.
        // Also repairs installs where a leading `--` comment made the old
        // `str_starts_with($statement, '--')` skip the whole first ALTER and still mark the file applied.
        self::ensureAclEnabledColumn($db);
        self::ensureMediaUploadedByColumn($db);
        self::ensureWebhookPresetColumns($db);
        self::ensureFieldAclColumns($db);
        self::repairMediaColumns($db, $settings);
        self::backfillMediaRefs($db, $settings);
    }

    /**
     * Drop full-line `--` comments so a header comment does not make the whole
     * statement look like a comment (and get skipped).
     */
    public static function stripLeadingSqlComments(string $statement): string
    {
        $lines = preg_split('/\R/', $statement) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }

    public static function ensureAclEnabledColumn(Connection $db): void
    {
        $row = $db->selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cms_users'
               AND COLUMN_NAME = 'acl_enabled'",
        );
        if ($row !== null && (int) $row['c'] > 0) {
            return;
        }

        try {
            $db->execRaw(
                'ALTER TABLE cms_users ADD COLUMN acl_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER role',
            );
        } catch (Throwable $e) {
            if (!self::isIgnorableMigrationError(
                'ALTER TABLE cms_users ADD COLUMN acl_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER role',
                $e,
            )) {
                throw $e;
            }
        }
    }

    public static function ensureMediaUploadedByColumn(Connection $db): void
    {
        $row = $db->selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cms_media'
               AND COLUMN_NAME = 'uploaded_by'",
        );
        if ($row !== null && (int) $row['c'] > 0) {
            return;
        }

        try {
            $db->execRaw(
                'ALTER TABLE cms_media ADD COLUMN uploaded_by BIGINT UNSIGNED NULL AFTER created_at',
            );
        } catch (Throwable $e) {
            if (!self::isIgnorableMigrationError(
                'ALTER TABLE cms_media ADD COLUMN uploaded_by BIGINT UNSIGNED NULL AFTER created_at',
                $e,
            )) {
                throw $e;
            }
        }

        try {
            $db->execRaw('ALTER TABLE cms_media ADD KEY idx_cms_media_uploaded_by (uploaded_by)');
        } catch (Throwable) {
            // Index may already exist from 014.sql
        }
    }

    public static function ensureWebhookPresetColumns(Connection $db): void
    {
        self::ensureColumn(
            $db,
            'cms_webhooks',
            'preset',
            'ALTER TABLE cms_webhooks ADD COLUMN preset VARCHAR(32) NULL AFTER status',
        );
        self::ensureColumn(
            $db,
            'cms_webhooks',
            'payload_mode',
            "ALTER TABLE cms_webhooks ADD COLUMN payload_mode VARCHAR(32) NOT NULL DEFAULT 'hcms' AFTER preset",
        );
        self::ensureColumn(
            $db,
            'cms_webhooks',
            'headers_json',
            'ALTER TABLE cms_webhooks ADD COLUMN headers_json JSON NULL AFTER payload_mode',
        );
    }

    public static function ensureFieldAclColumns(Connection $db): void
    {
        self::ensureColumn(
            $db,
            'cms_user_resource_grants',
            'field_acl_json',
            'ALTER TABLE cms_user_resource_grants ADD COLUMN field_acl_json JSON NULL AFTER tabs_json',
        );
        self::ensureColumn(
            $db,
            'cms_user_resource_grants',
            'own_entries_only',
            'ALTER TABLE cms_user_resource_grants ADD COLUMN own_entries_only TINYINT(1) NOT NULL DEFAULT 0 AFTER field_acl_json',
        );
    }

    private static function ensureColumn(Connection $db, string $table, string $column, string $alterSql): void
    {
        $row = $db->selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column',
            ['table' => $table, 'column' => $column],
        );
        if ($row !== null && (int) $row['c'] > 0) {
            return;
        }

        try {
            $db->execRaw($alterSql);
        } catch (Throwable $e) {
            if (!self::isIgnorableMigrationError($alterSql, $e)) {
                throw $e;
            }
        }
    }

    private static function isIgnorableMigrationError(string $statement, Throwable $e): bool
    {
        $message = $e->getMessage();
        $duplicateColumn = str_contains($message, 'Duplicate column name')
            || str_contains($message, 'duplicate column')
            || str_contains($message, '42S21')
            || str_contains($message, '1060');
        if (!$duplicateColumn) {
            return false;
        }

        return preg_match('/^\s*ALTER\s+TABLE\b.*\bADD\s+COLUMN\b/is', $statement) === 1;
    }

    /**
     * Resource tables live outside the .sql files, so their one-off repairs run here
     * and record their own marker.
     */
    private static function repairMediaColumns(Connection $db, Settings $settings): void
    {
        $marker = 'db.media_columns_json';
        if ($settings->get($marker) !== null) {
            return;
        }

        $settings->set($marker, (new MediaColumnRepair($db))->run());
    }

    private static function backfillMediaRefs(Connection $db, Settings $settings): void
    {
        $marker = 'db.media_refs_backfill';
        if ($settings->get($marker) !== null) {
            return;
        }

        $table = $db->selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_media_refs'",
        );
        if ($table === null || (int) $table['c'] === 0) {
            return;
        }

        $count = (new MediaRefService($db))->backfillAll();
        $settings->set($marker, ['at' => date('c'), 'refs' => $count]);
    }
}
