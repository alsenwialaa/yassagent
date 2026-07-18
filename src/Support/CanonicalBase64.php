<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Support;

/** Canonical RFC 4648 base64 checks that never materialize decoded bytes. */
final class CanonicalBase64
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    public static function isValid(string $value): bool
    {
        $length = strlen($value);
        if ($length === 0 || ($length % 4) !== 0) {
            return false;
        }

        $padding = 0;
        if ($value[$length - 1] === '=') {
            $padding = 1;
            if ($length >= 2 && $value[$length - 2] === '=') {
                $padding = 2;
            }
        }
        $dataLength = $length - $padding;
        if (
            $dataLength < 2
            || strspn($value, self::ALPHABET, 0, $dataLength) !== $dataLength
        ) {
            return false;
        }
        for ($index = $dataLength; $index < $length; ++$index) {
            if ($value[$index] !== '=') {
                return false;
            }
        }

        // Reject alternate encodings with non-zero unused padding bits. This
        // gives every byte sequence exactly one accepted textual form.
        if ($padding === 2) {
            $symbol = strpos(self::ALPHABET, $value[$length - 3]);
            return is_int($symbol) && ($symbol & 15) === 0;
        }
        if ($padding === 1) {
            $symbol = strpos(self::ALPHABET, $value[$length - 2]);
            return is_int($symbol) && ($symbol & 3) === 0;
        }
        return true;
    }

    public static function decodedLength(string $value): int
    {
        if (!self::isValid($value)) {
            return -1;
        }
        $length = strlen($value);
        $padding = $value[$length - 1] === '=' ? 1 : 0;
        if ($padding === 1 && $value[$length - 2] === '=') {
            $padding = 2;
        }
        return (int) (($length / 4) * 3) - $padding;
    }

    private function __construct()
    {
    }
}
