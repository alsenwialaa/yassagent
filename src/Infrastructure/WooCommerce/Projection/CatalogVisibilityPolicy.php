<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection;

use WC_Product;
use WC_Product_Variation;

final class CatalogVisibilityPolicy
{
    public const SEARCH = 'search';
    public const CATALOG = 'catalog';
    public const PUBLIC = 'public';

    public function productIsVisible($product, string $context = self::PUBLIC): bool
    {
        if (
            !$product instanceof WC_Product
            || $product instanceof WC_Product_Variation
            || $product->get_status() !== 'publish'
        ) {
            return false;
        }

        $catalogVisibility = (string) $product->get_catalog_visibility();
        if ($context === self::SEARCH) {
            return $catalogVisibility === 'visible' || $catalogVisibility === 'search';
        }
        if ($context === self::CATALOG) {
            return $catalogVisibility === 'visible' || $catalogVisibility === 'catalog';
        }
        if ($context === self::PUBLIC) {
            return in_array($catalogVisibility, array('visible', 'catalog', 'search'), true);
        }
        return false;
    }

    public function variationIsVisible($variation, $parent, string $context = self::PUBLIC): bool
    {
        return $variation instanceof WC_Product_Variation
            && $variation->get_status() === 'publish'
            && $variation->variation_is_visible()
            && $parent instanceof WC_Product
            && !($parent instanceof WC_Product_Variation)
            && (int) $variation->get_parent_id() === (int) $parent->get_id()
            && $this->productIsVisible($parent, $context);
    }
}
