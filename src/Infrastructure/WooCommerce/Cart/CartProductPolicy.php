<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Domain\Commerce\CartQuantity;
use Throwable;
use WC_Product;
use WC_Product_Variation;
use YassinStore\AiAssistant\Domain\Commerce\CartPurchaseIdentity;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\ProductCapabilityPolicy;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\AttributePresenter;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\CatalogVisibilityPolicy;

/** One live product/variation identity and quantity policy for every cart boundary. */
final class CartProductPolicy
{
    /** @var WooCartGateway */ private $gateway;
    /** @var CatalogVisibilityPolicy */ private $visibility;
    /** @var ProductCapabilityPolicy */ private $capabilities;
    /** @var AttributePresenter */ private $attributes;

    public function __construct(
        WooCartGateway $gateway,
        CatalogVisibilityPolicy $visibility,
        ProductCapabilityPolicy $capabilities,
        AttributePresenter $attributes
    ) {
        $this->gateway = $gateway;
        $this->visibility = $visibility;
        $this->capabilities = $capabilities;
        $this->attributes = $attributes;
    }

    /** @param array<string,mixed> $item */
    public function resolveCartItem(array $item): ?WC_Product
    {
        $productId = (int) ($item['product_id'] ?? 0);
        $variationId = (int) ($item['variation_id'] ?? 0);
        $expectedId = $variationId > 0 ? $variationId : $productId;
        if ($productId < 1 || $variationId < 0 || $expectedId < 1) {
            return null;
        }
        // Never trust the request-local embedded data object as execution or
        // restoration authority. Reload the exact canonical product ID.
        return $this->gateway->product($expectedId);
    }

    /** @return array{product_id:int,variation_id:int,variation:array<string,string>} */
    public function purchase(
        int $productId,
        int $variationId,
        int $quantity,
        string $expectedPurchaseFingerprint,
        string $excludedCartItemKey = ''
    ): array {
        $purchase = $this->purchaseIdentity(
            $productId,
            $variationId,
            $quantity,
            $expectedPurchaseFingerprint
        );
        $effective = $this->gateway->product($variationId > 0 ? $variationId : $productId);
        if (!$effective instanceof WC_Product) {
            throw new SafeCommerceException('product_unavailable', ('هذا المنتج لم يعد متاحاً.'));
        }
        $this->assertAggregateStock($effective, $quantity, $excludedCartItemKey);
        return $purchase;
    }

    /** @param array<string,mixed> $item */
    public function effectiveCartItem(array $item, int $quantity, string $cartItemKey): WC_Product
    {
        $productId = (int) ($item['product_id'] ?? 0);
        $variationId = (int) ($item['variation_id'] ?? 0);
        $effective = $this->resolveCartItem($item);
        if ($variationId > 0) {
            $parent = $this->gateway->product($productId);
            if (
                !$this->capabilities->cartSupported($parent)
                || !$this->visibility->variationIsVisible($effective, $parent)
            ) {
                $effective = null;
            }
        } elseif (
            !$this->visibility->productIsVisible($effective)
            || !$this->capabilities->cartSupported($effective)
        ) {
            $effective = null;
        }
        if (!$effective instanceof WC_Product) {
            throw new SafeCommerceException('product_unavailable', ('هذا العنصر لم يعد متاحاً للشراء.'));
        }
        $this->assertAvailable($effective);
        $this->assertQuantityLimits($effective, $quantity);
        $this->assertQuantityStock($effective, $quantity);
        $this->assertAggregateStock($effective, $quantity, $cartItemKey);
        return $effective;
    }

    /**
     * Applies quantity invariants to an existing line without turning a
     * reduction into a new-purchase eligibility check. Hidden, discontinued,
     * or currently out-of-stock items may still be reduced or removed, while
     * sold-individually and configured min/max bounds remain authoritative.
     *
     * @param array<string,mixed> $item
     */
    public function assertExistingLineQuantity(array $item, int $quantity): void
    {
        $effective = $this->resolveCartItem($item);
        if (!$effective instanceof WC_Product) {
            throw new SafeCommerceException(
                'product_unavailable',
                ('تعذر التحقق من سياسة كمية هذا العنصر الحالي.')
            );
        }
        $this->assertQuantityLimits($effective, $quantity);
    }

