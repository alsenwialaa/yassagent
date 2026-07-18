<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection;

use WC_Product_Variation;
use YassinStore\AiAssistant\Support\PublicHttpUrl;

final class VariationSnapshotFactory
{
    /** @var AttributePresenter */ private $attributes;
    /** @var DisplayPriceProjection */ private $prices;

    public function __construct(AttributePresenter $attributes, DisplayPriceProjection $prices)
    {
        $this->attributes = $attributes;
        $this->prices = $prices;
    }

    /** @return array<string,mixed> */
    public function create(WC_Product_Variation $variation): array
    {
        $imageId = (int) $variation->get_image_id();
        $image = StorefrontImage::url($imageId);

        $price = $this->prices->create($variation);

        return array(
            'id' => (int) $variation->get_id(),
            'parent_id' => (int) $variation->get_parent_id(),
            'name' => (string) $variation->get_name(),
            'sku' => (string) $variation->get_sku(),
            'price' => $price['price'],
            'price_min' => $price['price_min'],
            'price_max' => $price['price_max'],
            'price_is_range' => $price['price_is_range'],
            'price_status' => $price['price_status'],
            'price_basis' => $price['price_basis'],
            'formatted_price' => $price['formatted_price'],
            'currency' => $price['currency'],
            'attributes' => $this->attributes->variationAttributes($variation),
            'in_stock' => (bool) $variation->is_in_stock(),
            'purchasable' => (bool) $variation->is_purchasable(),
            'image' => PublicHttpUrl::optional($image),
        );
    }
}
