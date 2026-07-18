<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Internals;

use RuntimeException;

/** Exact core cart-session writer and hook containment topology. */
final class WooCartHookTopology
{
    /** @var WooCoreStructureProbe */ private $core;
    /** @var WooSessionStorageInternals */ private $storage;

    public function __construct(
        WooCoreStructureProbe $core,
        WooSessionStorageInternals $storage
    ) {
        $this->core = $core;
        $this->storage = $storage;
    }

    /** @param mixed $writer */
    public function isCoreCartSessionWriter($writer): bool
    {
        return $this->core->isCoreCartSessionWriter($writer);
    }

    /** @param object $cart @return object */
    public function cartSessionWriter($cart)
    {
        return $this->core->cartSessionWriter($cart);
    }

    /** @param object $cart */
    public function hydrateCartFromSession($cart): void
    {
        $this->cartSessionWriter($cart)->get_cart_from_session();
    }

    /** @param object $cart @return array<string,mixed> */
    public function cartForPersistentSession($cart): array
    {
        $projection = $this->cartSessionWriter($cart)->get_cart_for_session();
        if (!is_array($projection)) {
            throw new RuntimeException('WooCommerce persistent-cart projection is malformed.');
        }
        return $projection;
    }

    /**
     * @param object $session
     * @param object $cart
     * @param object|null $containedWriter
     * @return object
     */
    public function assertMutationRuntime($session, $cart, $containedWriter = null)
    {
        $this->storage->workingSessionEntries($session);
        $this->storage->sessionExpiration($session);
        $writer = $this->cartSessionWriter($cart);
        $sessionCallback = array($session, 'save_data');
        $sideWriters = $this->sideWriterHooks();

        if ($containedWriter !== null) {
            if ($writer !== $containedWriter || $this->hookPriority('shutdown', $sessionCallback) !== false) {
                throw new RuntimeException('WooCommerce cart containment changed within the request.');
            }
            foreach ($sideWriters as $hook) {
                if ($this->hookPriority((string) $hook[0], array($writer, (string) $hook[1])) !== false) {
                    throw new RuntimeException('WooCommerce cart side writer reappeared within the request.');
                }
            }
            return $writer;
        }

        if ($this->hookPriority('shutdown', $sessionCallback) !== 20) {
            throw new RuntimeException('WooCommerce automatic session writer hook layout is unsupported.');
        }
        foreach ($sideWriters as $hook) {
            if ($this->hookPriority((string) $hook[0], array($writer, (string) $hook[1])) !== (int) $hook[2]) {
                throw new RuntimeException('WooCommerce cart side-writer hook layout is unsupported.');
            }
        }
        return $writer;
    }

    /** @param object $session @param object $cart @return object */
    public function suppressAutomaticSave($session, $cart)
    {
        $writer = $this->assertMutationRuntime($session, $cart);
        $callback = array($session, 'save_data');
        remove_action('shutdown', $callback, 20);
        foreach ($this->sideWriterHooks() as $hook) {
            remove_action((string) $hook[0], array($writer, (string) $hook[1]), (int) $hook[2]);
        }
        if ($this->hookPriority('shutdown', $callback) !== false) {
            throw new RuntimeException('WooCommerce automatic session persistence is still active.');
        }
        foreach ($this->sideWriterHooks() as $hook) {
            if ($this->hookPriority((string) $hook[0], array($writer, (string) $hook[1])) !== false) {
                throw new RuntimeException('WooCommerce cart side writer could not be suppressed.');
            }
        }
        return $writer;
    }

    /** @param object $cart */
    public function suppressAutomaticTotals($cart): void
    {
        $this->core->assertCoreCartObject($cart);
        $callback = array($cart, 'calculate_totals');
        foreach ($this->automaticTotalsHooks() as $hook) {
            if ($this->hookPriority($hook, $callback) !== 20) {
                throw new RuntimeException('WooCommerce automatic totals hook layout is unsupported.');
            }
        }
        foreach ($this->automaticTotalsHooks() as $hook) {
            remove_action($hook, $callback, 20);
            if ($this->hookPriority($hook, $callback) !== false) {
                throw new RuntimeException('WooCommerce automatic totals callback could not be suppressed.');
            }
        }
    }

    /** @param object $cart */
    public function restoreAutomaticTotals($cart): void
    {
        $this->core->assertCoreCartObject($cart);
        $callback = array($cart, 'calculate_totals');
        foreach ($this->automaticTotalsHooks() as $hook) {
            if ($this->hookPriority($hook, $callback) !== false) {
                throw new RuntimeException('WooCommerce automatic totals callback reappeared unexpectedly.');
            }
            add_action($hook, $callback, 20, 0);
            if ($this->hookPriority($hook, $callback) !== 20) { // @phpstan-ignore notIdentical.alwaysTrue (WordPress stubs cannot observe the callback registered immediately above)
                throw new RuntimeException('WooCommerce automatic totals callback could not be restored.');
            }
        }
    }

    /** @param object $writer */
    public function publishVerifiedCartCookies($writer): void
    {
        if (!$this->isCoreCartSessionWriter($writer)) {
            throw new RuntimeException('WooCommerce cart cookie writer is unavailable.');
        }
        $writer->maybe_set_cart_cookies();
    }


    /** @param callable|array{0:object|string,1:string}|string|false $callback @return int|false */
    private function hookPriority(string $hook, $callback)
    {
        $function = 'has' . '_action';
        $priority = $function($hook, $callback);
        return is_int($priority) ? $priority : false;
    }

    /** @return array<int,string> */
    private function automaticTotalsHooks(): array
    {
        return array('woocommerce_add_to_cart', 'woocommerce_cart_item_removed');
    }

    /** @return array<int,array{0:string,1:string,2:int}> */
    private function sideWriterHooks(): array
    {
        return array(
            array('woocommerce_add_to_cart', 'maybe_set_cart_cookies', 10),
            array('wp', 'maybe_set_cart_cookies', 99),
            array('shutdown', 'maybe_set_cart_cookies', 0),
            array('woocommerce_add_to_cart', 'persistent_cart_update', 10),
            array('woocommerce_cart_item_removed', 'persistent_cart_update', 10),
            array('woocommerce_cart_item_restored', 'persistent_cart_update', 10),
            array('woocommerce_cart_item_set_quantity', 'persistent_cart_update', 10),
        );
    }
}
