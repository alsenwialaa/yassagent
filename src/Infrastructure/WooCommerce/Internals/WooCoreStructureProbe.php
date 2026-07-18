<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Internals;

use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Reflection-backed proof and access for the exact WooCommerce core objects.
 *
 * This class is private to WooSessionInternalsAdapter's implementation
 * boundary. Application and presentation code must never depend on it.
 */
final class WooCoreStructureProbe
{
    private const SESSION_BASE_CLASS = 'WC_Session';
    private const SESSION_HANDLER_CLASS = 'WC_Session_Handler';
    private const CART_CLASS = 'WC_Cart';
    private const CART_SESSION_CLASS = 'WC_Cart_Session';

    public function assertStaticCapabilities(): void
    {
        foreach (
            array(
            self::SESSION_BASE_CLASS,
            self::SESSION_HANDLER_CLASS,
            self::CART_CLASS,
            self::CART_SESSION_CLASS,
            ) as $class
        ) {
            if (!class_exists($class)) {
                throw new RuntimeException('Required WooCommerce core class is unavailable: ' . $class . '.');
            }
        }

        $this->assertProtectedProperty(self::SESSION_BASE_CLASS, '_data');
        $this->assertProtectedProperty(self::SESSION_BASE_CLASS, '_dirty');
        $this->assertProtectedProperty(self::SESSION_HANDLER_CLASS, '_session_expiration');
        $this->assertProtectedProperty(self::SESSION_HANDLER_CLASS, '_table');
        $this->assertProtectedProperty(self::CART_CLASS, 'session');

        $sessionParents = class_parents(self::SESSION_HANDLER_CLASS, true);
        if (!is_array($sessionParents) || !isset($sessionParents[self::SESSION_BASE_CLASS])) {
            throw new RuntimeException('WooCommerce core session-handler inheritance is unsupported.');
        }

        $this->assertPublicMethodContract(self::SESSION_HANDLER_CLASS, 'get', false, 1, 2);
        $this->assertPublicMethodContract(self::SESSION_HANDLER_CLASS, 'set', false, 2, 2);
        $this->assertPublicMethodContract(self::SESSION_HANDLER_CLASS, 'get_customer_id', false, 0, 0);
        $this->assertPublicMethodContract(self::SESSION_HANDLER_CLASS, 'save_data', false, 0, 1);
        $this->assertPublicMethodContract(self::SESSION_HANDLER_CLASS, 'get_session', false, 1, 2);
        $this->assertPublicMethodContract(self::SESSION_HANDLER_CLASS, 'delete_session', false, 1, 1);
        $this->assertPublicMethodContract(self::SESSION_HANDLER_CLASS, 'set_customer_session_cookie', false, 1, 1);
        $this->assertPublicMethodContract(self::CART_SESSION_CLASS, 'get_cart_from_session', false, 0, 0);
        $this->assertPublicMethodContract(self::CART_SESSION_CLASS, 'get_cart_for_session', false, 0, 0);
        $this->assertPublicMethodContract(self::CART_SESSION_CLASS, 'maybe_set_cart_cookies', false, 0, 0);
        $this->assertPublicMethodContract(self::CART_SESSION_CLASS, 'persistent_cart_update', false, 0, 0);
        $this->assertPublicMethodContract(self::CART_CLASS, 'calculate_totals', false, 0, 0);
    }

    /** @param mixed $handlerClass */
    public function isCoreSessionHandlerClass($handlerClass): bool
    {
        return is_string($handlerClass) && $handlerClass === self::SESSION_HANDLER_CLASS;
    }

    /** @param mixed $session */
    public function isCoreSessionHandler($session): bool
    {
        return is_object($session) && get_class($session) === self::SESSION_HANDLER_CLASS;
    }

    /** @param mixed $writer */
    public function isCoreCartSessionWriter($writer): bool
    {
        return is_object($writer) && get_class($writer) === self::CART_SESSION_CLASS;
    }

    /** @param mixed $session */
    public function sessionHandlerClass($session): string
    {
        return is_object($session) ? get_class($session) : '';
    }

    /** @param object $session */
    public function assertCoreSessionObject($session): void
    {
        if (!$this->isCoreSessionHandler($session)) {
            throw new RuntimeException('WooCommerce core session-handler topology is unsupported.');
        }

        // Re-prove the private layout at each direct-use boundary. A runtime
        // replacement cannot inherit authority merely because boot succeeded.
        $this->assertProtectedProperty(self::SESSION_BASE_CLASS, '_data');
        $this->assertProtectedProperty(self::SESSION_BASE_CLASS, '_dirty');
        $this->assertProtectedProperty(self::SESSION_HANDLER_CLASS, '_session_expiration');
        $this->assertProtectedProperty(self::SESSION_HANDLER_CLASS, '_table');
    }

