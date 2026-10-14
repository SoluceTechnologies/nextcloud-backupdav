<?php

declare(strict_types=1);

// Unit tests run inside the Nextcloud image (Makefile `test`): OCP interfaces, sabre/dav and
// psr/log come from the exact Nextcloud release the app targets, not from a stub package.
require __DIR__ . '/../vendor/autoload.php';
require '/usr/src/nextcloud/3rdparty/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'OCP\\')) {
        $file = '/usr/src/nextcloud/lib/public/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
