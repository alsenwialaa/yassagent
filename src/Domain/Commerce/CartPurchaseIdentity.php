<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Utf8;

/** Immutable product/variation identity selected from live current-turn authority. */
final class CartPurchaseIdentity
{
    private const MAX_NAME_CODE_POINTS = 500;
    private const MAX_NAME_BYTES = 2000;
    private const MAX_SKU_CODE_POINTS = 191;
    private const MAX_SKU_BYTES = 764;
    private const MAX_ATTRIBUTES = 32;

    /** @var array<string,mixed> */ private $payload;

    /** @param array<string,mixed> $product @param array<string,mixed>|null $variation */
    private function __construct(array $product, ?array $variation)
    {
        $productId = self::positiveInt($product, 'id', 'product id');
        $productName = self::text(
            $product,
            'name',
            self::MAX_NAME_CODE_POINTS,
            self::MAX_NAME_BYTES,
            false,
            'product name'
        );
        $productSku = self::text(
            $product,
            'sku',
            self::MAX_SKU_CODE_POINTS,
            self::MAX_SKU_BYTES,
            true,
            'product SKU'
        );
        if (!isset($product['type']) || !is_string($product['type'])) {
            throw new InvalidArgumentException('Cart purchase identity product type is invalid.');
        }
        $productType = $product['type'];
        if (preg_match('/^[a-z0-9_-]{1,64}$/', $productType) !== 1) {
            throw new InvalidArgumentException('Cart purchase identity product type is invalid.');
        }
        if (
            !array_key_exists('requires_variation', $product)
            || !is_bool($product['requires_variation'])
        ) {
            throw new InvalidArgumentException('Cart purchase identity variation topology is invalid.');
        }
        $requiresVariation = $product['requires_variation'];
        if (
            $requiresVariation !== ($productType === 'variable')
            || ($requiresVariation && $variation === null)
            || (!$requiresVariation && $variation !== null)
        ) {
            throw new InvalidArgumentException('Cart purchase identity variation topology is contradictory.');
        }

        $variationIdentity = null;
        if ($variation !== null) {
            $variationId = self::positiveInt($variation, 'id', 'variation id');
            $parentId = self::positiveInt($variation, 'parent_id', 'variation parent id');
            if ($parentId !== $productId) {
                throw new InvalidArgumentException('Cart purchase identity variation parent is invalid.');
            }
            $variationIdentity = array(
                'id' => $variationId,
                'parent_id' => $parentId,
                'name' => self::text(
                    $variation,
                    'name',
                    self::MAX_NAME_CODE_POINTS,
                    self::MAX_NAME_BYTES,
                    false,
                    'variation name'
                ),
                'sku' => self::text(
                    $variation,
                    'sku',
                    self::MAX_SKU_CODE_POINTS,
                    self::MAX_SKU_BYTES,
                    true,
                    'variation SKU'
                ),
                'attributes' => self::attributes($variation),
            );
        }

        $this->payload = array(
            'product' => array(
                'id' => $productId,
                'name' => $productName,
                'sku' => $productSku,
                'type' => $productType,
                'requires_variation' => $requiresVariation,
            ),
            'variation' => $variationIdentity,
        );
    }

    /** @param array<string,mixed> $product @param array<string,mixed>|null $variation */
    public static function fromAuthority(array $product, ?array $variation): self
    {
        return new self($product, $variation);
    }

    public function fingerprint(): string
    {
        return hash(
            'sha256',
            "cart-purchase-identity\0" . Json::canonical($this->payload)
        );
    }

    /** @param array<string,mixed> $source */
    private static function positiveInt(array $source, string $key, string $label): int
    {
        if (!isset($source[$key]) || !is_int($source[$key]) || $source[$key] < 1) {
            throw new InvalidArgumentException('Cart purchase identity ' . $label . ' is invalid.');
        }
        return $source[$key];
    }

    /** @param array<string,mixed> $source */
    private static function text(
        array $source,
        string $key,
        int $maximumCodePoints,
        int $maximumBytes,
        bool $allowEmpty,
        string $label
    ): string {
        if (!array_key_exists($key, $source) || !is_string($source[$key])) {
            throw new InvalidArgumentException('Cart purchase identity ' . $label . ' is invalid.');
        }
        $value = $source[$key];
        if (
            (!$allowEmpty && trim($value) === '')
            || !Utf8::isPlainText($value)
            || !Utf8::isBounded($value, $maximumCodePoints, $maximumBytes)
        ) {
            throw new InvalidArgumentException('Cart purchase identity ' . $label . ' is invalid.');
        }
        return $value;
    }

    /** @param array<string,mixed> $variation @return array<int,array<string,string>> */
    private static function attributes(array $variation): array
    {
        $source = $variation['attributes'] ?? null;
        if (!is_array($source) || !Arr::isList($source) || count($source) > self::MAX_ATTRIBUTES) {
            throw new InvalidArgumentException('Cart purchase identity variation attributes are invalid.');
        }
        $rows = array();
        $seen = array();
        foreach ($source as $attribute) {
            if (!is_array($attribute) || ($attribute !== array() && Arr::isList($attribute))) {
                throw new InvalidArgumentException('Cart purchase identity variation attribute is invalid.');
            }
            $keys = array_keys($attribute);
            sort($keys, SORT_STRING);
            if ($keys !== array('display', 'key', 'label', 'value')) {
                throw new InvalidArgumentException('Cart purchase identity variation attribute fields are invalid.');
            }
            $key = self::text(
                $attribute,
                'key',
                self::MAX_SKU_CODE_POINTS,
                self::MAX_SKU_BYTES,
                false,
                'variation attribute key'
            );
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Cart purchase identity variation attribute keys are duplicated.');
            }
            $seen[$key] = true;
            $rows[] = array(
                'key' => $key,
                'label' => self::text(
                    $attribute,
                    'label',
                    self::MAX_NAME_CODE_POINTS,
                    self::MAX_NAME_BYTES,
                    true,
                    'variation attribute label'
                ),
                'value' => self::text(
                    $attribute,
                    'value',
                    self::MAX_NAME_CODE_POINTS,
                    self::MAX_NAME_BYTES,
                    true,
                    'variation attribute value'
                ),
                'display' => self::text(
                    $attribute,
                    'display',
                    self::MAX_NAME_CODE_POINTS,
                    self::MAX_NAME_BYTES,
                    true,
                    'variation attribute display value'
                ),
            );
        }
        usort($rows, static function (array $left, array $right): int {
            return strcmp($left['key'], $right['key']);
        });
        return $rows;
    }
}
