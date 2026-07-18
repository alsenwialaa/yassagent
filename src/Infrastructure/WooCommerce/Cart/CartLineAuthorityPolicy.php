<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Domain\Commerce\CartQuantity;
use YassinStore\AiAssistant\Support\Json;

/** Defines the target-line authority that must remain stable across one cart mutation. */
final class CartLineAuthorityPolicy
{
    /** @var array<int,string> */
    private const AUTHORITY_KEYS = array(
        'key', 'product_id', 'variation_id', 'variation', 'quantity', 'item_data_hash',
    );

    /**
     * Only quantity may evolve on the one targeted line. Key, product,
     * variation attributes, and normalized custom cart-item data remain exact.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public function stableIdentityMatches(array $before, array $after): bool
    {
        if (!$this->validAuthority($before) || !$this->validAuthority($after)) {
            return false;
        }

        return hash_equals(
            Json::canonical($this->stableIdentity($before)),
            Json::canonical($this->stableIdentity($after))
        );
    }

    /** @param array<string,mixed> $authority @return array<string,mixed> */
    private function stableIdentity(array $authority): array
    {
        return array(
            'key' => $authority['key'],
            'product_id' => $authority['product_id'],
            'variation_id' => $authority['variation_id'],
            'variation' => $authority['variation'],
            // Add-ons, engraving, bundles, and other normalized cart-item
            // metadata are part of the customer's exact selected line. A
            // quantity/totals hook may not silently replace that authority.
            'item_data_hash' => $authority['item_data_hash'],
        );
    }

    /** @param array<string,mixed> $authority */
    private function validAuthority(array $authority): bool
    {
        $keys = array_keys($authority);
        sort($keys, SORT_STRING);
        $expected = self::AUTHORITY_KEYS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            return false;
        }

        return is_string($authority['key'])
            && $authority['key'] !== ''
            && is_int($authority['product_id'])
            && $authority['product_id'] > 0
            && is_int($authority['variation_id'])
            && $authority['variation_id'] >= 0
            && is_array($authority['variation'])
            && CartQuantity::isPositiveInteger($authority['quantity'])
            && is_string($authority['item_data_hash'])
            && preg_match('/^[a-f0-9]{64}$/', $authority['item_data_hash']) === 1;
    }
}
