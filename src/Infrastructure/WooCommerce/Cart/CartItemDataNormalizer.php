<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Support\Json;

final class CartItemDataNormalizer
{
    /** @var array<string,bool> */
    private $coreKeys;

    public function __construct()
    {
        $this->coreKeys = array_fill_keys(array(
            'key', 'cart_item_key', 'product_id', 'variation_id', 'variation', 'quantity',
            'data', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax',
            'line_total', 'line_tax', '_reduced_stock',
        ), true);
    }

    /** @param array<string,mixed> $item @return array{data:array<string,mixed>,hash:string,restorable:bool} */
    public function normalize(array $item): array
    {
        $custom = array_diff_key($item, $this->coreKeys);
        $restorable = true;
        $normalized = $this->value($custom, $restorable, 0);
        $normalized = is_array($normalized) ? $normalized : array();

        return array(
            'data' => $normalized,
            'hash' => hash('sha256', Json::canonical($normalized)),
            'restorable' => $restorable,
        );
    }

    /** @param mixed $value @return mixed */
    private function value($value, bool &$restorable, int $depth)
    {
        if ($depth > 12) {
            $restorable = false;
            return array('__unsupported' => 'depth');
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (is_finite($value)) {
                return $value;
            }
            $restorable = false;
            return array('__unsupported' => 'non_finite_float');
        }
        if (is_array($value)) {
            $out = array();
            foreach ($value as $key => $child) {
                $out[$key] = $this->value($child, $restorable, $depth + 1);
            }
            return $out;
        }
        if (is_object($value)) {
            $restorable = false;
            $out = array('__class' => get_class($value));
            foreach (get_object_vars($value) as $key => $child) {
                $out[(string) $key] = $this->value($child, $restorable, $depth + 1);
            }
            return $out;
        }

        $restorable = false;
        return array('__unsupported' => gettype($value));
    }
}
