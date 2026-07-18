<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce;

use RuntimeException;
use Throwable;
use YassinStore\AiAssistant\Support\Base64Url;

/**
 * Public WooCommerce session facade used by the cart application.
 *
 * All compatibility-sensitive core layout access is delegated to
 * WooSessionInternalsAdapter; this class owns only application-level session
 * lifecycle and cart-operation authority.
 */
final class WooSession
{
    public const CART_OPERATION_NONCE_KEY = 'ysai_cart_operation_nonce';
    public const CART_OPERATION_MARKER_KEY = 'ysai_cart_operation_marker';

    /** @var WooSessionInternalsAdapter */ private $internals;
    /** @var object|null */ private $cartSessionWriter;
    /** @var bool */ private $automaticTotalsSuppressed = false;

    public function __construct(?WooSessionInternalsAdapter $internals = null)
    {
        $this->internals = $internals ?? WooSessionInternalsAdapter::forInstalledWooCommerce();
        $this->cartSessionWriter = null;
    }

    public function ensure(): void
    {
        if (!class_exists('WooCommerce')) {
            throw new RuntimeException('WooCommerce is not available.');
        }

        $woocommerce = WC();
        if (!is_object($woocommerce)) {
            throw new RuntimeException('WooCommerce runtime is unavailable.');
        }
        $session = $this->runtimeComponent($woocommerce, 'session');
        $customer = $this->runtimeComponent($woocommerce, 'customer');
        $cart = $this->runtimeComponent($woocommerce, 'cart');
        $cartWasMissing = !is_object($cart);
        if (!is_object($session) || !is_object($customer) || $cartWasMissing) {
            wc_load_cart();
            $woocommerce = WC();
            if (!is_object($woocommerce)) {
                throw new RuntimeException('WooCommerce runtime is unavailable after cart initialization.');
            }
            $session = $this->runtimeComponent($woocommerce, 'session');
            $customer = $this->runtimeComponent($woocommerce, 'customer');
            $cart = $this->runtimeComponent($woocommerce, 'cart');
        }

        if (!is_object($session) || !is_object($customer) || !is_object($cart)) {
            throw new RuntimeException('WooCommerce session, customer, and cart could not be initialized.');
        }

        if ($cartWasMissing && wp_is_serving_rest_request()) {
            do_action('ysai_woocommerce_session_initialized');
        }

        // Woo's normal wp_loaded hydration callback has already passed when a
        // REST callback constructs the cart on demand. Hydrate that newly
        // constructed cart exactly once while the request fence still owns the
        // pre-hydration identity.
        if ($cartWasMissing && wp_is_serving_rest_request()) {
            $this->internals->hydrateCartFromSession($cart);
        }
    }

    /** Seeds the cart-only authority before Woo's native shutdown persistence. */
    private function seedCartOperationNonce(): void
    {
        $nonce = $this->getValue(self::CART_OPERATION_NONCE_KEY, '');
        $nonce = is_string($nonce) ? $nonce : '';
        if ($nonce !== '') {
            if (!$this->isOperationNonce($nonce)) {
                throw new RuntimeException('Cart operation authority is malformed.');
            }
            return;
        }

        $marker = $this->getValue(self::CART_OPERATION_MARKER_KEY, '');
        if ($marker !== '' && $marker !== null) {
            throw new RuntimeException('A cart marker exists without its operation authority.');
        }

        $nonce = Base64Url::encode(random_bytes(32));
        if (!$this->isOperationNonce($nonce)) {
            throw new RuntimeException('Unable to create cart operation authority.');
        }
        $this->setValue(self::CART_OPERATION_NONCE_KEY, $nonce);
        $stored = $this->getValue(self::CART_OPERATION_NONCE_KEY, '');
        if (
            !is_string($stored)
            || !$this->isOperationNonce($stored)
            || !hash_equals($nonce, $stored)
        ) {
            throw new RuntimeException('Cart operation authority could not be verified.');
        }
    }

    /**
     * Publishes the boot-created cart authority to the core Woo session row and
     * proves it can be recovered by the next storefront request.
     */
    public function publishCartOperationAuthority(): void
    {
        $this->internals->assertVerifiedCartMutationVersion();
        $this->ensure();
        if (!$this->internals->isCoreSessionHandler(WC()->session)) {
            throw new RuntimeException('WooCommerce cart operation authority cannot be published.');
        }

        $this->seedCartOperationNonce();
        $handler = WC()->session;
        $nonce = $this->cartOperationNonce();

        try {
            $customerId = $this->internals->customerId($handler);
            if ($customerId === '') {
                throw new RuntimeException('WooCommerce session identity is unavailable.');
            }
            if ((int) get_current_user_id() < 1) {
                if (headers_sent()) {
                    throw new RuntimeException('WooCommerce guest session cookie cannot be published.');
                }
                $this->internals->publishGuestCookie($handler);
            }
            $this->internals->saveSession($handler);
            $durable = $this->internals->durableSession($handler, $customerId);
            $stored = $durable[self::CART_OPERATION_NONCE_KEY] ?? '';
            $stored = is_string($stored) ? maybe_unserialize($stored) : $stored;
            if (
                !is_string($stored)
                || !$this->isOperationNonce($stored)
                || !hash_equals($nonce, $stored)
            ) {
                throw new RuntimeException('WooCommerce cart operation authority failed durable read-back.');
            }
        } catch (Throwable $exception) {
            $handler->set(self::CART_OPERATION_NONCE_KEY, '');
            throw new RuntimeException(
                'WooCommerce cart operation authority could not be published.',
                0,
                $exception
            );
        }
    }

