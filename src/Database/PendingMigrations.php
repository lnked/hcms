<?php

declare(strict_types=1);

namespace Cms\Database;

use Cms\Core\Paths;
use Cms\Core\Settings;
use Throwable;

final class PendingMigrations
{
    public static function apply(Connection $db, Paths $paths, Settings $settings): void
    {
        $appliedRaw = $settings->get('db.migrations');
        /** @var list<string> $applied */
        $applied = is_array($appliedRaw) ? array_values(array_map('strval', $appliedRaw)) : [];
        $dir = $paths->migrations();
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        $changed = false;
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
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

        self::repairMediaColumns($db, $settings);
    }

    private static function isIgnorableMigrationError(string $statement, Throwable $e): bool
    {
        $message = $e->getMessage();
        $isAddColumn = preg_match('/^\s*ALTER\s+TABLE\b.*\bADD\s+COLUMN\b/is', $statement) === 1;
        if ($isAddColumn && (
            str_contains($message, 'Duplicate column name')
            || str_contains($message, 'duplicate column')
        )) {
            return true;
        }

        return false;
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
}
