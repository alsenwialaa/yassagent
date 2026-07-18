<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce;

use WC_Product;
use WC_Product_Variation;

/** Explicit built-in chat-cart capability boundary. */
final class ProductCapabilityPolicy
{
    public function cartSupported($product): bool
    {
        return $product instanceof WC_Product
            && !($product instanceof WC_Product_Variation)
            && ($product->is_type('simple') || $product->is_type('variable'));
    }
    public function reason($product): string
    {
        return $this->cartSupported($product) ? 'supported' : 'unsupported_product_type';
    }

    /** Wildcard child variations require a concrete attribute value that this chat protocol never invents. */
    public function concreteVariation($variation): bool
    {
        if (!($variation instanceof WC_Product_Variation)) {
            return false;
        }
        $attributes = $variation->get_variation_attributes();
        if (!is_array($attributes) || $attributes === array()) {
            return false;
        }
        foreach ($attributes as $key => $value) {
            if (
                !is_string($key) || trim($key) === ''
                || (!is_string($value) && !is_numeric($value))
                || trim((string) $value) === ''
            ) {
                return false;
            }
        }
        return true;
    }
}
