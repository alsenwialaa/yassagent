<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\PublicHttpUrl;

final class CartLine
{
    /** @var string */ private $key;
    /** @var int */ private $productId;
    /** @var int */ private $variationId;
    /** @var array<string,string> */ private $variation;
    /** @var float */ private $quantity;
    /** @var string */ private $itemDataHash;
    /** @var array<string,mixed> */ private $itemData;
    /** @var bool */ private $restorable;
    /** @var array<string,mixed> */ private $publicFacts;

    /** @param array<string,string> $variation @param array<string,mixed> $itemData @param array<string,mixed> $publicFacts */
    public function __construct(
        string $key,
        int $productId,
        int $variationId,
        array $variation,
        float $quantity,
        string $itemDataHash,
        array $itemData,
        bool $restorable,
        array $publicFacts
    ) {
        $key = trim($key);
        $itemDataHash = strtolower(trim($itemDataHash));
        if (
            $key === '' || strlen($key) > 191 || preg_match('/[\x00-\x1F\x7F]/', $key) === 1
            || $productId < 1 || $variationId < 0 || !CartQuantity::isPositiveInteger($quantity)
            || preg_match('/^[a-f0-9]{64}$/', $itemDataHash) !== 1
            || ($itemData !== array() && Arr::isList($itemData))
            || ($publicFacts !== array() && Arr::isList($publicFacts))
        ) {
            throw new InvalidArgumentException('Cart line authority is invalid.');
        }
        $normalizedVariation = array();
        foreach ($variation as $name => $value) {
            if (!is_string($name) || trim($name) === '' || !is_string($value)) {
                throw new InvalidArgumentException('Cart line variation authority is invalid.');
            }
            $normalizedVariation[$name] = $value;
        }
        ksort($normalizedVariation, SORT_STRING);
        if (!hash_equals($itemDataHash, hash('sha256', Json::canonical($itemData)))) {
            throw new InvalidArgumentException('Cart line custom-data fingerprint is invalid.');
        }
        if (
            strlen(Json::encodeObject($itemData)) > 131072
            || strlen(Json::encodeObject($publicFacts)) > 65536
        ) {
            throw new InvalidArgumentException('Cart line evidence exceeds the supported size.');
        }
        foreach (array('image', 'permalink') as $urlField) {
            if (
                array_key_exists($urlField, $publicFacts)
                && !PublicHttpUrl::isSafe($publicFacts[$urlField], true)
            ) {
                throw new InvalidArgumentException('Cart line public URL evidence is invalid.');
            }
        }

        $this->key = $key;
        $this->productId = $productId;
        $this->variationId = $variationId;
        $this->variation = $normalizedVariation;
        $this->quantity = $quantity;
        $this->itemDataHash = $itemDataHash;
        $this->itemData = $itemData;
        $this->restorable = $restorable;
        $this->publicFacts = $publicFacts;
    }

    public function key(): string
    {
        return $this->key;
    }
    public function productId(): int
    {
        return $this->productId;
    }
    public function variationId(): int
    {
        return $this->variationId;
    }
    /** @return array<string,string> */ public function variation(): array
    {
        return $this->variation;
    }
    public function quantity(): float
    {
        return $this->quantity;
    }
    public function restorable(): bool
    {
        return $this->restorable;
    }
    /** @return array<string,mixed> */ public function publicFacts(): array
    {
        return $this->publicFacts;
    }

    public function fingerprint(): string
    {
        return hash('sha256', Json::canonical($this->authorityArray()));
    }

    /** @return array<string,mixed> */
    public function authorityArray(): array
    {
        return array(
            'key' => $this->key,
            'product_id' => $this->productId,
            'variation_id' => $this->variationId,
            'variation' => $this->variation,
            'quantity' => $this->quantity,
            'item_data_hash' => $this->itemDataHash,
        );
    }

    /** @return array<string,mixed> */
    public function toStorageArray(): array
    {
        return array(
            'authority' => $this->authorityArray(),
            'item_data' => $this->itemData,
            'restorable' => $this->restorable,
            'public_facts' => $this->publicFacts,
        );
    }

    /** @param array<string,mixed> $row */
    public static function fromStorageArray(array $row): self
    {
        self::assertKeys($row, array('authority', 'item_data', 'restorable', 'public_facts'));
        if (
            !is_array($row['authority']) || Arr::isList($row['authority'])
            || !is_array($row['item_data']) || ($row['item_data'] !== array() && Arr::isList($row['item_data']))
            || !is_bool($row['restorable'])
            || !is_array($row['public_facts']) || ($row['public_facts'] !== array() && Arr::isList($row['public_facts']))
        ) {
            throw new InvalidArgumentException('Stored cart line is invalid.');
        }
        $authority = $row['authority'];
        self::assertKeys($authority, array('key', 'product_id', 'variation_id', 'variation', 'quantity', 'item_data_hash'));
        if (
            !is_string($authority['key']) || !is_int($authority['product_id']) || !is_int($authority['variation_id'])
            || !is_array($authority['variation']) || ($authority['variation'] !== array() && Arr::isList($authority['variation']))
            || (!is_int($authority['quantity']) && !is_float($authority['quantity']))
            || !is_string($authority['item_data_hash'])
        ) {
            throw new InvalidArgumentException('Stored cart line authority is invalid.');
        }
        return new self(
            $authority['key'],
            $authority['product_id'],
            $authority['variation_id'],
            $authority['variation'],
            (float) $authority['quantity'],
            $authority['item_data_hash'],
            $row['item_data'],
            $row['restorable'],
            $row['public_facts']
        );
    }

    /** @param array<string,mixed> $row @param array<int,string> $expected */
    private static function assertKeys(array $row, array $expected): void
    {
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Stored cart line fields are incomplete or unsupported.');
        }
    }
}
