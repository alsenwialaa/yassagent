<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant;

final class Autoload
{
    private const PREFIX = 'YassinStore\\AiAssistant\\';

    /** @var bool */
    private static $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        spl_autoload_register(array(__CLASS__, 'load'));
        self::$registered = true;
    }

    public static function load(string $class): void
    {
        if (strpos($class, self::PREFIX) !== 0) {
            return;
        }

        $relative = substr($class, strlen(self::PREFIX));
        if ($relative === false || $relative === '') {
            return;
        }

        $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($path)) {
            require_once $path;
        }
    }
}
