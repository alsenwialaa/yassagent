<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Support;

use RuntimeException;
use stdClass;

/**
 * Strict JSON boundary for durable authority and signed claims.
 *
 * Invalid JSON must never be silently converted into an empty array: doing so
 * makes corruption indistinguishable from a legitimate empty object. Optional
 * database columns are handled explicitly through decodeOptionalObject().
 */
final class Json
{
    private const MAX_DEPTH = 64;
    private const MAX_BYTES = 8388608;

    /** @param mixed $value */
    public static function encode($value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            self::MAX_DEPTH
        );
        if (!is_string($json) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Unable to encode JSON: ' . json_last_error_msg());
        }

        return $json;
    }

    /** Encode a PHP associative array as a JSON object, including an empty object.
     * @param array<string,mixed> $value
     */
    public static function encodeObject(array $value): string
    {
        return self::encode((object) $value);
    }

    /**
     * Decode a required JSON object. Empty strings, arrays, scalars, malformed
     * JSON, and excessive nesting are rejected.
     *
     * @return array<string,mixed>
     */
    public static function decodeRequiredObject(string $json, string $context = 'JSON object'): array
    {
        if ($json === '') {
            throw new RuntimeException($context . ' is missing.');
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException($context . ' exceeds the allowed size.');
        }

        $decoded = json_decode($json, false, self::MAX_DEPTH, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException($context . ' is invalid: ' . json_last_error_msg());
        }
        if (!($decoded instanceof stdClass)) {
            throw new RuntimeException($context . ' must be a JSON object.');
        }

        /** @var array<string,mixed> $object */
        $object = self::normalizeDecoded($decoded);
        return $object;
    }

    /**
     * Decode an optional JSON object. Only an empty database value represents
     * absence; any non-empty malformed value fails closed.
     *
     * @return array<string,mixed>
     */
    public static function decodeOptionalObject(string $json, string $context = 'JSON object'): array
    {
        return $json === '' ? array() : self::decodeRequiredObject($json, $context);
    }

    /** Produce a stable JSON representation for fingerprints. @param mixed $value */
    public static function canonical($value): string
    {
        return self::encode(self::sortRecursively($value));
    }

    /** @param array<string,mixed> $value */
    public static function canonicalObject(array $value): string
    {
        /** @var array<string,mixed> $sorted */
        $sorted = self::sortRecursively($value);
        return self::encodeObject($sorted);
    }

    /** @param mixed $value @return mixed */
    private static function normalizeDecoded($value)
    {
        if ($value instanceof stdClass) {
            $out = array();
            foreach (get_object_vars($value) as $key => $item) {
                $out[(string) $key] = self::normalizeDecoded($item);
            }
            return $out;
        }

        if (is_array($value)) {
            $out = array();
            foreach ($value as $item) {
                $out[] = self::normalizeDecoded($item);
            }
            return $out;
        }

        return $value;
    }

    /** @param mixed $value @return mixed */
    private static function sortRecursively($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (Arr::isList($value)) {
            $out = array();
            foreach ($value as $item) {
                $out[] = self::sortRecursively($item);
            }
            return $out;
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursively($item);
        }

        return $value;
    }
}
