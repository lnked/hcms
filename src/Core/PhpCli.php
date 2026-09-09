<?php

declare(strict_types=1);

namespace Cms\Core;

use RuntimeException;

/**
 * Pick a PHP CLI binary that satisfies composer platform (>= 8.3).
 *
 * Shared hosting often runs the site on 8.3+ while `php` / PHP_BINARY on PATH
 * is an older CLI — update migrations then die in platform_check.php.
 */
final class PhpCli
{
    public const MIN_VERSION_ID = 80300;

    /**
     * @param ?string $preferred Explicit path from CMS_PHP_CLI / caller
     */
    public static function resolve(?string $preferred = null): string
    {
        foreach (self::candidates($preferred) as $bin) {
            if (self::versionId($bin) >= self::MIN_VERSION_ID) {
                return $bin;
            }
        }

        throw new RuntimeException(
            'No PHP >= 8.3 CLI binary found for post-update migrations. '
            . 'Web SAPI is PHP ' . PHP_VERSION . ', but the shell `php` / PHP_BINARY is older. '
            . 'Set CMS_PHP_CLI in .env to your php8.3 path (e.g. /usr/local/bin/php8.3), then retry.',
        );
    }

    /**
     * @return list<string>
     */
    public static function candidates(?string $preferred = null): array
    {
        $out = [];
        self::push($out, $preferred);
        self::push($out, self::envValue('CMS_PHP_CLI'));

        if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') {
            self::push($out, PHP_BINARY);
            foreach (self::binarySiblings(PHP_BINARY) as $sibling) {
                self::push($out, $sibling);
            }
        }

        $maj = PHP_MAJOR_VERSION;
        $min = PHP_MINOR_VERSION;
        foreach ([
            "php{$maj}.{$min}",
            "php{$maj}{$min}",
            "/usr/local/bin/php{$maj}.{$min}",
            "/usr/local/bin/php{$maj}{$min}",
            "/usr/bin/php{$maj}.{$min}",
            "/usr/bin/php{$maj}{$min}",
            "/opt/php{$maj}{$min}/bin/php",
            "/opt/php/{$maj}.{$min}/bin/php",
            "/usr/local/php{$maj}{$min}/bin/php",
            "/usr/local/php/{$maj}.{$min}/bin/php",
            'php',
        ] as $bin) {
            self::push($out, $bin);
        }

        return $out;
    }

    public static function versionId(string $bin): int
    {
        if ($bin === '') {
            return 0;
        }

        // Absolute paths must exist; bare commands are resolved via PATH by the shell.
        if (str_contains($bin, '/') && !is_file($bin)) {
            return 0;
        }

        $cmd = escapeshellarg($bin) . ' -r ' . escapeshellarg('echo PHP_VERSION_ID;');
        $output = [];
        $code = 0;
        @exec($cmd . ' 2>/dev/null', $output, $code);
        if ($code !== 0 || $output === []) {
            return 0;
        }

        return (int) trim(implode('', $output));
    }

    /**
     * @return list<string>
     */
    private static function binarySiblings(string $binary): array
    {
        $siblings = [];
        // php-fpm8.3 / php-cgi8.3 → php8.3 (same dir)
        if (preg_match('#^(.*?)php-(?:fpm|cgi)(\d+(?:\.\d+)?)?$#i', $binary, $m) === 1) {
            $suffix = $m[2] ?? '';
            $siblings[] = $m[1] . 'php' . $suffix;
        }
        $replaced = preg_replace('#php-(?:fpm|cgi)#i', 'php', $binary);
        if (is_string($replaced) && $replaced !== $binary) {
            $siblings[] = $replaced;
        }

        return $siblings;
    }

    private static function envValue(string $key): ?string
    {
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        $fromEnv = getenv($key);

        return is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : null;
    }

    /**
     * @param list<string> $out
     */
    private static function push(array &$out, ?string $bin): void
    {
        if ($bin === null) {
            return;
        }
        $bin = trim($bin);
        if ($bin === '' || in_array($bin, $out, true)) {
            return;
        }
        $out[] = $bin;
    }
}
