<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection;

use WC_Product;
use YassinStore\AiAssistant\Support\Json;

/** Fingerprints the exact parent axes and variation rows projected to Gemini. */
final class VariationAuthorityEpoch
{
    /** @var AttributePresenter */ private $attributes;

    public function __construct(AttributePresenter $attributes)
    {
        $this->attributes = $attributes;
    }

    /** @param array<int,array<string,mixed>> $projectedVariations */
    public function create(WC_Product $product, array $projectedVariations): string
    {
        $variationAxes = array();
        foreach ($this->attributes->productAttributes($product) as $attribute) {
            if (!empty($attribute['variation'])) {
                $variationAxes[] = $attribute;
            }
        }

        return hash('sha256', Json::canonical(array(
            'parent' => array(
                'id' => (int) $product->get_id(),
                'name' => (string) $product->get_name(),
                'sku' => (string) $product->get_sku(),
                'variation_axes' => $variationAxes,
            ),
            'variations' => $projectedVariations,
        )));
    }
}
