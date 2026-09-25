<?php

declare(strict_types=1);

namespace Cms\Fields;

use Throwable;

/**
 * Discovers extra field types from composer.json extra.hcms.field-types
 * and extensions/<name>/manifest.php (returns FieldType class-strings).
 */
final class FieldTypeDiscovery
{
    public static function registerInto(FieldTypeRegistry $registry, string $projectRoot): void
    {
        $root = rtrim($projectRoot, '/');
        self::registerComposerExtra($registry, $root . '/composer.json');
        self::registerExtensions($registry, $root . '/extensions');
    }

    private static function registerComposerExtra(FieldTypeRegistry $registry, string $composerJsonPath): void
    {
        if (!is_file($composerJsonPath)) {
            return;
        }
        $raw = file_get_contents($composerJsonPath);
        if ($raw === false) {
            return;
        }
        $json = json_decode($raw, true);
        if (!\is_array($json)) {
            return;
        }
        $classes = $json['extra']['hcms']['field-types'] ?? null;
        if (!\is_array($classes)) {
            return;
        }
        foreach ($classes as $class) {
            if (!\is_string($class) || $class === '') {
                continue;
            }
            self::registerClass($registry, $class);
        }
    }

    private static function registerExtensions(FieldTypeRegistry $registry, string $extensionsDir): void
    {
        if (!is_dir($extensionsDir)) {
            return;
        }
        $dirs = scandir($extensionsDir);
        if ($dirs === false) {
            return;
        }
        foreach ($dirs as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $extensionsDir . '/' . $entry;
            if (!is_dir($dir)) {
                continue;
            }
            $manifest = $dir . '/manifest.php';
            if (!is_file($manifest)) {
                continue;
            }
            try {
                /** @var mixed $result */
                $result = require $manifest;
            } catch (Throwable) {
                continue;
            }
            if (!\is_array($result)) {
                continue;
            }
            foreach ($result as $class) {
                if (!\is_string($class) || $class === '') {
                    continue;
                }
                self::registerClass($registry, $class);
            }
        }
    }

    private static function registerClass(FieldTypeRegistry $registry, string $class): void
    {
        if (!class_exists($class)) {
            return;
        }
        try {
            $instance = new $class();
        } catch (Throwable) {
            return;
        }
        if (!$instance instanceof FieldType) {
            return;
        }
        $registry->register($instance);
    }
}
