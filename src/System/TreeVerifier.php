<?php

declare(strict_types=1);

namespace Cms\System;

use ReflectionClass;

/**
 * Answers "would this tree serve requests?" without booting it.
 *
 * Used on a staged release before it is swapped in, on the live tree right
 * after, and by scripts/verify-tree.php in CI. Checks are filesystem-only so
 * they are safe to run in-process: the 0.45.3 regression shipped a vendor/
 * generated at the wrong depth, where every autoload path pointed above the
 * install root and every request died with a fatal error.
 */
final class TreeVerifier
{
    private const REQUIRED = [
        'VERSION',
        'src/autoload.php',
        'src/bootstrap.php',
        'src/Http/Kernel.php',
    ];

    /**
     * @return list<string> Human-readable problems; empty means usable.
     */
    public static function problems(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $problems = [];

        foreach (self::REQUIRED as $rel) {
            $file = $root . '/' . $rel;
            if (!is_file($file)) {
                $problems[] = 'missing ' . $rel;
            } elseif (filesize($file) === 0) {
                $problems[] = 'empty ' . $rel;
            }
        }
        if ($problems !== []) {
            return $problems;
        }

        return array_merge($problems, self::autoloadProblems($root));
    }

    /**
     * Full boot check: only meaningful in a process that has not already loaded
     * the classes (so, a fresh CLI run), hence kept out of {@see problems()}.
     *
     * @return list<string>
     */
    public static function bootProblems(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $problems = [];

        foreach (['Cms\Http\Kernel', 'Cms\System\UpdateService', 'Cms\Core\Version'] as $class) {
            if (!class_exists($class)) {
                $problems[] = 'class ' . $class . ' is not autoloadable';
                continue;
            }
            $file = (string) (new ReflectionClass($class))->getFileName();
            $real = realpath($file);
            $rootReal = realpath($root);
            if ($real === false || $rootReal === false || !str_starts_with($real, $rootReal)) {
                $problems[] = 'class ' . $class . ' resolved outside the tree: ' . $file;
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function autoloadProblems(string $root): array
    {
        $classmapFile = $root . '/vendor/composer/autoload_classmap.php';
        if (!is_file($classmapFile)) {
            // src/autoload.php covers Cms\* on its own, so no vendor is fine.
            return [];
        }

        // Generated maps only return arrays; including them cannot boot the app.
        $classmap = @include $classmapFile;
        if (!\is_array($classmap) || $classmap === []) {
            return ['vendor/composer/autoload_classmap.php did not return a class map'];
        }

        $problems = [];
        foreach ($classmap as $class => $file) {
            if (!\is_string($file) || is_file($file)) {
                continue;
            }
            $problems[] = 'class map points outside the tree (vendor built at the wrong depth?): '
                . $class . ' => ' . $file;
            break;
        }

        $psr4 = @include $root . '/vendor/composer/autoload_psr4.php';
        if (\is_array($psr4)) {
            foreach ($psr4 as $prefix => $dirs) {
                foreach ((array) $dirs as $dir) {
                    if (\is_string($dir) && !is_dir($dir)) {
                        $problems[] = 'PSR-4 prefix ' . $prefix . ' points at a missing directory: ' . $dir;
                    }
                }
            }
        }

        return $problems;
    }
}
