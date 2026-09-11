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
                $statement = trim($raw);
                if ($statement === '' || str_starts_with($statement, '--')) {
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
        self::ensureAclEnabledColumn($db);
        self::ensureMediaUploadedByColumn($db);
        self::repairMediaColumns($db, $settings);
        self::backfillMediaRefs($db, $settings);
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
