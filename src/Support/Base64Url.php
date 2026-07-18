<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Support;

use InvalidArgumentException;

/** Strict unpadded RFC 4648 base64url encoding for opaque binary authority. */
final class Base64Url
{
    public static function encode(string $binary): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-to-text encoding for signed opaque authority, not code obfuscation.
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            throw new InvalidArgumentException('Base64url value is malformed.');
        }
        $remainder = strlen($encoded) % 4;
        if ($remainder === 1) {
            throw new InvalidArgumentException('Base64url value has an invalid length.');
        }
        $padded = $encoded . ($remainder === 0 ? '' : str_repeat('=', 4 - $remainder));
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- strict binary decoding for signed opaque authority, not code execution.
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if (!is_string($decoded) || !hash_equals(self::encode($decoded), $encoded)) {
            throw new InvalidArgumentException('Base64url value is not canonical.');
        }
        return $decoded;
    }

    private function __construct()
    {
    }
}
