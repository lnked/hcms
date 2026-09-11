<?php

declare(strict_types=1);

namespace Cms\Core;

use RuntimeException;

/** Upsert a single KEY=value line in a dotenv file. */
final class EnvFile
{
    public static function upsert(string $path, string $key, string $value): void
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            throw new RuntimeException('Invalid env key');
        }

        $line = $key . '=' . self::encodeValue($value);
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $lines = preg_split('/\R/', $existing) ?: [];
        $found = false;
        foreach ($lines as $i => $current) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $current) === 1) {
                $lines[$i] = $line;
                $found = true;
                break;
            }
        }
        if (!$found) {
            if ($lines !== [] && end($lines) !== '') {
                $lines[] = '';
            }
            $lines[] = $line;
        }

        $contents = implode("\n", $lines);
        if (!str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }

        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create env directory');
        }

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write .env');
        }
    }

    private static function encodeValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/[\s#"\']/', $value) === 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }
}
