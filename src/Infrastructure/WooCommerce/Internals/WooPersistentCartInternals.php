<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Internals;

/** Persistent-cart projection and WordPress user-meta identity. */
final class WooPersistentCartInternals
{
    /** @var WooCartHookTopology */ private $hooks;

    public function __construct(WooCartHookTopology $hooks)
    {
        $this->hooks = $hooks;
    }

    public function persistentCartMetaKey(): string
    {
        return '_woocommerce_persistent_cart_' . max(1, (int) get_current_blog_id());
    }

    /** @param object $cart @return array<string,mixed> */
    public function persistentCartProjection($cart): array
    {
        return array('cart' => $this->hooks->cartForPersistentSession($cart));
    }
}
