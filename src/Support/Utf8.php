<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Support;

use InvalidArgumentException;

/** Extension-independent UTF-8 primitives shared by public contracts. */
final class Utf8
{
    /** Returns false for malformed UTF-8 or text outside either public bound. */
    public static function isBounded(string $value, int $maximumCodePoints, int $maximumBytes): bool
    {
        if ($maximumCodePoints < 0 || $maximumBytes < 0) {
            throw new InvalidArgumentException('UTF-8 bounds must not be negative.');
        }
        if (strlen($value) > $maximumBytes) {
            return false;
        }
        try {
            return self::codePointLength($value) <= $maximumCodePoints;
        } catch (InvalidArgumentException $exception) {
            return false;
        }
    }

    public static function codePointLength(string $value): int
    {
        $count = preg_match_all('/./us', $value, $matches);
        if (!is_int($count)) {
            throw new InvalidArgumentException('Text must be valid UTF-8.');
        }
        return $count;
    }

    /**
     * Validates exact customer plain text without rewriting it. Horizontal tab,
     * carriage return, and line feed are permitted; transport/control bytes that
     * have no customer-text meaning are rejected.
     */
    public static function isPlainText(string $value): bool
    {
        try {
            self::codePointLength($value);
        } catch (InvalidArgumentException $exception) {
            return false;
        }
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}]/u', $value) !== 1;
    }

    /**
     * Detects a blank customer message without normalizing its accepted bytes.
     * Unicode separator characters and the three permitted ASCII layout
     * controls are whitespace; zero-width format characters are not silently
     * discarded or reclassified.
     */
    public static function isWhitespaceOnly(string $value): bool
    {
        return preg_match('/^[\p{Z}\x09\x0A\x0D]*$/u', $value) === 1;
    }

    /** Detects layout whitespace at either edge without rewriting accepted bytes. */
    public static function hasOuterWhitespace(string $value): bool
    {
        return preg_match('/\A[\p{Z}\x09\x0A\x0D]|[\p{Z}\x09\x0A\x0D]\z/u', $value) === 1;
    }

    /**
     * Returns at most the first $maximumCodePoints Unicode code points.
     *
     * This deliberately uses PCRE's Unicode mode instead of mbstring so the
     * public length contract is identical on every supported WordPress host.
     */
    public static function truncate(string $value, int $maximumCodePoints): string
    {
        if ($maximumCodePoints < 0) {
            throw new InvalidArgumentException('Maximum code-point length must not be negative.');
        }

        $count = preg_match_all('/./us', $value, $matches);
        if (!is_int($count)) {
            throw new InvalidArgumentException('Text must be valid UTF-8.');
        }
        if ($count <= $maximumCodePoints) {
            return $value;
        }

        return implode('', array_slice($matches[0], 0, $maximumCodePoints));
    }
}
