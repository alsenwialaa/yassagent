<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

final class IpNetwork
{
    private function __construct()
    {
    }

    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        $packed = @inet_pton($value);
        if (!is_string($packed)) {
            return '';
        }

        if (
            strlen($packed) === 16
            && substr($packed, 0, 10) === str_repeat("\0", 10)
            && substr($packed, 10, 2) === "\xff\xff"
        ) {
            $packed = substr($packed, 12, 4);
        }

        $normalized = @inet_ntop($packed);
        return is_string($normalized) ? strtolower($normalized) : '';
    }

    public static function canonicalCidr(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $parts = explode('/', $value, 2);
        $ip = self::normalize((string) ($parts[0] ?? ''));
        if ($ip === '') {
            return '';
        }

        $packed = @inet_pton($ip);
        if (!is_string($packed)) {
            return '';
        }
        $maximum = strlen($packed) === 4 ? 32 : 128;
        $prefix = count($parts) === 2 && preg_match('/^[0-9]{1,3}$/', (string) $parts[1]) === 1
            ? (int) $parts[1]
            : $maximum;
        if ($prefix < 0 || $prefix > $maximum) {
            return '';
        }

        $network = $packed;
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        $length = strlen($network);
        for ($index = $wholeBytes; $index < $length; $index++) {
            if ($index === $wholeBytes && $remainingBits > 0) {
                $mask = (0xff << (8 - $remainingBits)) & 0xff;
                $network[$index] = chr(ord($network[$index]) & $mask);
            } else {
                $network[$index] = "\0";
            }
        }

        $canonical = @inet_ntop($network);
        return is_string($canonical) ? strtolower($canonical) . '/' . $prefix : '';
    }

    public static function contains(string $cidr, string $ip): bool
    {
        $canonical = self::canonicalCidr($cidr);
        $normalized = self::normalize($ip);
        if ($canonical === '' || $normalized === '') {
            return false;
        }

        list($networkText, $prefixText) = explode('/', $canonical, 2);
        $network = @inet_pton($networkText);
        $candidate = @inet_pton($normalized);
        if (!is_string($network) || !is_string($candidate) || strlen($network) !== strlen($candidate)) {
            return false;
        }

        $prefix = (int) $prefixText;
        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && !hash_equals(substr($network, 0, $wholeBytes), substr($candidate, 0, $wholeBytes))) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($network[$wholeBytes]) & $mask) === (ord($candidate[$wholeBytes]) & $mask);
    }
}
