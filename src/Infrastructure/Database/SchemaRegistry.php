<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

/**
 * Current WordPress database scope and exact plugin table names.
 *
 * Repositories depend on this registry only for physical names. Installation,
 * readiness, and repair remain the separate responsibility of SchemaLifecycle.
 */
final class SchemaRegistry
{
    /** @var array<string,SchemaDefinition> */ private static $definitions = array();

    public static function current(): SchemaDefinition
    {
        global $wpdb;
        $key = self::scopeKey() . '|'
            . (isset($wpdb->charset) ? (string) $wpdb->charset : '') . '|'
            . (isset($wpdb->collate) ? (string) $wpdb->collate : '');
        if (!isset(self::$definitions[$key])) {
            self::$definitions[$key] = SchemaDefinition::fromWordPress();
        }
        return self::$definitions[$key];
    }

    public static function scopeKey(): string
    {
        global $wpdb;
        return (string) DB_NAME . '|' . (string) $wpdb->prefix;
    }

    public static function conversations(): string
    {
        return self::current()->tableName('conversations');
    }
    public static function browserContinuityAuthorities(): string
    {
        return self::current()->tableName('browser_continuity_authorities');
    }
    public static function messages(): string
    {
        return self::current()->tableName('messages');
    }
    public static function turns(): string
    {
        return self::current()->tableName('turns');
    }
    public static function operations(): string
    {
        return self::current()->tableName('operations');
    }
    public static function operationSteps(): string
    {
        return self::current()->tableName('operation_steps');
    }
    public static function operationStepAttempts(): string
    {
        return self::current()->tableName('operation_step_attempts');
    }
    public static function leases(): string
    {
        return self::current()->tableName('leases');
    }
    public static function rateLimits(): string
    {
        return self::current()->tableName('rate_limits');
    }
}
