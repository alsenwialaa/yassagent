<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection;

use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variation;
use WP_Term;
use YassinStore\AiAssistant\Domain\Commerce\VariableProductLimits;

final class AttributePresenter
{
    /** @return array<int,array<string,mixed>> */
    public function productAttributes(WC_Product $product): array
    {
        $variationRows = array();
        $descriptiveRows = array();
        foreach ($product->get_attributes() as $attribute) {
            // Product filters can return malformed values. Ignore anything
            // outside WooCommerce's declared attribute boundary rather than
            // invoking lookalike objects supplied by third-party code.
            if (!$attribute instanceof WC_Product_Attribute) {
                continue;
            }
            $name = trim((string) $attribute->get_name());
            if ($name === '') {
                continue;
            }
            $isVariation = (bool) $attribute->get_variation();
            if (
                $isVariation
                && count($variationRows) >= VariableProductLimits::MAX_AXES
            ) {
                continue;
            }
            if (
                !$isVariation
                && count($descriptiveRows) >= VariableProductLimits::MAX_AXES
            ) {
                continue;
            }
            $values = array();
            if ($attribute->is_taxonomy()) {
                $terms = wc_get_product_terms($product->get_id(), $name, array(
                    'fields' => 'names',
                    'number' => VariableProductLimits::MAX_VALUES_PER_AXIS,
                ));
                $values = is_array($terms) ? $terms : array();
            } else {
                $options = $attribute->get_options();
                $values = is_array($options) ? $options : array();
            }
            $row = array(
                'name' => (string) wc_attribute_label($name, $product),
                'values' => array_slice(
                    array_values(array_map('strval', $values)),
                    0,
                    VariableProductLimits::MAX_VALUES_PER_AXIS
                ),
                'variation' => $isVariation,
            );
            if ($isVariation) {
                $variationRows[] = $row;
            } else {
                $descriptiveRows[] = $row;
            }
        }
        return array_merge(
            $variationRows,
            array_slice(
                $descriptiveRows,
                0,
                max(0, VariableProductLimits::MAX_AXES - count($variationRows))
            )
        );
    }

    /** @return array<int,array<string,string>> */
    public function variationAttributes(WC_Product_Variation $variation): array
    {
        $rows = array();
        foreach ($variation->get_variation_attributes() as $key => $value) {
            $taxonomy = str_replace('attribute_', '', (string) $key);
            $display = (string) $value;
            if (taxonomy_exists($taxonomy)) {
                $term = get_term_by('slug', (string) $value, $taxonomy);
                if ($term instanceof WP_Term) {
                    $display = (string) $term->name;
                }
            }
            $rows[] = array(
                'key' => (string) $key,
                'label' => (string) wc_attribute_label($taxonomy),
                'value' => (string) $value,
                'display' => $display,
            );
        }
        return $rows;
    }
}
