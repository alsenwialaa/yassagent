<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection\StorefrontImage;
use YassinStore\AiAssistant\Application\Port\CartSnapshotProviderPort;
use RuntimeException;
use WC_Product;
use WP_Term;
use YassinStore\AiAssistant\Domain\Commerce\CartLine;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Support\PublicHttpUrl;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\PlainMoneyFormatter;

final class CartSnapshotFactory implements CartSnapshotProviderPort
{
    /** @var WooCartGateway */ private $gateway;
    /** @var CartItemDataNormalizer */ private $normalizer;
    /** @var CartProductPolicy */ private $products;
    /** @var PlainMoneyFormatter */ private $money;
    /** @var CartItemDisplayProjector */ private $displayData;

    public function __construct(
        WooCartGateway $gateway,
        CartItemDataNormalizer $normalizer,
        CartProductPolicy $products,
        PlainMoneyFormatter $money,
        CartItemDisplayProjector $displayData
    ) {
        $this->gateway = $gateway;
        $this->normalizer = $normalizer;
        $this->products = $products;
        $this->money = $money;
        $this->displayData = $displayData;
    }

    /** Calculates totals once, then captures that exact request-local cart state. */
    public function capture(): CartSnapshot
    {
        $this->gateway->calculate();
        return $this->captureCurrent();
    }

    /** Captures the current request-local cart without invoking WooCommerce hooks again. */
    public function captureCurrent(): CartSnapshot
    {
        $lines = array();
        foreach ($this->gateway->rawCart() as $key => $item) {
            if (!is_string($key) || trim($key) === '' || !is_array($item) || $item === array()) {
                throw new RuntimeException('WooCommerce returned a malformed cart line.');
            }
            $productId = $this->positiveInt($item['product_id'] ?? null, 'product ID');
            $variationId = $this->nonNegativeInt($item['variation_id'] ?? 0, 'variation ID');
            $quantity = $this->positiveInteger($item['quantity'] ?? null, 'quantity');
            $product = $this->products->resolveCartItem($item);
            $variation = $this->variation($item['variation'] ?? array());
            $custom = $this->normalizer->normalize($item);
            $restorable = $custom['restorable']
                && $this->products->canReconstructStoredLine($productId, $variationId, $variation);

            $lines[$key] = new CartLine(
                $key,
                $productId,
                $variationId,
                $variation,
                $quantity,
                $custom['hash'],
                $custom['data'],
                $restorable,
                $this->publicFacts($item, $product, $quantity)
            );
        }

        return new CartSnapshot($lines, $this->gateway->coupons(), $this->gateway->facts());
    }

    /** @param mixed $variation @return array<string,string> */
    private function variation($variation): array
    {
        if (!is_array($variation)) {
            throw new RuntimeException('WooCommerce returned malformed variation authority.');
        }
        $out = array();
        foreach ($variation as $key => $value) {
            if (!is_string($key) || trim($key) === '' || (!is_string($value) && !is_numeric($value))) {
                throw new RuntimeException('WooCommerce returned malformed variation authority.');
            }
            $normalized = (string) $value;
            if (strlen($key) > 191 || strlen($normalized) > 1000) {
                throw new RuntimeException('WooCommerce variation authority exceeds the supported size.');
            }
            $out[$key] = $normalized;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function publicFacts(array $item, ?WC_Product $product, int $quantity): array
    {
        $lineTotal = $this->finiteNumber($item['line_total'] ?? 0, 'line total');
        $name = $product instanceof WC_Product ? wp_strip_all_tags((string) $product->get_name()) : '';
        $currency = (string) get_woocommerce_currency();
        $formatted = $this->money->amount($lineTotal, $currency);
        try {
            $displayIsValid = Utf8::codePointLength($name) <= 500
                && Utf8::codePointLength($formatted) <= 2048;
        } catch (\InvalidArgumentException $exception) {
            $displayIsValid = false;
        }
        if (!$displayIsValid) {
            throw new RuntimeException('WooCommerce cart display facts exceed the public response limit.');
        }
        return array(
            'product_id' => (int) $item['product_id'],
            'variation_id' => (int) ($item['variation_id'] ?? 0),
            'name' => $name,
            'quantity' => $quantity,
            'line_total' => $lineTotal,
            'formatted_line_total' => $formatted,
            'attributes' => $this->displayAttributes($item['variation'] ?? array()),
            'item_data' => $this->displayData->project($item),
            'image' => $product instanceof WC_Product ? PublicHttpUrl::optional($this->image($product)) : '',
            'permalink' => $product instanceof WC_Product ? PublicHttpUrl::optional((string) $product->get_permalink()) : '',
        );
    }

    /** @param mixed $variation @return array<int,array<string,string>> */
    private function displayAttributes($variation): array
    {
        $normalized = $this->variation($variation);
        $attributes = array();
        foreach ($normalized as $key => $value) {
            $taxonomy = str_replace('attribute_', '', $key);
            $display = $value;
            if (taxonomy_exists($taxonomy)) {
                $term = get_term_by('slug', $value, $taxonomy);
                if ($term instanceof WP_Term) {
                    $display = (string) $term->name;
                }
            }
            $attributes[] = array(
                'label' => wp_strip_all_tags((string) wc_attribute_label($taxonomy)),
                'value' => wp_strip_all_tags($display),
            );
        }
        return $attributes;
    }

    private function image(WC_Product $product): string
    {
        return StorefrontImage::url((int) $product->get_image_id());
    }

    /** @param mixed $value */
    private function positiveInt($value, string $label): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[0-9]+$/', $value) === 1)) {
            throw new RuntimeException('WooCommerce cart ' . $label . ' is invalid.');
        }
        $number = (int) $value;
        if ($number < 1) {
            throw new RuntimeException('WooCommerce cart ' . $label . ' is invalid.');
        }
        return $number;
    }

    /** @param mixed $value */
    private function nonNegativeInt($value, string $label): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[0-9]+$/', $value) === 1)) {
            throw new RuntimeException('WooCommerce cart ' . $label . ' is invalid.');
        }
        $number = (int) $value;
        if ($number < 0) {
            throw new RuntimeException('WooCommerce cart ' . $label . ' is invalid.');
        }
        return $number;
    }

    /** @param mixed $value */
    private function positiveInteger($value, string $label): int
    {
        $number = $this->finiteNumber($value, $label);
        if ($number <= 0 || floor($number) !== $number) {
            throw new RuntimeException('WooCommerce cart ' . $label . ' is invalid.');
        }
        return (int) $number;
    }

    /** @param mixed $value */
    private function finiteNumber($value, string $label): float
    {
        if (
            (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value)))
            || !is_finite((float) $value)
        ) {
            throw new RuntimeException('WooCommerce cart ' . $label . ' is invalid.');
        }
        return (float) $value;
    }
}
