<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Utf8;

final class CartCommand
{
    public const ADD = 'add';
    public const UPDATE = 'update';
    public const REMOVE = 'remove';
    public const REPLACE = 'replace';
    public const CLEAR = 'clear';

    /** @var string */ private $type;
    /** @var string */ private $cartItemKey;
    /** @var string */ private $expectedLineFingerprint;
    /** @var int */ private $productId;
    /** @var int */ private $variationId;
    /** @var float */ private $quantity;
    /** @var string */ private $expectedPurchaseFingerprint;
    /** @var string */ private $displayName;

    private function __construct(
        string $type,
        string $cartItemKey,
        string $expectedLineFingerprint,
        int $productId,
        int $variationId,
        float $quantity,
        string $expectedPurchaseFingerprint,
        string $displayName
    ) {
        $cartItemKey = trim($cartItemKey);
        $expectedLineFingerprint = strtolower(trim($expectedLineFingerprint));
        $expectedPurchaseFingerprint = strtolower(trim($expectedPurchaseFingerprint));
        $displayName = trim($displayName);
        try {
            $displayNameIsValid = Utf8::codePointLength($displayName) <= 500;
        } catch (InvalidArgumentException $exception) {
            $displayNameIsValid = false;
        }
        if (
            !in_array($type, self::types(), true)
            || strlen($cartItemKey) > 191 || preg_match('/[\x00-\x1F\x7F]/', $cartItemKey) === 1
            || !$displayNameIsValid || $variationId < 0
        ) {
            throw new InvalidArgumentException('Cart command fields are invalid.');
        }
        $targetsLine = in_array($type, array(self::UPDATE, self::REMOVE, self::REPLACE), true);
        $targetsProduct = in_array($type, array(self::ADD, self::REPLACE), true);
        $needsQuantity = in_array($type, array(self::ADD, self::UPDATE, self::REPLACE), true);
        if (
            ($type === self::CLEAR && $displayName !== '')
            || ($type !== self::CLEAR && $displayName === '')
        ) {
            throw new InvalidArgumentException('A cart command display identity is invalid.');
        }
        if ($targetsLine && ($cartItemKey === '' || preg_match('/^[a-f0-9]{64}$/', $expectedLineFingerprint) !== 1)) {
            throw new InvalidArgumentException('A cart command target is invalid.');
        }
        if (!$targetsLine && ($cartItemKey !== '' || $expectedLineFingerprint !== '')) {
            throw new InvalidArgumentException('A nontargeting cart command contains line authority.');
        }
        if ($targetsProduct && $productId < 1) {
            throw new InvalidArgumentException('A cart command product is invalid.');
        }
        if ($targetsProduct && preg_match('/^[a-f0-9]{64}$/', $expectedPurchaseFingerprint) !== 1) {
            throw new InvalidArgumentException('A cart command purchase identity is invalid.');
        }
        if (!$targetsProduct && $expectedPurchaseFingerprint !== '') {
            throw new InvalidArgumentException('A non-product cart command contains purchase authority.');
        }
        if (!$targetsProduct && ($productId !== 0 || $variationId !== 0)) {
            throw new InvalidArgumentException('A non-product cart command contains product authority.');
        }
        if ($needsQuantity && (!CartQuantity::isPositiveInteger($quantity))) {
            throw new InvalidArgumentException('A cart command quantity is invalid.');
        }
        if (!$needsQuantity && $quantity !== 0.0) {
            throw new InvalidArgumentException('A quantity-free cart command contains a quantity.');
        }

        $this->type = $type;
        $this->cartItemKey = $cartItemKey;
        $this->expectedLineFingerprint = $expectedLineFingerprint;
        $this->productId = $productId;
        $this->variationId = $variationId;
        $this->quantity = $quantity;
        $this->expectedPurchaseFingerprint = $expectedPurchaseFingerprint;
        $this->displayName = $displayName;
    }

    public static function add(
        int $productId,
        int $variationId,
        float $quantity,
        string $expectedPurchaseFingerprint,
        string $displayName
    ): self {
        return new self(
            self::ADD,
            '',
            '',
            $productId,
            $variationId,
            $quantity,
            $expectedPurchaseFingerprint,
            $displayName
        );
    }

    public static function update(string $cartItemKey, string $fingerprint, float $quantity, string $displayName): self
    {
        return new self(self::UPDATE, $cartItemKey, $fingerprint, 0, 0, $quantity, '', $displayName);
    }

    public static function remove(string $cartItemKey, string $fingerprint, string $displayName): self
    {
        return new self(self::REMOVE, $cartItemKey, $fingerprint, 0, 0, 0.0, '', $displayName);
    }

    public static function replace(
        string $cartItemKey,
        string $fingerprint,
        int $productId,
        int $variationId,
        float $quantity,
        string $expectedPurchaseFingerprint,
        string $displayName
    ): self {
        return new self(
            self::REPLACE,
            $cartItemKey,
            $fingerprint,
            $productId,
            $variationId,
            $quantity,
            $expectedPurchaseFingerprint,
            $displayName
        );
    }

    public static function clear(): self
    {
        return new self(self::CLEAR, '', '', 0, 0, 0.0, '', '');
    }

    /** @return array<int,string> */
    public static function types(): array
    {
        return array(self::ADD, self::UPDATE, self::REMOVE, self::REPLACE, self::CLEAR);
    }

