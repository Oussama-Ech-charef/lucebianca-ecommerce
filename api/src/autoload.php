<?php

/**
 * Manual PSR-4 autoloader — fallback so the API runs even before Composer
 * is installed (`composer install` / `composer dump-autoload`).
 *
 * Maps namespace prefix "App\" to the api/src directory, the same mapping
 * declared in composer.json. When vendor/autoload.php exists (Composer),
 * this file is skipped and Composer's optimized PSR-4 autoloader is used.
 */

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';

    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR;   // this file lives in api/src/

    if (str_starts_with($class, $prefix) === false) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});