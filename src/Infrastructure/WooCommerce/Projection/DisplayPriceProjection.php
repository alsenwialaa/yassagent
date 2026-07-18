<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection;

use WC_Product;
use WC_Product_Variable;
use YassinStore\AiAssistant\Domain\Shopping\ProductPriceRange;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\PlainMoneyFormatter;

/** One WooCommerce storefront display-price basis for parents and exact variations. */
final class DisplayPriceProjection
{
    /** @var PlainMoneyFormatter */ private $formatter;

    public function __construct(?PlainMoneyFormatter $formatter = null)
    {
        $this->formatter = $formatter ?: new PlainMoneyFormatter();
    }

    /** @return array{price:string,price_min:string,price_max:string,price_is_range:bool,price_status:string,price_basis:string,currency:string,formatted_price:string} */
    public function create(WC_Product $product): array
    {
        $minimum = null;
        $maximum = null;
        if ($product instanceof WC_Product_Variable) {
            $minimum = $product->get_variation_price('min', true);
            $maximum = $product->get_variation_price('max', true);
        } else {
            $raw = $product->get_price();
            if ($raw !== '' && is_numeric($raw)) {
                $minimum = wc_get_price_to_display($product);
                $maximum = $minimum;
            }
        }
        $basis = $this->basis();
        $currency = (string) get_woocommerce_currency();
        $range = ProductPriceRange::fromValues($minimum, $maximum);
        if (!$range['known'] || $range['min'] === null || $range['max'] === null) {
            return array(
                'price' => '',
                'price_min' => '',
                'price_max' => '',
                'price_is_range' => false,
                'price_status' => 'unknown',
                'price_basis' => $basis,
                'currency' => $currency,
                'formatted_price' => '',
            );
        }
        $priceIsRange = $range['is_range'];
        return array(
            'price' => $this->decimal($range['min']),
            'price_min' => $this->decimal($range['min']),
            'price_max' => $this->decimal($range['max']),
            'price_is_range' => $priceIsRange,
            'price_status' => $priceIsRange ? 'range' : 'exact',
            'price_basis' => $basis,
            'currency' => $currency,
            'formatted_price' => $priceIsRange
                ? $this->formatter->range($range['min'], $range['max'], $currency)
                : $this->formatter->amount($range['min'], $currency),
        );
    }

    private function basis(): string
    {
        $tax = (bool) wc_tax_enabled();
        if (!$tax) {
            return 'woocommerce_storefront_display_no_tax';
        }
        $mode = (string) get_option('woocommerce_tax_display_shop', 'excl');
        return $mode === 'incl' ? 'woocommerce_storefront_display_including_tax' : 'woocommerce_storefront_display_excluding_tax';
    }
    private function decimal(float $value): string
    {
        $v = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        return $v === '' ? '0' : $v;
    }
}