    public function type(): string
    {
        return $this->type;
    }
    public function cartItemKey(): string
    {
        return $this->cartItemKey;
    }
    public function expectedLineFingerprint(): string
    {
        return $this->expectedLineFingerprint;
    }
    public function productId(): int
    {
        return $this->productId;
    }
    public function variationId(): int
    {
        return $this->variationId;
    }
    public function quantity(): float
    {
        return $this->quantity;
    }
    public function expectedPurchaseFingerprint(): string
    {
        return $this->expectedPurchaseFingerprint;
    }
    public function displayName(): string
    {
        return $this->displayName;
    }
    /** @return array<string,mixed> */
    public function canonical(): array
    {
        $row = array('type' => $this->type);
        if ($this->cartItemKey !== '') {
            $row['cart_item_key'] = $this->cartItemKey;
            $row['expected_line_fingerprint'] = $this->expectedLineFingerprint;
        }
        if ($this->productId > 0) {
            $row['product_id'] = $this->productId;
            $row['variation_id'] = $this->variationId;
            $row['expected_purchase_fingerprint'] = $this->expectedPurchaseFingerprint;
        }
        if ($this->quantity > 0) {
            $row['quantity'] = $this->quantity;
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function toStorageArray(): array
    {
        $row = $this->canonical();
        $row['display_name'] = $this->displayName;
        return $row;
    }

    /** @param array<string,mixed> $row */
    public static function fromStorageArray(array $row): self
    {
        if (!isset($row['type']) || !is_string($row['type'])) {
            throw new InvalidArgumentException('Stored cart command type is invalid.');
        }
        $type = $row['type'];
        $required = array('type', 'display_name');
        if ($type === self::ADD) {
            $required = array_merge(
                $required,
                array('product_id', 'variation_id', 'quantity', 'expected_purchase_fingerprint')
            );
        }
        if ($type === self::UPDATE) {
            $required = array_merge($required, array('cart_item_key', 'expected_line_fingerprint', 'quantity'));
        }
        if ($type === self::REMOVE) {
            $required = array_merge($required, array('cart_item_key', 'expected_line_fingerprint'));
        }
        if ($type === self::REPLACE) {
            $required = array_merge($required, array(
                'cart_item_key', 'expected_line_fingerprint', 'product_id',
                'variation_id', 'quantity', 'expected_purchase_fingerprint',
            ));
        }
        if (!in_array($type, self::types(), true)) {
            throw new InvalidArgumentException('Stored cart command is invalid.');
        }
        self::assertKeys($row, $required);
        if (!is_string($row['display_name'])) {
            throw new InvalidArgumentException('Stored cart command display name is invalid.');
        }
        if (
            ($type === self::CLEAR && $row['display_name'] !== '')
            || ($type !== self::CLEAR && trim($row['display_name']) === '')
        ) {
            throw new InvalidArgumentException('Stored cart command display identity is invalid.');
        }
        if ($type === self::ADD) {
            self::assertNumberFields($row, true);
            if (!is_string($row['expected_purchase_fingerprint'])) {
                throw new InvalidArgumentException('Stored cart command purchase identity is invalid.');
            }
            return self::add(
                $row['product_id'],
                $row['variation_id'],
                (float) $row['quantity'],
                $row['expected_purchase_fingerprint'],
                $row['display_name']
            );
        }
        if ($type === self::UPDATE) {
            self::assertTargetFields($row);
            self::assertQuantity($row['quantity']);
            return self::update($row['cart_item_key'], $row['expected_line_fingerprint'], (float) $row['quantity'], $row['display_name']);
        }
        if ($type === self::REMOVE) {
            self::assertTargetFields($row);
            return self::remove($row['cart_item_key'], $row['expected_line_fingerprint'], $row['display_name']);
        }
        if ($type === self::REPLACE) {
            self::assertTargetFields($row);
            self::assertNumberFields($row, true);
            if (!is_string($row['expected_purchase_fingerprint'])) {
                throw new InvalidArgumentException('Stored replacement purchase identity is invalid.');
            }
            return self::replace(
                $row['cart_item_key'],
                $row['expected_line_fingerprint'],
                $row['product_id'],
                $row['variation_id'],
                (float) $row['quantity'],
                $row['expected_purchase_fingerprint'],
                $row['display_name']
            );
        }
        return self::clear();
    }

    /** @param array<string,mixed> $row @param array<int,string> $expected */
    private static function assertKeys(array $row, array $expected): void
    {
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Stored cart command fields are invalid.');
        }
    }
    /** @param array<string,mixed> $row */
    private static function assertTargetFields(array $row): void
    {
        if (!is_string($row['cart_item_key']) || !is_string($row['expected_line_fingerprint'])) {
            throw new InvalidArgumentException('Stored cart command target fields are invalid.');
        }
    }
    /** @param array<string,mixed> $row */
    private static function assertNumberFields(array $row, bool $quantity): void
    {
        if (!is_int($row['product_id']) || !is_int($row['variation_id'])) {
            throw new InvalidArgumentException('Stored cart command product fields are invalid.');
        }
        if ($quantity) {
            self::assertQuantity($row['quantity']);
        }
    }
    /** @param mixed $value */
    private static function assertQuantity($value): void
    {
        if ((!is_int($value) && !is_float($value)) || !CartQuantity::isPositiveInteger((float) $value)) {
            throw new InvalidArgumentException('Stored cart command quantity is invalid.');
        }
    }
}
