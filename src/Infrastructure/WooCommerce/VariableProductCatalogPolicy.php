<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce;

use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variation;
use YassinStore\AiAssistant\Domain\Commerce\VariableProductLimits;

/** Determines whether one product's complete option authority is safely enumerable. */
final class VariableProductCatalogPolicy
{
    public const SUPPORTED = 'supported';
    public const NOT_REQUIRED = 'not_required';
    public const OPTIONS_UNAVAILABLE = 'variation_options_unavailable';
    public const CATALOG_TOO_LARGE = 'variation_catalog_too_large';
    public const CATALOG_INVALID = 'variation_catalog_invalid';

    public static function reasonIsSupported(string $reason): bool
    {
        return $reason === self::SUPPORTED || $reason === self::NOT_REQUIRED;
    }

    public function reason($product): string
    {
        if (!$product instanceof WC_Product || $product instanceof WC_Product_Variation) {
            return self::CATALOG_INVALID;
        }
        if (!$product->is_type('variable')) {
            return self::NOT_REQUIRED;
        }

        $children = $product->get_children();
        if (!is_array($children)) {
            return self::CATALOG_INVALID;
        }
        if ($children === array()) {
            return self::OPTIONS_UNAVAILABLE;
        }
        if (count($children) > VariableProductLimits::MAX_VARIATIONS) {
            return self::CATALOG_TOO_LARGE;
        }

        $axisCount = 0;
        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute instanceof WC_Product_Attribute || !$attribute->get_variation()) {
                continue;
            }
            ++$axisCount;
            $options = $attribute->get_options();
            if (!is_array($options)) {
                return self::CATALOG_INVALID;
            }
            if ($options === array()) {
                return self::OPTIONS_UNAVAILABLE;
            }
            if (
                $axisCount > VariableProductLimits::MAX_AXES
                || count($options) > VariableProductLimits::MAX_VALUES_PER_AXIS
            ) {
                return self::CATALOG_TOO_LARGE;
            }
        }
        return $axisCount > 0 ? self::SUPPORTED : self::OPTIONS_UNAVAILABLE;
    }
}
