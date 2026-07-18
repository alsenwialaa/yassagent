<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use ReflectionObject;
use Throwable;

/** Reads wpdb error state without trusting the static stub's literal default. */
final class WpdbError
{
    /** @param object $wpdb */
    public static function message($wpdb): string
    {
        try {
            $reflection = new ReflectionObject($wpdb);
            if (!$reflection->hasProperty('last_error')) {
                return '';
            }
            $value = $reflection->getProperty('last_error')->getValue($wpdb);
        } catch (Throwable $exception) {
            return '';
        }
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }
        return trim((string) $value);
    }

    /** @param object $wpdb */
    public static function has($wpdb): bool
    {
        return self::message($wpdb) !== '';
    }

    private function __construct()
    {
    }
}
