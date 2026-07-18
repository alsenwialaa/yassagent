<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery;

use YassinStore\AiAssistant\Domain\Shopping\ProductPriceRange;

/** Pure live-snapshot price-range rules shared by discovery and alternatives. */
final class CatalogPricePolicy
{
    /** @param array<string,mixed> $product @return array{min:?float,max:?float,known:bool,is_range:bool} */
    public function range(array $product): array
    {
        return ProductPriceRange::fromSnapshot($product);
    }

    /** @param array<string,mixed> $product @param array<string,mixed> $args @return array{matches:bool,requires_variation:bool} */
    public function filterStatus(array $product, array $args): array
    {
        if (!isset($args['min_price']) && !isset($args['max_price'])) {
            return array('matches' => true, 'requires_variation' => false);
        }
        $range = $this->range($product);
        if (!$range['known']) {
            return array('matches' => false, 'requires_variation' => false);
        }
        $requiresVariation = false;
        if (isset($args['min_price'])) {
            $minimum = (float) $args['min_price'];
            if ((float) $range['max'] < $minimum) {
                return array('matches' => false, 'requires_variation' => false);
            }
            if ((float) $range['min'] < $minimum) {
                $requiresVariation = true;
            }
        }
        if (isset($args['max_price'])) {
            $maximum = (float) $args['max_price'];
            if ((float) $range['min'] > $maximum) {
                return array('matches' => false, 'requires_variation' => false);
            }
            if ((float) $range['max'] > $maximum) {
                $requiresVariation = true;
            }
        }
        return array('matches' => true, 'requires_variation' => $requiresVariation);
    }
}
