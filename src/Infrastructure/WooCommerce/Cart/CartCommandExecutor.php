<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Domain\Commerce\CartQuantity;
use RuntimeException;
use YassinStore\AiAssistant\Domain\Commerce\CartPrimitive;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;

/** Executes exactly one primitive and never persists, loops, verifies, or presents a receipt. */
final class CartCommandExecutor
{
    /** @var WooCartGateway */ private $gateway;
    /** @var CartProductPolicy */ private $products;
    /** @var CartMutationCapabilityProof */ private $capability;

    public function __construct(
        WooCartGateway $gateway,
        CartProductPolicy $products,
        CartMutationCapabilityProof $capability
    ) {
        $this->gateway = $gateway;
        $this->products = $products;
        $this->capability = $capability;
    }

    /** @return array<string,mixed> draft effect sealed later by CartStepVerifier */
    public function execute(CartPrimitive $primitive, CartSnapshot $preState): array
    {
        // Re-prove connection-owned named-lock authority immediately before
        // the first irreversible Woo hook can run.
        $this->capability->assertSupported();
        $this->gateway->suppressAutomaticTotals();
        try {
            return $this->executePrimitive($primitive, $preState);
        } finally {
            $this->gateway->restoreAutomaticTotals();
        }
    }

    /** @return array<string,mixed> */
    private function executePrimitive(CartPrimitive $primitive, CartSnapshot $preState): array
    {
        $base = array(
            'primitive_type' => $primitive->type(),
            'semantic_type' => $primitive->semanticType(),
            'phase' => $primitive->phase(),
            'command_index' => $primitive->commandIndex(),
        );

        if ($primitive->type() === CartPrimitive::ADD) {
            $purchase = $this->products->purchase(
                $primitive->productId(),
                $primitive->variationId(),
                (int) $primitive->quantity(),
                $primitive->expectedPurchaseFingerprint()
            );
            $key = $this->gateway->add(
                $purchase['product_id'],
                (int) $primitive->quantity(),
                $purchase['variation_id'],
                $purchase['variation']
            );
            $previous = $preState->line($key);
            return $base + array(
                'cart_item_key' => $key,
                'previous_quantity' => $previous !== null ? $previous->quantity() : 0.0,
                'quantity' => $primitive->quantity(),
                'product_id' => $primitive->productId(),
                'variation_id' => $primitive->variationId(),
                'display_name' => $primitive->displayName(),
            );
        }
        if ($primitive->type() === CartPrimitive::SET_QUANTITY) {
            $line = $this->requireFreshLine($primitive, $preState);
            $item = $this->requireRawItem($primitive->cartItemKey());
            $this->products->assertExistingLineQuantity(
                $item,
                (int) $primitive->quantity()
            );
            // Purchase visibility, stock, and purchasability predicates apply
            // only when quantity increases. Existing-line min/max and
            // sold-individually rules were proved above for both directions.
            if ($primitive->quantity() > $line->quantity()) {
                $this->products->effectiveCartItem(
                    $item,
                    (int) $primitive->quantity(),
                    $primitive->cartItemKey()
                );
            }
            if (!CartQuantity::equals($line->quantity(), $primitive->quantity())) {
                $this->gateway->setQuantity($primitive->cartItemKey(), (int) $primitive->quantity());
            }
            return $base + array(
                'cart_item_key' => $primitive->cartItemKey(),
                'previous_quantity' => $line->quantity(),
                'quantity' => $primitive->quantity(),
                'display_name' => $primitive->displayName(),
            );
        }
        if ($primitive->type() === CartPrimitive::REMOVE_LINE) {
            $line = $this->requireFreshLine($primitive, $preState);
            $this->requireRawItem($primitive->cartItemKey());
            $this->gateway->remove($primitive->cartItemKey());
            return $base + array(
                'cart_item_key' => $primitive->cartItemKey(),
                'previous_quantity' => $line->quantity(),
                'display_name' => $primitive->displayName(),
            );
        }
        if ($primitive->type() === CartPrimitive::REPLACE_LINE) {
            $source = $this->requireFreshLine($primitive, $preState);
            $this->requireRawItem($primitive->cartItemKey());
            $purchase = $this->products->purchase(
                $primitive->productId(),
                $primitive->variationId(),
                (int) $primitive->quantity(),
                $primitive->expectedPurchaseFingerprint(),
                $primitive->cartItemKey()
            );
            // The purchase policy first proves the projected stock state while
            // excluding the exact source line. Remove that source in working
            // memory before Woo's canonical add so parent-managed stock and
            // sold-individually checks see the true replacement, not source +
            // target. Any later rejection is restored by the step engine before
            // terminal classification or durable persistence.
            $this->gateway->remove($primitive->cartItemKey());
            $targetKey = $this->gateway->add(
                $purchase['product_id'],
                (int) $primitive->quantity(),
                $purchase['variation_id'],
                $purchase['variation']
            );
            if (hash_equals($primitive->cartItemKey(), $targetKey)) {
                throw new SafeCommerceException(
                    'cart_replace_same_line',
                    ('العنصر البديل اندمج مع السطر نفسه، لذلك لم يتم تنفيذ الاستبدال.')
                );
            }
            $targetBefore = $preState->line($targetKey);
            return $base + array(
                'source_cart_item_key' => $primitive->cartItemKey(),
                'source_previous_quantity' => $source->quantity(),
                'target_cart_item_key' => $targetKey,
                'target_previous_quantity' => $targetBefore !== null
                    ? $targetBefore->quantity() : 0.0,
                'quantity' => $primitive->quantity(),
                'product_id' => $primitive->productId(),
                'variation_id' => $primitive->variationId(),
                'display_name' => $primitive->displayName(),
            );
        }
        if ($primitive->type() === CartPrimitive::EMPTY_CART) {
            $this->gateway->emptyCart();
            $this->gateway->assertCanonicallyEmpty();
            return $base + array(
                'previous_line_count' => count($preState->lines()),
                'previous_coupon_count' => count($preState->coupons()),
            );
        }
        throw new RuntimeException('Unsupported cart primitive.');
    }

    private function requireFreshLine(CartPrimitive $primitive, CartSnapshot $preState): \YassinStore\AiAssistant\Domain\Commerce\CartLine
    {
        $line = $preState->line($primitive->cartItemKey());
        if ($line === null) {
            throw new SafeCommerceException(
                'cart_item_not_found',
                ('هذا العنصر لم يعد موجوداً في السلة.')
            );
        }
        if (!hash_equals($line->fingerprint(), $primitive->expectedLineFingerprint())) {
            throw new SafeCommerceException(
                'cart_item_changed',
                ('تغير هذا العنصر في السلة. اعرض السلة مجدداً.')
            );
        }
        return $line;
    }

    /** @return array<string,mixed> */
    private function requireRawItem(string $key): array
    {
        $item = $this->gateway->rawItem($key);
        if ($item === null) {
            throw new SafeCommerceException(
                'cart_item_not_found',
                ('هذا العنصر لم يعد موجوداً في السلة.')
            );
        }
        return $item;
    }
}
