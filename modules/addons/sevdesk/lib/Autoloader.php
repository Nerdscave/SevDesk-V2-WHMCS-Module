<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk;

/**
 * Small module-local PSR-4 loader.
 *
 * WHMCS normally discovers addon classes under lib/, but registering the
 * namespace explicitly also makes CLI workers and isolated tests predictable.
 */
final class Autoloader
{
    private const PREFIX = __NAMESPACE__ . '\\';

    public static function register(): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        spl_autoload_register(static function (string $class): void {
            if (!str_starts_with($class, self::PREFIX)) {
                return;
            }

            $relative = substr($class, strlen(self::PREFIX));
            $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

            if (is_file($path)) {
                require_once $path;
            }
        });

        $registered = true;
    }
}

Autoloader::register();
