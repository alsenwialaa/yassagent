<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use RuntimeException;
use Throwable;
use WC_Product;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\PlainMoneyFormatter;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSession;

/** Mechanical WooCommerce adapter. It contains no customer wording or policy. */
final class WooCartGateway
{
    /** @var WooSession */ private $session;
    /** @var PlainMoneyFormatter */ private $money;

    public function __construct(WooSession $session, ?PlainMoneyFormatter $money = null)
    {
        $this->session = $session;
        $this->money = $money ?: new PlainMoneyFormatter();
    }

    public function ensure(): void
    {
        $this->session->ensure();
    }

    /** @return array<string,array<string,mixed>> */
    public function rawCart(): array
    {
        $this->ensure();
        $cart = WC()->cart->get_cart();
        return is_array($cart) ? $cart : array();
    }

    /** @return array<string,mixed>|null */
    public function rawItem(string $key): ?array
    {
        $this->ensure();
        $item = WC()->cart->get_cart_item($key);
        return is_array($item) && $item !== array() ? $item : null;
    }

    public function product(int $id): ?WC_Product
    {
        $product = wc_get_product($id);
        return $product instanceof WC_Product ? $product : null;
    }

    /** @param array<string,string> $variation @param array<string,mixed> $itemData */
    public function add(int $productId, int $quantity, int $variationId, array $variation, array $itemData = array()): string
    {
        $this->ensure();
        $this->assertAddAllowed($productId, $quantity, $variationId, $variation);
        $key = WC()->cart->add_to_cart(
            $productId,
            $quantity,
            $variationId,
            $variation,
            $itemData
        );
        if (!is_string($key) || $key === '') {
            throw new RuntimeException('WooCommerce did not return a cart item key.');
        }
        return $key;
    }

    public function setQuantity(string $key, int $quantity): void
    {
        $this->ensure();
        $item = WC()->cart->get_cart_item($key);
        if (!is_array($item) || $item === array()) {
            throw new SafeCommerceException('cart_item_not_found', ('هذا العنصر لم يعد موجوداً في السلة.'));
        }
        $this->assertUpdateAllowed($key, $item, $quantity);
        if (WC()->cart->set_quantity($key, $quantity, false) === false) {
            throw new RuntimeException('WooCommerce rejected the quantity update.');
        }
    }

    public function remove(string $key): void
    {
        $this->ensure();
        if (!WC()->cart->remove_cart_item($key) && $this->rawItem($key) !== null) {
            throw new RuntimeException('WooCommerce rejected the cart item removal.');
        }
    }

    /** Uses WooCommerce's canonical empty-cart hooks without an unfenced usermeta write. */
    public function emptyCart(): void
    {
        $this->ensure();
        // The persistent projection is updated inside the same fenced database
        // transaction as the core session row. Passing false prevents Woo's
        // ordinary usermeta writer from escaping that boundary.
        WC()->cart->empty_cart(false);
    }

    public function assertCanonicallyEmpty(): void
    {
        $this->ensure();
        $removed = WC()->cart->get_removed_cart_contents();
        if (
            $this->rawCart() !== array()
            || $this->coupons() !== array()
            || !is_array($removed)
            || $removed !== array()
        ) {
            throw new RuntimeException('WooCommerce did not produce canonical empty-cart state.');
        }
    }

    /** @return array<int,string> */
    public function coupons(): array
    {
        $this->ensure();
        return array_values(array_map('strval', (array) WC()->cart->get_applied_coupons()));
    }

    public function calculate(): void
    {
        $this->ensure();
        WC()->cart->calculate_totals();
    }

    public function suppressAutomaticTotals(): void
    {
        $this->session->suppressAutomaticTotals();
    }

    public function restoreAutomaticTotals(): void
    {
        $this->session->restoreAutomaticTotals();
    }

    /** Stages the already-calculated working cart in the Woo session without saving it. */
    public function stageCurrentSession(): void
    {
        $this->ensure();
        WC()->cart->set_session();
    }

