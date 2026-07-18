<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Support;

/** One canonical server-side boundary for URLs exposed to the storefront client. */
final class PublicHttpUrl
{
    /** @param mixed $value */
    public static function isSafe($value, bool $allowEmpty = false): bool
    {
        if (!is_string($value)) {
            return false;
        }
        if ($value === '') {
            return $allowEmpty;
        }
        if (strlen($value) > 4096 || trim($value) !== $value) {
            return false;
        }
        // Browsers normalize backslashes and control/space characters differently
        // from PHP's parser. Reject them rather than permitting split authority.
        if (preg_match('/[\\x00-\\x20\\x7f\\\\]/', $value) === 1) {
            return false;
        }

        $parts = parse_url($value);
        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true)
            || trim((string) $parts['host']) === ''
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            return false;
        }
        if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
            return false;
        }
        // WHATWG URL parsing rejects percent escapes in a host (for example
        // example%40evil.test), while parse_url() preserves them. Reject that
        // split-authority surface instead of returning a URL the client drops.
        return preg_match('/[\\x00-\\x20\\x7f%@\\\\\/]/', (string) $parts['host']) !== 1;
    }

    /** @param mixed $value */
    public static function optional($value): string
    {
        return self::isSafe($value, true) ? (string) $value : '';
    }
}