    /**
     * Proves only that an already-stored line can be reconstructed exactly in
     * request memory. Purchase visibility, stock, and quantity policy belong to
     * a new customer mutation and must not prevent rollback of existing state.
     *
     * @param array<string,string> $storedVariation
     */
    public function canReconstructStoredLine(
        int $productId,
        int $variationId,
        array $storedVariation
    ): bool {
        try {
            if ($productId < 1 || $variationId < 0) {
                return false;
            }
            if ($variationId === 0) {
                return $this->gateway->product($productId) instanceof WC_Product;
            }
            $variation = $this->gateway->product($variationId);
            return $variation instanceof WC_Product_Variation
                && $variation->get_parent_id() === $productId
                && $this->sameVariation(
                    $storedVariation,
                    $this->normalizeVariation($variation->get_variation_attributes())
                );
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function assertAvailable(WC_Product $product): void
    {
        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            throw new SafeCommerceException('product_unavailable', ('هذا المنتج أو خياره غير متاح للشراء حالياً.'));
        }
    }

    private function assertQuantityLimits(WC_Product $product, int $quantity): void
    {
        if (!CartQuantity::isPositiveInteger($quantity)) {
            throw new SafeCommerceException('quantity_invalid', ('الكمية المطلوبة غير صالحة.'));
        }
        if ($product->is_sold_individually() && $quantity !== 1) {
            throw new SafeCommerceException('sold_individually', ('هذا المنتج يباع بقطعة واحدة فقط.'));
        }
        $minimum = (float) $product->get_min_purchase_quantity();
        $maximum = (float) $product->get_max_purchase_quantity();
        if ($minimum > 0 && $quantity < $minimum) {
            throw new SafeCommerceException('quantity_below_minimum', ('الكمية أقل من الحد الأدنى.'));
        }
        if ($maximum > 0 && $quantity > $maximum) {
            throw new SafeCommerceException('quantity_above_maximum', ('الكمية أكبر من الحد الأقصى المتاح.'));
        }
    }

    private function assertQuantityStock(WC_Product $product, int $quantity): void
    {
        if (!$product->has_enough_stock($quantity)) {
            throw new SafeCommerceException('insufficient_stock', ('الكمية المطلوبة غير متوفرة.'));
        }
    }

    /** @return array{product_id:int,variation_id:int,variation:array<string,string>} */
    private function purchaseIdentity(
        int $productId,
        int $variationId,
        int $quantity,
        string $expectedPurchaseFingerprint
    ): array {
        $parent = $this->gateway->product($productId);
        if (!$this->visibility->productIsVisible($parent)) {
            throw new SafeCommerceException('product_not_found', ('هذا المنتج لم يعد متاحاً.'));
        }
        /** @var WC_Product $parent */
        if (!$this->capabilities->cartSupported($parent)) {
            throw new SafeCommerceException(
                'product_type_not_supported',
                ('هذا النوع من المنتجات يحتاج الشراء من صفحة المنتج.')
            );
        }
        $effective = $parent;
        $variation = array();
        if ($parent->is_type('variable')) {
            $variant = $this->gateway->product($variationId);
            if (!$this->visibility->variationIsVisible($variant, $parent)) {
                throw new SafeCommerceException('variation_required', ('يجب اختيار خيار صالح لهذا المنتج.'));
            }
            /** @var WC_Product_Variation $variant */
            if (!$this->capabilities->concreteVariation($variant)) {
                throw new SafeCommerceException(
                    'variation_option_incomplete',
                    ('هذا الخيار يحتاج تحديد قيمة إضافية من صفحة المنتج.')
                );
            }
            $effective = $variant;
            $variation = $this->normalizeVariation($variant->get_variation_attributes());
        } elseif ($variationId > 0) {
            throw new SafeCommerceException('variation_not_allowed', ('هذا المنتج لا يستخدم خياراً إضافياً.'));
        }
        $this->assertSelectedPurchaseIdentity(
            $parent,
            $effective instanceof WC_Product_Variation ? $effective : null,
            $expectedPurchaseFingerprint
        );
        $this->assertAvailable($effective);
        $this->assertQuantityLimits($effective, $quantity);
        $this->assertQuantityStock($effective, $quantity);
        return array('product_id' => $productId, 'variation_id' => $variationId, 'variation' => $variation);
    }

    private function assertSelectedPurchaseIdentity(
        WC_Product $parent,
        ?WC_Product_Variation $variation,
        string $expectedFingerprint
    ): void {
        if (preg_match('/^[a-f0-9]{64}$/', $expectedFingerprint) !== 1) {
            throw new SafeCommerceException(
                'product_changed_since_selection',
                ('تغير المنتج أو خياره منذ عرضه. اعرض الخيارات الحالية ثم أعد الطلب.')
            );
        }
        try {
            $product = array(
                'id' => (int) $parent->get_id(),
                'name' => (string) $parent->get_name(),
                'sku' => (string) $parent->get_sku(),
                'type' => (string) $parent->get_type(),
                'requires_variation' => (bool) $parent->is_type('variable'),
            );
            $variant = $variation !== null ? array(
                'id' => (int) $variation->get_id(),
                'parent_id' => (int) $variation->get_parent_id(),
                'name' => (string) $variation->get_name(),
                'sku' => (string) $variation->get_sku(),
                'attributes' => $this->attributes->variationAttributes($variation),
            ) : null;
            $actualFingerprint = CartPurchaseIdentity::fromAuthority($product, $variant)->fingerprint();
        } catch (Throwable $exception) {
            throw new SafeCommerceException(
                'product_changed_since_selection',
                ('تغير المنتج أو خياره منذ عرضه. اعرض الخيارات الحالية ثم أعد الطلب.'),
                $exception->getMessage()
            );
        }
        if (!hash_equals($expectedFingerprint, $actualFingerprint)) {
            throw new SafeCommerceException(
                'product_changed_since_selection',
                ('تغير المنتج أو خياره منذ عرضه. اعرض الخيارات الحالية ثم أعد الطلب.')
            );
        }
    }

    private function assertAggregateStock(
        WC_Product $target,
        int $requestedQuantity,
        string $excludedCartItemKey
    ): void {
        $managedBy = $this->stockManagedById($target);
        $aggregate = $requestedQuantity;
        foreach ($this->gateway->rawCart() as $key => $item) {
            if (!is_string($key) || $key === '' || !is_array($item)) {
                throw new SafeCommerceException(
                    'cart_stock_validation_failed',
                    ('تعذر التحقق من مخزون عناصر السلة الحالية.')
                );
            }
            if ($excludedCartItemKey !== '' && hash_equals($key, $excludedCartItemKey)) {
                continue;
            }
            $product = $this->resolveCartItem($item);
            if (!$product instanceof WC_Product) {
                throw new SafeCommerceException(
                    'cart_stock_validation_failed',
                    ('تعذر التحقق من مخزون عناصر السلة الحالية.')
                );
            }
            if ($this->stockManagedById($product) !== $managedBy) {
                continue;
            }
            $quantity = $item['quantity'] ?? null;
            if (
                (!is_int($quantity) && !is_float($quantity) && !(is_string($quantity) && is_numeric($quantity)))
                || !CartQuantity::isPositiveInteger((float) $quantity)
            ) {
                throw new SafeCommerceException(
                    'cart_stock_validation_failed',
                    ('تعذر التحقق من مخزون عناصر السلة الحالية.')
                );
            }
            $aggregate += (float) $quantity;
        }
        if ($target->is_sold_individually() && $aggregate > 1) {
            throw new SafeCommerceException('sold_individually', ('هذا المنتج يباع بقطعة واحدة فقط.'));
        }
        if (!$target->has_enough_stock($aggregate)) {
            throw new SafeCommerceException('insufficient_stock', ('الكمية الإجمالية المطلوبة غير متوفرة.'));
        }
    }

    private function stockManagedById(WC_Product $product): int
    {
        return (int) $product->get_stock_managed_by_id();
    }

    /** @param array<string,mixed> $variation @return array<string,string> */
    private function normalizeVariation(array $variation): array
    {
        $out = array();
        foreach ($variation as $key => $value) {
            if (is_string($key) && trim($key) !== '' && (is_string($value) || is_numeric($value))) {
                $out[$key] = (string) $value;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @param array<string,string> $left @param array<string,string> $right */
    private function sameVariation(array $left, array $right): bool
    {
        ksort($left, SORT_STRING);
        ksort($right, SORT_STRING);
        return $left === $right;
    }
}
