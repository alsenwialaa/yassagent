<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use RuntimeException;

/**
 * Bounded decoder for untrusted serialized WooCommerce array authority.
 *
 * Objects, resources, recursion, excessive nesting, and oversized value graphs
 * are rejected before any decoded value can reach cart normalization or proof.
 */
final class SafeSerializedArrayDecoder
{
    private const MAX_BYTES = 8388608;
    private const MAX_DEPTH = 64;
    private const MAX_VALUES = 100000;

    /** @return array<string|int,mixed> */
    public function decode(string $serialized, string $label, bool $allowEmpty = false): array
    {
        $label = trim($label) !== '' ? trim($label) : 'Serialized array';
        if ($serialized === '') {
            if ($allowEmpty) {
                return array();
            }
            throw new RuntimeException($label . ' is missing.');
        }
        if (strlen($serialized) > self::MAX_BYTES) {
            throw new RuntimeException($label . ' exceeds the allowed size.');
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WooCommerce owns this format; classes are forbidden and the decoded graph is bounded below.
        $decoded = @unserialize($serialized, array('allowed_classes' => false));
        if (!is_array($decoded)) {
            throw new RuntimeException($label . ' is not a serialized array.');
        }

        return $this->validate($decoded, $label);
    }


    /** @param array<string|int,mixed> $decoded @return array<string|int,mixed> */
    public function validate(array $decoded, string $label): array
    {
        $label = trim($label) !== '' ? trim($label) : 'Serialized array';
        // Substitute invalid UTF-8 while using JSON only as a recursion/depth
        // detector. Binary scalar strings remain valid authority and are
        // framed byte-for-byte by the Woo session proof.
        json_encode(
            $decoded,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            self::MAX_DEPTH
        );
        $error = json_last_error();
        if ($error === JSON_ERROR_RECURSION) {
            throw new RuntimeException($label . ' contains an unsupported recursive value.');
        }
        if ($error === JSON_ERROR_DEPTH) {
            throw new RuntimeException($label . ' exceeds structural limits.');
        }

        $count = 0;
        $bytes = 0;
        $this->assertSafeTree($decoded, 0, $count, $bytes, $label);
        return $decoded;
    }

    /** @param mixed $value */
    private function assertSafeTree(
        $value,
        int $depth,
        int &$count,
        int &$bytes,
        string $label
    ): void {
        if ($depth > self::MAX_DEPTH || ++$count > self::MAX_VALUES) {
            throw new RuntimeException($label . ' exceeds structural limits.');
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $bytes += is_string($key) ? strlen($key) : PHP_INT_SIZE;
                if ($bytes > self::MAX_BYTES) {
                    throw new RuntimeException($label . ' exceeds the allowed size.');
                }
                $this->assertSafeTree($item, $depth + 1, $count, $bytes, $label);
            }
            return;
        }
        if ($value === null || is_int($value) || is_bool($value)) {
            $bytes += PHP_INT_SIZE;
        } elseif (is_string($value)) {
            $bytes += strlen($value);
        } elseif (is_float($value) && is_finite($value)) {
            $bytes += 8;
        } else {
            throw new RuntimeException($label . ' contains an unsafe value.');
        }
        if ($bytes > self::MAX_BYTES) {
            throw new RuntimeException($label . ' exceeds the allowed size.');
        }
        return;
    }
}
