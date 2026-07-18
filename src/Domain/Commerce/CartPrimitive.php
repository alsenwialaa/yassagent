<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Utf8;

/**
 * One mechanically executable cart mutation boundary.
 *
 * In the first-release contract one semantic command maps to exactly one
 * durable primitive. Emptying and replacement are each verified and persisted
 * as one boundary.
 */
final class CartPrimitive
{
    public const ADD = 'add_line';
    public const SET_QUANTITY = 'set_quantity';
    public const REMOVE_LINE = 'remove_line';
    public const REPLACE_LINE = 'replace_line';
    public const EMPTY_CART = 'empty_cart';

    /** @var string */ private $type;
    /** @var string */ private $semanticType;
    /** @var int */ private $commandIndex;
    /** @var string */ private $phase;
    /** @var string */ private $cartItemKey;
    /** @var string */ private $expectedLineFingerprint;
    /** @var int */ private $productId;
    /** @var int */ private $variationId;
    /** @var float */ private $quantity;
    /** @var string */ private $expectedPurchaseFingerprint;
    /** @var string */ private $displayName;

    private function __construct(
        string $type,
        string $semanticType,
        int $commandIndex,
        string $phase,
        string $cartItemKey,
        string $expectedLineFingerprint,
        int $productId,
        int $variationId,
        float $quantity,
        string $expectedPurchaseFingerprint,
        string $displayName
    ) {
        $phase = trim($phase);
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
            || !in_array($semanticType, CartCommand::types(), true)
            || $commandIndex !== 0
            || preg_match('/^[a-z0-9_]{1,32}$/', $phase) !== 1
            || strlen($cartItemKey) > 191
            || !$displayNameIsValid
            || $variationId < 0
        ) {
            throw new InvalidArgumentException('Cart primitive identity is invalid.');
        }

        $targetsLine = in_array($type, array(
            self::SET_QUANTITY, self::REMOVE_LINE, self::REPLACE_LINE,
        ), true);
        if (
            $targetsLine
            && ($cartItemKey === '' || preg_match('/^[a-f0-9]{64}$/', $expectedLineFingerprint) !== 1)
        ) {
            throw new InvalidArgumentException('Cart primitive line authority is invalid.');
        }
        if (!$targetsLine && ($cartItemKey !== '' || $expectedLineFingerprint !== '')) {
            throw new InvalidArgumentException('Cart primitive contains unsupported line authority.');
        }

        $purchasesProduct = in_array($type, array(self::ADD, self::REPLACE_LINE), true);
        if ($purchasesProduct) {
            if (
                $productId < 1 || !CartQuantity::isPositiveInteger($quantity)
                || preg_match('/^[a-f0-9]{64}$/', $expectedPurchaseFingerprint) !== 1
            ) {
                throw new InvalidArgumentException('Cart purchase primitive is invalid.');
            }
        } elseif ($expectedPurchaseFingerprint !== '') {
            throw new InvalidArgumentException('A non-purchase cart primitive contains purchase authority.');
        } elseif ($type === self::SET_QUANTITY) {
            if (!CartQuantity::isPositiveInteger($quantity) || $productId !== 0 || $variationId !== 0) {
                throw new InvalidArgumentException('Cart quantity primitive is invalid.');
            }
        } elseif ($type === self::REMOVE_LINE) {
            if ($productId !== 0 || $variationId !== 0 || $quantity !== 0.0) {
                throw new InvalidArgumentException('Cart remove primitive is invalid.');
            }
        } elseif (
            $type === self::EMPTY_CART
            && ($cartItemKey !== '' || $productId !== 0 || $variationId !== 0 || $quantity !== 0.0)
        ) {
            throw new InvalidArgumentException('Cart empty primitive contains mutation authority.');
        }

        $semanticByPrimitive = array(
            self::ADD => CartCommand::ADD,
            self::SET_QUANTITY => CartCommand::UPDATE,
            self::REMOVE_LINE => CartCommand::REMOVE,
            self::REPLACE_LINE => CartCommand::REPLACE,
            self::EMPTY_CART => CartCommand::CLEAR,
        );
        $phaseByPrimitive = array(
            self::ADD => 'single',
            self::SET_QUANTITY => 'single',
            self::REMOVE_LINE => 'single',
            self::REPLACE_LINE => 'replace_atomic',
            self::EMPTY_CART => 'clear_atomic',
        );
        if (
            !hash_equals($semanticByPrimitive[$type], $semanticType)
            || !hash_equals($phaseByPrimitive[$type], $phase)
        ) {
            throw new InvalidArgumentException('Cart primitive semantic identity or phase is contradictory.');
        }

