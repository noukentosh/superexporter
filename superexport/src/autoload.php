<?php

declare(strict_types=1);

/**
 * PSR-4 style autoloader for the SuperExport namespace (no Composer in MVP).
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'SuperExport\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
