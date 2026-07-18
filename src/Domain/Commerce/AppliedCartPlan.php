<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Arr;

/** The exact effect completed by the server for one cart command. */
final class AppliedCartPlan
{
    /** @var array<int,array<string,mixed>> */ private $effects;

    /** @param array<int,array<string,mixed>> $effects */
    public function __construct(array $effects)
    {
        if (!Arr::isList($effects) || count($effects) > 1) {
            throw new InvalidArgumentException('Applied cart effects must contain at most one effect.');
        }
        $normalized = array();
        foreach ($effects as $effect) {
            if (!is_array($effect) || ($effect !== array() && Arr::isList($effect))) {
                throw new InvalidArgumentException('An applied cart effect is invalid.');
            }
            $normalized[] = $this->normalize($effect);
        }
        $this->effects = $normalized;
    }

    /** @return array<int,array<string,mixed>> */ public function effects(): array
    {
        return $this->effects;
    }
    public function count(): int
    {
        return count($this->effects);
    }
    public function isEmpty(): bool
    {
        return $this->effects === array();
    }
    /** @return array<string,mixed> */ public function toStorageArray(): array
    {
        return array('effects' => $this->effects);
    }

    /** @param array<string,mixed> $row */
    public static function fromStorageArray(array $row): self
    {
        if (array_keys($row) !== array('effects') || !is_array($row['effects']) || !Arr::isList($row['effects'])) {
            throw new InvalidArgumentException('Stored cart effects are invalid.');
        }
        return new self($row['effects']);
    }

    /** @param array<string,mixed> $effect @return array<string,mixed> */
    private function normalize(array $effect): array
    {
        if (
            !isset($effect['type']) || !is_string($effect['type'])
            || !in_array($effect['type'], CartCommand::types(), true)
        ) {
            throw new InvalidArgumentException('Applied cart effect type is invalid.');
        }
        $type = $effect['type'];
        if ($type === CartCommand::CLEAR) {
            $this->assertKeys($effect, array('type', 'previous_line_count'));
            if (!is_int($effect['previous_line_count']) || $effect['previous_line_count'] < 0) {
                throw new InvalidArgumentException('Applied clear effect is invalid.');
            }
            return $effect;
        }

        if ($type === CartCommand::REPLACE) {
            $this->assertKeys($effect, array(
                'type', 'source_cart_item_key', 'source_previous_quantity',
                'target_cart_item_key', 'target_previous_quantity', 'quantity',
                'product_id', 'variation_id', 'display_name',
            ));
            if (
                !is_string($effect['source_cart_item_key'])
                || trim($effect['source_cart_item_key']) === ''
                || !is_string($effect['target_cart_item_key'])
                || trim($effect['target_cart_item_key']) === ''
                || hash_equals($effect['source_cart_item_key'], $effect['target_cart_item_key'])
                || !is_string($effect['display_name'])
                || !CartQuantity::isPositiveInteger($effect['source_previous_quantity'])
                || !CartQuantity::isNonNegativeInteger($effect['target_previous_quantity'])
                || !$this->integerQuantity($effect['quantity'])
                || !is_int($effect['product_id']) || $effect['product_id'] < 1
                || !is_int($effect['variation_id']) || $effect['variation_id'] < 0
            ) {
                throw new InvalidArgumentException('Applied replacement effect is invalid.');
            }
            return $effect;
        }

        $required = array('type', 'cart_item_key', 'previous_quantity', 'display_name');
        if ($type === CartCommand::ADD) {
            $required = array_merge($required, array('quantity', 'product_id', 'variation_id'));
        } elseif ($type === CartCommand::UPDATE) {
            $required[] = 'quantity';
        }
        $this->assertKeys($effect, $required);
        if (
            !is_string($effect['cart_item_key']) || trim($effect['cart_item_key']) === ''
            || !is_string($effect['display_name'])
            || !CartQuantity::isNonNegativeInteger($effect['previous_quantity'])
        ) {
            throw new InvalidArgumentException('Applied cart effect fields are invalid.');
        }
        if (isset($effect['quantity']) && (!$this->integerQuantity($effect['quantity']))) {
            throw new InvalidArgumentException('Applied cart effect quantity is invalid.');
        }
        if (isset($effect['product_id']) && (!is_int($effect['product_id']) || $effect['product_id'] < 1)) {
            throw new InvalidArgumentException('Applied cart effect product is invalid.');
        }
        if (isset($effect['variation_id']) && (!is_int($effect['variation_id']) || $effect['variation_id'] < 0)) {
            throw new InvalidArgumentException('Applied cart effect variation is invalid.');
        }
        return $effect;
    }

    /** @param array<string,mixed> $row @param array<int,string> $expected */
    private function assertKeys(array $row, array $expected): void
    {
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Applied cart effect fields are incomplete or unsupported.');
        }
    }
    /** @param mixed $value */
    private function integerQuantity($value): bool
    {
        return CartQuantity::isPositiveInteger($value);
    }
}