    /** @return array<string,mixed> */
    public function facts(): array
    {
        $this->ensure();
        $currency = (string) get_woocommerce_currency();
        $subtotal = (float) WC()->cart->get_displayed_subtotal();
        $total = (float) WC()->cart->get_total('edit');
        $count = WC()->cart->get_cart_contents_count();
        if (
            (!is_int($count) && !is_float($count))
            || !is_finite((float) $count)
            || (float) $count < 0
            || floor((float) $count) !== (float) $count
        ) {
            throw new RuntimeException('WooCommerce cart item count is not a non-negative integer.');
        }
        return array(
            'item_count' => (int) $count,
            'subtotal' => $subtotal,
            'total' => $total,
            'formatted_subtotal' => $this->money->amount($subtotal, $currency),
            'formatted_total' => $this->money->amount($total, $currency),
            'currency' => $currency,
            'woocommerce_cart_hash' => (string) WC()->cart->get_cart_hash(),
            'cart_url' => (string) wc_get_cart_url(),
            'checkout_url' => (string) wc_get_checkout_url(),
        );
    }

    /**
     * Restores a request-local cart from already validated durable session
     * authority. This is containment rollback, so it deliberately emits no
     * commerce hooks and performs no new validation or calculation.
     *
     * @param array<string,array<string,mixed>> $cart
     * @param array<string,mixed> $totals
     * @param array<int,string> $coupons
     * @param array<string,mixed> $couponDiscountTotals
     * @param array<string,mixed> $couponDiscountTaxTotals
     * @param array<string,array<string,mixed>> $removed
     */
    public function restoreWorkingCart(
        array $cart,
        array $totals,
        array $coupons,
        array $couponDiscountTotals,
        array $couponDiscountTaxTotals,
        array $removed
    ): void {
        $this->ensure();
        $restored = array();
        foreach ($cart as $key => $item) {
            if (!is_string($key) || $key === '' || !is_array($item)) {
                throw new RuntimeException('Durable cart rollback line is malformed.');
            }
            $productId = (int) ($item['product_id'] ?? 0);
            $variationId = (int) ($item['variation_id'] ?? 0);
            $product = $this->product($variationId > 0 ? $variationId : $productId);
            if (!$product instanceof WC_Product) {
                throw new RuntimeException('Durable cart rollback product is unavailable.');
            }
            $item['data'] = $product;
            $restored[$key] = $item;
        }
        WC()->cart->set_cart_contents($restored);
        WC()->cart->set_totals($totals);
        WC()->cart->set_applied_coupons($coupons);
        WC()->cart->set_coupon_discount_totals($couponDiscountTotals);
        WC()->cart->set_coupon_discount_tax_totals($couponDiscountTaxTotals);
        WC()->cart->set_removed_cart_contents($removed);
    }

    private function assertAddAllowed(
        int $productId,
        int $quantity,
        int $variationId,
        array $variation
    ): void {
        $beforeErrors = $this->errorNoticeCount();
        try {
            // These are the exact WC_Form_Handler 10.9.4 signatures: simple
            // products receive three arguments; variable products receive five.
            $allowed = $variationId > 0
                ? apply_filters(
                    'woocommerce_add_to_cart_validation',
                    true,
                    $productId,
                    $quantity,
                    $variationId,
                    $variation
                )
                : apply_filters('woocommerce_add_to_cart_validation', true, $productId, $quantity);
        } catch (Throwable $exception) {
            throw new SafeCommerceException(
                'cart_add_validation_failed',
                ('تعذر إضافة المنتج لأن المتجر رفض التحقق من الطلب.'),
                $exception->getMessage()
            );
        }
        if (!$allowed || $this->errorNoticeCount() > $beforeErrors) {
            throw new SafeCommerceException(
                'cart_add_rejected',
                ('لا يمكن إضافة هذا المنتج بهذه الكمية حالياً.')
            );
        }
    }

    /** @param array<string,mixed> $item */
    private function assertUpdateAllowed(string $key, array $item, int $quantity): void
    {
        $beforeErrors = $this->errorNoticeCount();
        try {
            // Exact WC_Form_Handler 10.9.4 update-cart filter signature.
            $allowed = apply_filters(
                'woocommerce_update_cart_validation',
                true,
                $key,
                $item,
                $quantity
            );
        } catch (Throwable $exception) {
            throw new SafeCommerceException(
                'cart_update_validation_failed',
                ('تعذر تحديث الكمية لأن المتجر رفض التحقق من الطلب.'),
                $exception->getMessage()
            );
        }
        if (!$allowed || $this->errorNoticeCount() > $beforeErrors) {
            throw new SafeCommerceException(
                'cart_update_rejected',
                ('لا يمكن تحديث هذا العنصر إلى الكمية المطلوبة حالياً.')
            );
        }
    }

    private function errorNoticeCount(): int
    {
        return max(0, (int) wc_notice_count('error'));
    }
}
