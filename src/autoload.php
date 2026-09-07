<?php

declare(strict_types=1);

/**
 * Class loading that survives a broken vendor/.
 *
 * The CMS has no runtime Composer dependencies — vendor/ only ever holds the
 * generated autoloader — so a mis-generated or half-written vendor tree must
 * not be able to take the site down. This built-in PSR-4 loader is prepended
 * and resolves Cms\* straight from src/; vendor/autoload.php is still loaded
 * afterwards when present, for dev tools and any future package.
 */

$cmsRoot = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($cmsRoot): void {
    if (!str_starts_with($class, 'Cms\\')) {
        return;
    }
    $file = $cmsRoot . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
}, true, true);

if (is_file($cmsRoot . '/vendor/autoload.php')) {
    require $cmsRoot . '/vendor/autoload.php';
}
