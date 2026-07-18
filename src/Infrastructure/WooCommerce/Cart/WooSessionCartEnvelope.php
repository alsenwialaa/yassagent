<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Domain\Commerce\CartQuantity;
use RuntimeException;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSession;

/** Exact authoritative cart and complete session-map evidence from core storage. */
final class WooSessionCartEnvelope
{
    private const CART_AUTHORITY_KEYS = array(
        'applied_coupons',
        'cart',
        'cart_totals',
        'coupon_discount_tax_totals',
        'coupon_discount_totals',
        'removed_cart_contents',
    );

    /** @var string */ private $authorityRevision;
    /** @var string */ private $payloadHash;
    /** @var array<string,mixed>|null */ private $marker;
    /** @var array<string,mixed> */ private $storedEntries;

    /** @param array<string,mixed>|null $marker @param array<string,mixed> $storedEntries */
    private function __construct(
        string $authorityRevision,
        string $payloadHash,
        ?array $marker,
        array $storedEntries
    ) {
        if (
            preg_match('/^[a-f0-9]{64}$/', $authorityRevision) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $payloadHash) !== 1
        ) {
            throw new RuntimeException('Woo session cart envelope is invalid.');
        }
        $this->authorityRevision = $authorityRevision;
        $this->payloadHash = $payloadHash;
        $this->marker = $marker;
        $this->storedEntries = $storedEntries;
    }

    public static function fromWorking(
        WooSession $session,
        CartItemDataNormalizer $normalizer,
        SafeSerializedArrayDecoder $decoder,
        string $markerKey
    ): self {
        return self::fromEntries($session->sessionEntries(), $normalizer, $decoder, $markerKey);
    }

    public static function fromStoredValue(
        string $storedValue,
        CartItemDataNormalizer $normalizer,
        SafeSerializedArrayDecoder $decoder,
        string $markerKey
    ): self {
        $outer = $decoder->decode($storedValue, 'WooCommerce core session row');
        if ($outer !== array() && array_keys($outer) === range(0, count($outer) - 1)) {
            throw new RuntimeException('WooCommerce core session row is malformed.');
        }
        return self::fromEntries($outer, $normalizer, $decoder, $markerKey);
    }

    /**
     * @param array<string,mixed> $storedEntries
     */
    private static function fromEntries(
        array $storedEntries,
        CartItemDataNormalizer $normalizer,
        SafeSerializedArrayDecoder $decoder,
        string $markerKey
    ): self {
        $decoder->validate($storedEntries, 'WooCommerce core session map');
        foreach ($storedEntries as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new RuntimeException('WooCommerce core session key is malformed.');
            }
            // WC_Session::set() stores maybe_serialize() output: compound
            // values become serialized strings, while scalar values retain
            // their PHP type. Reject filter-injected compound/resource state
            // because it is outside that exact core persistence contract.
            if (
                $value !== null
                && (!is_string($value) && !is_int($value) && !is_bool($value) && !is_float($value))
                || (is_float($value) && !is_finite($value))
            ) {
                throw new RuntimeException('WooCommerce core session entry value is malformed.');
            }
        }
        $raw = array();
        foreach (self::CART_AUTHORITY_KEYS as $key) {
            $value = $storedEntries[$key] ?? '';
            if (!is_string($value)) {
                throw new RuntimeException('WooCommerce session cart authority entry is malformed.');
            }
            $raw[$key] = $value;
        }
        $marker = null;
        if (array_key_exists($markerKey, $storedEntries) && $storedEntries[$markerKey] !== '') {
            if (!is_string($storedEntries[$markerKey])) {
                throw new RuntimeException('Durable WooCommerce cart marker is malformed.');
            }
            $marker = $decoder->decode(
                $storedEntries[$markerKey],
                'Durable WooCommerce cart marker'
            );
        }
        return self::fromRaw(
            $raw,
            is_array($marker) ? $marker : null,
            $normalizer,
            $decoder,
            $storedEntries,
            $markerKey
        );
    }

    public function authorityRevision(): string
    {
        return $this->authorityRevision;
    }
    public function payloadHash(): string
    {
        return $this->payloadHash;
    }
    /** @return array<string,mixed>|null */ public function marker(): ?array
    {
        return $this->marker;
    }
    /** @return array<string,mixed> */ public function storedEntries(): array
    {
        return $this->storedEntries;
    }
    /** @param array<string,string> $raw @param array<string,mixed>|null $marker @param array<string,mixed> $storedEntries */
    private static function fromRaw(
        array $raw,
        ?array $marker,
        CartItemDataNormalizer $normalizer,
        SafeSerializedArrayDecoder $decoder,
        array $storedEntries,
        string $markerKey
    ): self {
        $cart = $decoder->decode(
            (string) ($raw['cart'] ?? ''),
            'WooCommerce session cart authority',
            true
        );
        $coupons = $decoder->decode(
            (string) ($raw['applied_coupons'] ?? ''),
            'WooCommerce session coupon authority',
            true
        );

        $lines = array();
        foreach ($cart as $key => $item) {
            if (!is_string($key) || trim($key) === '' || !is_array($item)) {
                throw new RuntimeException('WooCommerce session line authority is malformed.');
            }
            $productId = self::integer($item['product_id'] ?? null, true);
            $variationId = self::integer($item['variation_id'] ?? 0, false);
            $quantity = self::number($item['quantity'] ?? null);
            if (!CartQuantity::isPositiveInteger($quantity)) {
                throw new RuntimeException('WooCommerce session line quantity is invalid.');
            }
            $variation = $item['variation'] ?? array();
            if (!is_array($variation)) {
                throw new RuntimeException('WooCommerce session variation authority is malformed.');
            }
            $normalizedVariation = array();
            foreach ($variation as $name => $value) {
                if (!is_string($name) || (!is_string($value) && !is_numeric($value))) {
                    throw new RuntimeException('WooCommerce session variation authority is malformed.');
                }
                $normalizedVariation[$name] = (string) $value;
            }
            ksort($normalizedVariation, SORT_STRING);
            $custom = $normalizer->normalize($item);
            $lines[$key] = array(
                'key' => $key,
                'product_id' => $productId,
                'variation_id' => $variationId,
                'variation' => $normalizedVariation,
                'quantity' => $quantity,
                'item_data_hash' => $custom['hash'],
            );
        }
        ksort($lines, SORT_STRING);

        $normalizedCoupons = array();
        foreach ($coupons as $coupon) {
            if (!is_string($coupon) || trim($coupon) === '') {
                throw new RuntimeException('WooCommerce session coupon authority is malformed.');
            }
            $normalizedCoupons[] = trim($coupon);
        }
        $normalizedCoupons = array_values(array_unique($normalizedCoupons));
        sort($normalizedCoupons, SORT_STRING);
        return new self(
            self::authorityHash($lines, $normalizedCoupons),
            self::completeSessionMapHash($storedEntries, $markerKey),
            $marker,
            $storedEntries
        );
    }

    /** @param array<string,array<string,mixed>> $lines @param array<int,string> $coupons */
    private static function authorityHash(array $lines, array $coupons): string
    {
        return hash(
            'sha256',
            \YassinStore\AiAssistant\Support\Json::canonical(array('lines' => $lines, 'coupons' => $coupons))
        );
    }

    /**
     * Hashes every exact serialized Woo session entry except this protocol's
     * marker. Length framing is deterministic and binary-safe; sorting makes
     * PHP associative-array insertion order irrelevant. The marker is proved
     * independently by its signed canonical envelope.
     *
     * @param array<string,mixed> $storedEntries
     */
    private static function completeSessionMapHash(array $storedEntries, string $markerKey): string
    {
        unset($storedEntries[$markerKey]);
        ksort($storedEntries, SORT_STRING);
        $context = hash_init('sha256');
        hash_update($context, "ysai-woo-session-map-v1\0");
        hash_update($context, pack('N', count($storedEntries)));
        foreach ($storedEntries as $key => $value) {
            // fromEntries() has already restricted values to the scalar/null
            // output domain of WooCommerce's core session serializer. Encode types explicitly so
            // the proof is independent of serialize_precision and preserves
            // binary strings as well as negative zero.
            $entryKey = (string) $key;
            $entryValue = self::scalarFrame($value);
            hash_update($context, pack('N', strlen($entryKey)));
            hash_update($context, $entryKey);
            hash_update($context, pack('N', strlen($entryValue)));
            hash_update($context, $entryValue);
        }
        return hash_final($context);
    }

    /** @param mixed $value */
    private static function scalarFrame($value): string
    {
        if ($value === null) {
            return 'n';
        }
        if (is_bool($value)) {
            return $value ? 'b1' : 'b0';
        }
        if (is_int($value)) {
            return 'i' . (string) $value;
        }
        if (is_float($value)) {
            return 'f' . pack('E', $value);
        }
        return 's' . (string) $value;
    }

    /** @param mixed $value */
    private static function integer($value, bool $positive): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[0-9]+$/', $value) === 1)) {
            throw new RuntimeException('WooCommerce session integer authority is invalid.');
        }
        $number = (int) $value;
        if (($positive && $number < 1) || (!$positive && $number < 0)) {
            throw new RuntimeException('WooCommerce session integer authority is invalid.');
        }
        return $number;
    }

    /** @param mixed $value */
    private static function number($value): float
    {
        if (
            (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value)))
            || !is_finite((float) $value)
        ) {
            throw new RuntimeException('WooCommerce session numeric authority is invalid.');
        }
        return (float) $value;
    }
}