    /** @param object $session @return array<string,mixed> */
    public function readSessionEntries($session): array
    {
        $this->assertCoreSessionObject($session);
        try {
            $entries = $this->property(self::SESSION_BASE_CLASS, '_data')->getValue($session);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'WooCommerce working session data cannot be inspected completely.',
                0,
                $exception
            );
        }
        if (!is_array($entries)) {
            throw new RuntimeException('WooCommerce working session data is malformed.');
        }
        return $entries;
    }

    /** @param object $session @param array<string,mixed> $entries */
    public function replaceSessionEntries($session, array $entries): void
    {
        $this->assertCoreSessionObject($session);
        try {
            $this->property(self::SESSION_BASE_CLASS, '_data')->setValue($session, $entries);
        } catch (Throwable $exception) {
            throw new RuntimeException('WooCommerce working session data cannot be restored.', 0, $exception);
        }
    }

    /** @param object $session */
    public function readSessionExpiration($session): int
    {
        $this->assertCoreSessionObject($session);
        try {
            $expiration = $this->property(
                self::SESSION_HANDLER_CLASS,
                '_session_expiration'
            )->getValue($session);
        } catch (Throwable $exception) {
            throw new RuntimeException('WooCommerce session expiration cannot be inspected.', 0, $exception);
        }
        if (!is_int($expiration) || $expiration <= time()) {
            throw new RuntimeException('WooCommerce session expiration is invalid.');
        }
        return $expiration;
    }

    /** @param object $session */
    public function markSessionClean($session): void
    {
        $this->assertCoreSessionObject($session);
        try {
            $this->property(self::SESSION_BASE_CLASS, '_dirty')->setValue($session, false);
        } catch (Throwable $exception) {
            throw new RuntimeException('WooCommerce session dirty state cannot be finalized.', 0, $exception);
        }
    }

    /** @param object $session */
    public function readSessionTable($session): string
    {
        $this->assertCoreSessionObject($session);
        try {
            $table = $this->property(self::SESSION_HANDLER_CLASS, '_table')->getValue($session);
        } catch (Throwable $exception) {
            throw new RuntimeException('WooCommerce session table cannot be inspected.', 0, $exception);
        }
        if (!is_string($table) || $table === '') {
            throw new RuntimeException('WooCommerce session table layout is malformed.');
        }
        return $table;
    }

    /** @param object $cart @return object */
    public function cartSessionWriter($cart)
    {
        if (!is_object($cart) || get_class($cart) !== self::CART_CLASS) {
            throw new RuntimeException('WooCommerce cart-session writer is unsupported.');
        }
        try {
            $writer = $this->property(self::CART_CLASS, 'session')->getValue($cart);
        } catch (Throwable $exception) {
            throw new RuntimeException('WooCommerce cart-session writer cannot be inspected.', 0, $exception);
        }
        if (!$this->isCoreCartSessionWriter($writer)) {
            throw new RuntimeException('WooCommerce cart-session writer identity is unsupported.');
        }
        return $writer;
    }

    /** @param object $cart */
    public function assertCoreCartObject($cart): void
    {
        if (!is_object($cart) || get_class($cart) !== self::CART_CLASS) {
            throw new RuntimeException('WooCommerce cart totals topology is unsupported.');
        }
    }

    private function assertProtectedProperty(string $class, string $name): void
    {
        try {
            $property = new ReflectionProperty($class, $name);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'WooCommerce runtime property is unavailable: ' . $class . '::$' . $name . '.',
                0,
                $exception
            );
        }
        if (
            !$property->isProtected() || $property->isStatic()
            || $property->getDeclaringClass()->getName() !== $class
        ) {
            throw new RuntimeException(
                'WooCommerce runtime property layout is unsupported: ' . $class . '::$' . $name . '.'
            );
        }
        self::makeReflectionPropertyAccessible($property);
    }

    public function assertPublicMethodContract(
        string $class,
        string $name,
        bool $static,
        int $requiredParameters,
        int $totalParameters
    ): void {
        try {
            $method = new ReflectionMethod($class, $name);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'WooCommerce runtime method is unavailable: ' . $class . '::' . $name . '().',
                0,
                $exception
            );
        }
        if (
            !$method->isPublic()
            || $method->isStatic() !== $static
            || $method->isAbstract()
            || $method->isVariadic()
            || $method->getNumberOfRequiredParameters() !== $requiredParameters
            || $method->getNumberOfParameters() !== $totalParameters
        ) {
            throw new RuntimeException(
                'WooCommerce runtime method layout is unsupported: ' . $class . '::' . $name . '().'
            );
        }
    }

    private function property(string $class, string $name): ReflectionProperty
    {
        $this->assertProtectedProperty($class, $name);
        $property = new ReflectionProperty($class, $name);
        self::makeReflectionPropertyAccessible($property);
        return $property;
    }

    private static function makeReflectionPropertyAccessible(ReflectionProperty $property): void
    {
        // PHP 8.1+ permits reflected non-public property access directly.
        // Calling setAccessible() there is a no-op and is deprecated in PHP 8.5.
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
    }
}
