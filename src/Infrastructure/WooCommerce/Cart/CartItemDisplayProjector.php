<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use RuntimeException;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\TrustedCommerceText;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Utf8;

/** Projects the same customer-visible custom line data WooCommerce renders. */
final class CartItemDisplayProjector
{
    private const MAX_ROWS = 32;
    private const MAX_BYTES = 16384;

    /**
     * @param array<string,mixed> $cartItem
     * @return array<int,array{label:string,value:string}>
     */
    public function project(array $cartItem): array
    {
        $filtered = apply_filters('woocommerce_get_item_data', array(), $cartItem);
        if (!is_array($filtered) || !Arr::isList($filtered)) {
            throw new RuntimeException('WooCommerce returned malformed customer-visible cart item data.');
        }

        $out = array();
        foreach ($filtered as $row) {
            if (!is_array($row) || !empty($row['hidden'])) {
                continue;
            }
            $label = $this->text($row['key'] ?? ($row['name'] ?? ''), 160);
            $rawValue = array_key_exists('display', $row)
                ? $row['display'] : ($row['value'] ?? '');
            $value = $this->text($rawValue, 500);
            if ($label === '' || $value === '') {
                continue;
            }
            $out[] = array('label' => $label, 'value' => $value);
            if (count($out) > self::MAX_ROWS) {
                throw new RuntimeException('Customer-visible cart item data exceeds its row bound.');
            }
        }
        if (strlen(Json::canonical($out)) > self::MAX_BYTES) {
            throw new RuntimeException('Customer-visible cart item data exceeds its byte bound.');
        }
        return $out;
    }

    /** @param mixed $value */
    private function text($value, int $limit): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }
        $text = trim(TrustedCommerceText::decodeEntities(wp_strip_all_tags((string) $value)));
        if (
            $text === '' || !Utf8::isPlainText($text)
            || !Utf8::isBounded($text, $limit, $limit * 4)
        ) {
            return '';
        }
        return $text;
    }
}