        $this->type = $type;
        $this->semanticType = $semanticType;
        $this->commandIndex = $commandIndex;
        $this->phase = $phase;
        $this->cartItemKey = $cartItemKey;
        $this->expectedLineFingerprint = $expectedLineFingerprint;
        $this->productId = $productId;
        $this->variationId = $variationId;
        $this->quantity = $quantity;
        $this->expectedPurchaseFingerprint = $expectedPurchaseFingerprint;
        $this->displayName = $displayName;
    }

    public static function add(
        string $semanticType,
        int $commandIndex,
        string $phase,
        int $productId,
        int $variationId,
        float $quantity,
        string $expectedPurchaseFingerprint,
        string $displayName
    ): self {
        return new self(
            self::ADD,
            $semanticType,
            $commandIndex,
            $phase,
            '',
            '',
            $productId,
            $variationId,
            $quantity,
            $expectedPurchaseFingerprint,
            $displayName
        );
    }

    public static function setQuantity(
        string $semanticType,
        int $commandIndex,
        string $phase,
        string $cartItemKey,
        string $fingerprint,
        float $quantity,
        string $displayName
    ): self {
        return new self(
            self::SET_QUANTITY,
            $semanticType,
            $commandIndex,
            $phase,
            $cartItemKey,
            $fingerprint,
            0,
            0,
            $quantity,
            '',
            $displayName
        );
    }

    public static function removeLine(
        string $semanticType,
        int $commandIndex,
        string $phase,
        string $cartItemKey,
        string $fingerprint,
        string $displayName
    ): self {
        return new self(
            self::REMOVE_LINE,
            $semanticType,
            $commandIndex,
            $phase,
            $cartItemKey,
            $fingerprint,
            0,
            0,
            0.0,
            '',
            $displayName
        );
    }

    public static function replaceLine(
        int $commandIndex,
        string $cartItemKey,
        string $fingerprint,
        int $productId,
        int $variationId,
        float $quantity,
        string $expectedPurchaseFingerprint,
        string $displayName
    ): self {
        return new self(
            self::REPLACE_LINE,
            CartCommand::REPLACE,
            $commandIndex,
            'replace_atomic',
            $cartItemKey,
            $fingerprint,
            $productId,
            $variationId,
            $quantity,
            $expectedPurchaseFingerprint,
            $displayName
        );
    }

    public static function emptyCart(int $commandIndex): self
    {
        return new self(
            self::EMPTY_CART,
            CartCommand::CLEAR,
            $commandIndex,
            'clear_atomic',
            '',
            '',
            0,
            0,
            0.0,
            '',
            ''
        );
    }

    /** @return array<int,string> */
    public static function types(): array
    {
        return array(
            self::ADD, self::SET_QUANTITY, self::REMOVE_LINE,
            self::REPLACE_LINE, self::EMPTY_CART,
        );
    }

    public function type(): string
    {
        return $this->type;
    }
    public function semanticType(): string
    {
        return $this->semanticType;
    }
    public function commandIndex(): int
    {
        return $this->commandIndex;
    }
    public function phase(): string
    {
        return $this->phase;
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
    public function toStorageArray(): array
    {
        return array(
            'type' => $this->type,
            'semantic_type' => $this->semanticType,
            'command_index' => $this->commandIndex,
            'phase' => $this->phase,
            'cart_item_key' => $this->cartItemKey,
            'expected_line_fingerprint' => $this->expectedLineFingerprint,
            'product_id' => $this->productId,
            'variation_id' => $this->variationId,
            'quantity' => $this->quantity,
            'expected_purchase_fingerprint' => $this->expectedPurchaseFingerprint,
            'display_name' => $this->displayName,
        );
    }

    /** @param array<string,mixed> $row */
    public static function fromStorageArray(array $row): self
    {
        $expected = array(
            'cart_item_key', 'command_index', 'display_name', 'expected_line_fingerprint',
            'expected_purchase_fingerprint',
            'phase', 'product_id', 'quantity', 'semantic_type', 'type', 'variation_id',
        );
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        if (
            $keys !== $expected
            || !is_string($row['type']) || !is_string($row['semantic_type'])
            || !is_int($row['command_index']) || !is_string($row['phase'])
            || !is_string($row['cart_item_key']) || !is_string($row['expected_line_fingerprint'])
            || !is_string($row['expected_purchase_fingerprint'])
            || !is_int($row['product_id']) || !is_int($row['variation_id'])
            || (!is_int($row['quantity']) && !is_float($row['quantity']))
            || !is_string($row['display_name'])
        ) {
            throw new InvalidArgumentException('Stored cart primitive is invalid.');
        }
        return new self(
            $row['type'],
            $row['semantic_type'],
            $row['command_index'],
            $row['phase'],
            $row['cart_item_key'],
            $row['expected_line_fingerprint'],
            $row['product_id'],
            $row['variation_id'],
            (float) $row['quantity'],
            $row['expected_purchase_fingerprint'],
            $row['display_name']
        );
    }
}
