<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Internals;

use YassinStore\AiAssistant\Infrastructure\Database\WpdbError;
use RuntimeException;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart\SafeSerializedArrayDecoder;

/** Session working-copy, durable-row, and object-cache internals. */
final class WooSessionStorageInternals
{
    private const CACHE_HELPER_CLASS = 'WC_Cache_Helper';
    private const SESSION_TABLE_SUFFIX = 'woocommerce_sessions';

    /** @var WooCoreStructureProbe */ private $core;

    public function __construct(WooCoreStructureProbe $core)
    {
        $this->core = $core;
    }

    public function assertStaticCapabilities(): void
    {
        if (!class_exists(self::CACHE_HELPER_CLASS)) {
            throw new RuntimeException('Required WooCommerce core class is unavailable: ' . self::CACHE_HELPER_CLASS . '.');
        }

        $this->core->assertPublicMethodContract(
            self::CACHE_HELPER_CLASS,
            'get_cache_prefix',
            true,
            1,
            1
        );

        if (!defined('WC_SESSION_CACHE_GROUP')) {
            throw new RuntimeException('WooCommerce session cache group is unavailable.');
        }
        $this->cacheGroup();

        foreach (array('wp_cache_get', 'wp_cache_delete', 'maybe_unserialize') as $function) {
            if (!function_exists($function)) {
                throw new RuntimeException('Required WordPress/WooCommerce storage function is unavailable: ' . $function . '.');
            }
        }
    }

    /** @param object $session */
    public function assertSessionRuntime($session): void
    {
        $this->core->assertCoreSessionObject($session);
        $table = $this->core->readSessionTable($session);
        if (!hash_equals($this->sessionTableName(), $table)) {
            throw new RuntimeException('WooCommerce session table layout is unsupported.');
        }
    }


    /** @param object $session */
    public function customerId($session): string
    {
        $this->assertSessionRuntime($session);
        $value = call_user_func(array($session, 'get_customer_id'));
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param object $session */
    public function publishGuestCookie($session): void
    {
        $this->assertSessionRuntime($session);
        call_user_func(array($session, 'set_customer_session_cookie'), true);
    }

    /** @param object $session */
    public function save($session): void
    {
        $this->assertSessionRuntime($session);
        call_user_func(array($session, 'save_data'));
    }

    /** @param object $session @return array<string,mixed> */
    public function durableSession($session, string $customerId): array
    {
        $this->assertSessionRuntime($session);
        if ($customerId === '' || strlen($customerId) > 191) {
            throw new RuntimeException('WooCommerce session identity is malformed.');
        }
        $value = call_user_func(array($session, 'get_session'), $customerId, array());
        if (!is_array($value)) {
            throw new RuntimeException('WooCommerce durable session is malformed.');
        }
        return $value;
    }

    /** @param object $session @return array<string,mixed> */
    public function workingSessionEntries($session): array
    {
        $this->assertSessionRuntime($session);
        return $this->core->readSessionEntries($session);
    }

    /** @param object $session @param array<string,mixed> $entries */
    public function replaceWorkingSessionEntries($session, array $entries): void
    {
        $this->assertSessionRuntime($session);
        $this->core->replaceSessionEntries($session, $entries);
    }

    /** @param object $session */
    public function sessionExpiration($session): int
    {
        $this->assertSessionRuntime($session);
        return $this->core->readSessionExpiration($session);
    }

    /** @param object $session */
    public function markSessionClean($session): void
    {
        $this->assertSessionRuntime($session);
        $this->core->markSessionClean($session);
    }

    public function sessionTableName(): string
    {
        global $wpdb;
        $prefix = isset($wpdb->prefix) ? (string) $wpdb->prefix : '';
        return $prefix . self::SESSION_TABLE_SUFFIX;
    }

    public function invalidateSessionCache(string $customerId): void
    {
        if ($customerId === '') {
            throw new RuntimeException('WooCommerce session cache identity is unavailable.');
        }
        $group = $this->cacheGroup();
        wp_cache_delete($this->sessionCacheKey($customerId), $group);
    }

    /** @return mixed|null */
    public function storedSessionValue(string $customerId)
    {
        if ($customerId === '' || strlen($customerId) > 191) {
            throw new RuntimeException('WooCommerce session storage identity is malformed.');
        }
        $group = $this->cacheGroup();
        $value = wp_cache_get($this->sessionCacheKey($customerId), $group);
        if ($value !== false) {
            return $value;
        }

        global $wpdb;
        $wpdb->last_error = '';
        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT session_value FROM %i WHERE session_key = %s',
            $this->sessionTableName(),
            $customerId
        ));
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('WooCommerce session storage could not be inspected.');
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public function storedSessionMap(string $customerId, SafeSerializedArrayDecoder $decoder): array
    {
        $value = $this->storedSessionValue($customerId);
        if ($value === null || $value === false) {
            return array();
        }
        if (is_array($value)) {
            return $decoder->validate($value, 'WooCommerce cookie session');
        }
        if (!is_string($value)) {
            throw new RuntimeException('WooCommerce cookie session is malformed.');
        }
        return $decoder->decode($value, 'WooCommerce cookie session');
    }

    private function cacheGroup(): string
    {
        if (!class_exists(self::CACHE_HELPER_CLASS) || !defined('WC_SESSION_CACHE_GROUP')) {
            throw new RuntimeException('WooCommerce session cache inspection is unavailable.');
        }
        $group = constant('WC_SESSION_CACHE_GROUP');
        if (!is_string($group) || $group === '') {
            throw new RuntimeException('WooCommerce session cache group is malformed.');
        }
        return $group;
    }

    private function sessionCacheKey(string $customerId): string
    {
        $class = self::CACHE_HELPER_CLASS;
        $prefix = $class::get_cache_prefix($this->cacheGroup());
        if (!is_string($prefix)) {
            throw new RuntimeException('WooCommerce session cache identity is malformed.');
        }
        return $prefix . $customerId;
    }
}