    /** Strict read-only authority lookup for durable cart operation markers. */
    public function cartOperationNonce(): string
    {
        $nonce = $this->getValue(self::CART_OPERATION_NONCE_KEY, '');
        if (!is_string($nonce) || !$this->isOperationNonce($nonce)) {
            throw new RuntimeException('Cart operation authority is unavailable.');
        }
        return $nonce;
    }

    /** @param mixed $default @return mixed */
    public function getValue(string $key, $default = null)
    {
        $this->ensure();
        return WC()->session->get($key, $default);
    }

    /** @param mixed $value */
    public function setValue(string $key, $value): void
    {
        $this->ensure();
        WC()->session->set($key, $value);
    }

    /** @return array<string,mixed> */
    public function sessionEntries(): array
    {
        $this->ensure();
        return $this->internals->workingSessionEntries(WC()->session);
    }

    /** @param array<string,mixed> $entries */
    public function replaceSessionEntries(array $entries): void
    {
        $this->internals->assertVerifiedCartMutationVersion();
        $this->ensure();
        $this->internals->replaceWorkingSessionEntries(WC()->session, $entries);
    }

    /** Proves the active request's exact safe-mutation topology. */
    public function assertCartMutationCapability(): void
    {
        $this->ensure();
        $this->internals->assertMutationRuntime(
            WC()->session,
            WC()->cart,
            $this->cartSessionWriter
        );
    }

    /** Prevents Woo's shutdown hook from persisting an uncontained working copy. */
    public function suppressAutomaticSave(): void
    {
        $this->assertCartMutationCapability();
        if ($this->cartSessionWriter !== null) {
            return;
        }
        $this->cartSessionWriter = $this->internals->suppressAutomaticSave(
            WC()->session,
            WC()->cart
        );
    }

    /** Suppresses core total callbacks around one controlled cart primitive. */
    public function suppressAutomaticTotals(): void
    {
        $this->ensure();
        if ($this->automaticTotalsSuppressed) {
            throw new RuntimeException('WooCommerce automatic totals are already suppressed.');
        }
        $this->internals->suppressAutomaticTotals(WC()->cart);
        $this->automaticTotalsSuppressed = true;
    }

    public function restoreAutomaticTotals(): void
    {
        if (!$this->automaticTotalsSuppressed) {
            return;
        }
        $this->ensure();
        $this->internals->restoreAutomaticTotals(WC()->cart);
        $this->automaticTotalsSuppressed = false;
    }

    /** Publishes derived Woo cart cookies only after durable cart verification. */
    public function publishVerifiedCartCookies(): void
    {
        if ($this->cartSessionWriter === null) {
            throw new RuntimeException('WooCommerce cart cookie writer is unavailable.');
        }
        $this->internals->publishVerifiedCartCookies($this->cartSessionWriter);
    }

    public function sessionHandlerClass(): string
    {
        try {
            $this->ensure();
        } catch (Throwable $exception) {
            return '';
        }
        return $this->internals->sessionHandlerClass(WC()->session);
    }

    public function hasCoreSessionHandler(): bool
    {
        try {
            $this->ensure();
        } catch (Throwable $exception) {
            return false;
        }
        return $this->internals->isCoreSessionHandler(WC()->session);
    }

    public function allowsVerifiedCartMutation(): bool
    {
        return $this->internals->allowsVerifiedCartMutation();
    }

    public function assertVerifiedCartMutationVersion(): void
    {
        $this->internals->assertVerifiedCartMutationVersion();
    }

    public function sessionExpiration(): int
    {
        $this->ensure();
        return $this->internals->sessionExpiration(WC()->session);
    }

    public function markSessionClean(): void
    {
        $this->internals->assertVerifiedCartMutationVersion();
        $this->ensure();
        $this->internals->markSessionClean(WC()->session);
    }

    public function authenticatedCustomerId(): int
    {
        $id = (int) get_current_user_id();
        if ($id > 0) {
            return $id;
        }
        $this->ensure();
        $sessionCustomer = (string) WC()->session->get_customer_id();
        if (preg_match('/^[1-9][0-9]*$/D', $sessionCustomer) === 1) {
            return (int) $sessionCustomer;
        }
        if (is_object(WC()->customer)) {
            return max(0, (int) WC()->customer->get_id());
        }
        return 0;
    }

    public function persistentCartMetaKey(): string
    {
        return $this->internals->persistentCartMetaKey();
    }

    /** @return array<string,mixed> */
    public function persistentCartProjection(): array
    {
        $this->ensure();
        return $this->internals->persistentCartProjection(WC()->cart);
    }

    public function sessionTableName(): string
    {
        return $this->internals->sessionTableName();
    }

    public function invalidateSessionCache(string $customerId): void
    {
        $this->internals->assertVerifiedCartMutationVersion();
        $this->internals->invalidateSessionCache($customerId);
    }


    /** @param object $woocommerce @return mixed */
    private function runtimeComponent($woocommerce, string $name)
    {
        $properties = get_object_vars($woocommerce);
        return $properties[$name] ?? null;
    }

    private function isOperationNonce(string $value): bool
    {
        return strlen($value) === 43
            && preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) === 1;
    }
}
